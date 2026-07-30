<?php
/**
 * Branded client report renderer (Verdana). Produces a self-contained HTML
 * document with the logo header, the mathematical radar chart (SVG, cognitive +
 * physiological overlay), the scores table, and the interpretation text.
 *
 * The same HTML is used for: on-screen preview, "download as PDF" (print CSS,
 * Verdana, A4), and the email body. No external assets — the logo is inlined as
 * a data URI so the file is portable.
 */

require_once __DIR__ . '/chart.php';
require_once __DIR__ . '/profile.php';
require_once __DIR__ . '/metrics.php';

final class Report {
    /**
     * @param array  $profile   profile array (see Profile::fromSheets)
     * @param string $interpHtml interpretation rendered to HTML
     * @param array  $cfg       config (branding)
     * @param array  $opts      ['phys' => Phys::decode-структура|null, 'autoprint' => bool]
     */
    public static function html(array $profile, string $interpHtml, array $cfg, array $opts = []): string {
        $brand = $cfg['BRAND_NAME'] ?? 'НейроПро';
        $phone = $cfg['BRAND_PHONE'] ?? '';
        $logo  = self::logoDataUri($cfg['BRAND_LOGO'] ?? '');

        $phys   = $opts['phys'] ?? null;
        // Обратная совместимость: старые вызовы передавали голый aligned-массив.
        if ($phys !== null && array_is_list($phys)) $phys = ['aligned' => $phys, 'p' => [], 'sig' => []];
        // Все числа отчёта — из одного детерминированного расчёта.
        $metrics = Metrics::build($profile, $phys);
        $svg = Chart::fromMetrics($metrics, [
            'title' => Profile::chartTitle((string) ($profile['test_key'] ?? '')),
            'size'  => 600,
        ]);

        $h = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $title = $h(($profile['name'] ?: 'Профиль') . ' — ' . ($profile['methodic'] ?: ''));
        $autoprint = !empty($opts['autoprint']);

        ob_start(); ?>
<!doctype html>
<html lang="ru"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $title ?></title>
<style>
  @page { size: A4; margin: 16mm 14mm; }
  * { box-sizing: border-box; }
  body { font-family: Verdana, Geneva, sans-serif; color: #2a3138; font-size: 12px; line-height: 1.5; margin: 0; padding: 24px; max-width: 900px; margin: 0 auto; background: #fff; }
  .head { display: flex; align-items: center; justify-content: space-between; border-bottom: 2px solid #b3203b; padding-bottom: 10px; }
  .head img { height: 56px; }
  .brand { text-align: right; font-weight: bold; color: #2a3138; font-size: 13px; }
  .meta { margin: 14px 0 4px; }
  .chart { text-align: center; margin: 8px 0; }
  .chart svg { max-width: 100%; height: auto; }
  table.scores { width: 100%; border-collapse: collapse; margin: 10px 0; font-size: 11px; table-layout: auto; }
  table.scores th, table.scores td { border: 1px solid #c9d2da; padding: 4px 6px; text-align: left; }
  table.scores th { background: #f4f6f8; }
  table.scores td.num { text-align: center; white-space: nowrap; }
  table.scores .dim { color: #8a949d; font-size: 10.5px; }
  .legend { color: #6b7682; font-size: 10.5px; margin: -4px 0 10px; }
  .group { writing-mode: vertical-rl; transform: rotate(180deg); text-align: center; font-weight: bold; color: #6b7682; }
  .totals { margin: 8px 0 18px; }
  .interp { margin-top: 8px; }
  .interp h1, .interp h2, .interp h3 { color: #b3203b; font-size: 14px; margin: 16px 0 6px; }
  .interp p { margin: 6px 0; }
  .interp table { border-collapse: collapse; font-size: 11px; margin: 8px 0; }
  .interp table td, .interp table th { border: 1px solid #c9d2da; padding: 3px 6px; }
  .foot { margin-top: 22px; border-top: 1px solid #c9d2da; padding-top: 8px; font-size: 11px; color: #6b7682; }
  .foot a { color: #b3203b; }
  @media print { body { padding: 0; } .noprint { display: none; } }
</style>
</head><body>
  <div class="head">
    <?php if ($logo): ?><img src="<?= $logo ?>" alt="logo"><?php else: ?><div></div><?php endif; ?>
    <div class="brand"><?= nl2br($h($brand)) ?></div>
  </div>

  <div class="meta">
    ФИО: <?= $h($profile['name']) ?>, Возраст: <?= $h($profile['age']) ?>, Пол: <?= $h($profile['sex']) ?>;<br>
    Методика: <?= $h($profile['methodic']) ?>;<br>
    Дата исследования: <?= $h($profile['date']) ?>.
  </div>

  <div class="chart"><?= $svg ?></div>

  <?= self::dataTable($metrics, $profile, $h) ?>

  <?= self::totals($metrics, $profile, $h) ?>

  <div class="interp"><?= $interpHtml ?></div>

  <div class="foot">
    <?php if ($phone): ?>Если у вас остались вопросы, вы можете связаться с нами по тел. <a href="tel:<?= $h($phone) ?>"><?= $h($phone) ?></a>.<?php endif; ?>
  </div>
  <?php if ($autoprint): ?><script>window.addEventListener('load',()=>setTimeout(()=>window.print(),300));</script><?php endif; ?>
</body></html>
<?php
        return ob_get_clean();
    }

    /**
     * ЕДИНСТВЕННАЯ таблица данных отчёта: балл, уровень, положение в профиле,
     * «Знач.», достоверность и вывод — всё из Metrics.
     *
     * Раньше таблиц было две (баллы + физиология), а нейросеть печатала в тексте
     * третью, свою — и они противоречили друг другу: у одной и той же шкалы
     * достоверность была «—» в таблице сервиса и «p<0.05» в таблице модели.
     * Теперь таблица одна, и её строит только код.
     */
    private static function dataTable(array $metrics, array $profile, callable $h): string {
        $axes = $metrics['axes'];
        if (!$axes) return '';
        $testKey = (string) ($profile['test_key'] ?? '');
        $isSmu = $testKey === 'smu' && count($axes) === 12;
        $hasPhys = !empty($metrics['has_phys']);

        ob_start(); ?>
<table class="scores">
  <tr>
    <th>Параметр</th><th>Балл</th><th>Уровень</th>
    <?php if ($hasPhys): ?><th>Знач.</th><th>Физиология</th><th>Вывод</th><?php endif; ?>
    <?php if ($isSmu): ?><th></th><?php endif; ?>
  </tr>
  <?php foreach ($axes as $i => $a):
      $rowspanCell = '';
      if ($isSmu && $i === 0) $rowspanCell = '<td class="group" rowspan="6">Мотивация достижения</td>';
      if ($isSmu && $i === 6) $rowspanCell = '<td class="group" rowspan="6">Мотивация отношения</td>';
      $style = $a['sig'] ? ' style="font-weight:bold"' : '';
  ?>
  <tr>
    <td<?= $style ?>><?= $h($a['label']) ?></td>
    <td class="num"><?= $h(self::num($a['score'])) ?> из <?= (int) $a['scale_max'] ?>
      <span class="dim"><?= $h(self::num($a['pct'])) ?>%</span></td>
    <td><?= $h($a['level_label']) ?><br><span class="dim"><?= $h($a['rank_label']) ?></span></td>
    <?php if ($hasPhys): ?>
      <td class="num"<?= $style ?>><?= $a['zna'] === null ? '—' : $h(self::num((float) $a['zna'])) ?>
        <br><span class="dim"><?= $h($a['p_label']) ?></span></td>
      <td><?= $h($a['phys_dir']) ?></td>
      <td><?= $h($a['category_short']) ?></td>
    <?php endif; ?>
    <?= $rowspanCell ?>
  </tr>
  <?php endforeach; ?>
</table>
<?php if ($hasPhys): ?>
<p class="legend">«Знач.» — столбец смысло-эмоциональной значимости Эгоскопа: 0 — медиана,
  больше нуля — тело откликается сильнее обычного, меньше нуля — телесного отклика нет.
  Жирным выделены достоверные отклонения (p&lt;0.05).</p>
<?php endif;
        return ob_get_clean();
    }

    /** Итоги методики: суммы СМУ и индексы Басса-Дарки (детерминированные). */
    private static function totals(array $metrics, array $profile, callable $h): string {
        $scores = $profile['scores'] ?? [];
        $testKey = (string) ($profile['test_key'] ?? '');
        $isSmu = $testKey === 'smu' && count($scores) === 12;
        $indices = $metrics['indices'] ?? [];
        if (!$isSmu && !$indices) return '';
        ob_start(); ?>
<div class="totals">
  Заключение:<br>
  <?php if ($isSmu):
      $ach = array_sum(array_map(fn ($s) => (float) $s['score'], array_slice($scores, 0, 6)));
      $rel = array_sum(array_map(fn ($s) => (float) $s['score'], array_slice($scores, 6, 6))); ?>
    Мотивация достижения: <?= $h((string) round($ach)) ?>.<br>
    Мотивация отношения: <?= $h((string) round($rel)) ?>.<br>
  <?php endif; ?>
  <?php foreach ($indices as $name => $ix): ?>
    <?= $h($name) ?> = <?= $h(self::num($ix['value'])) ?> из <?= (int) $ix['cap'] ?> (норма <?= $h(self::num($ix['min'])) ?>–<?= $h(self::num($ix['max'])) ?>) — <?= $h($ix['verdict']) ?>.<br>
  <?php endforeach; ?>
</div>
<?php
        return ob_get_clean();
    }

    /** Compact number: 9.0 → "9", 3.5 → "3.5". */
    private static function num(float $v): string {
        return rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
    }

    private static function logoDataUri(string $path): string {
        if (!$path || !is_file($path)) return '';
        $data = @file_get_contents($path);
        if ($data === false) return '';
        $mime = str_ends_with(strtolower($path), '.png') ? 'image/png' : 'image/jpeg';
        return 'data:' . $mime . ';base64,' . base64_encode($data);
    }

    /**
     * Текст интерпретации → HTML для отчёта. В отличие от mdToHtml вырезает
     * таблицы: единственная таблица данных в отчёте — каноническая, из Metrics.
     * Таблица от нейросети — это те же числа, пересказанные второй раз, и она
     * расходилась с расчётом (у Обиды достоверность «—» против «p<0.05»).
     * Применяется и к старым интерпретациям, сделанным до промптов v3.
     */
    public static function interpToHtml(string $md): string {
        return self::mdToHtml(self::stripTables($md));
    }

    /** Убирает Markdown-таблицы (и их заголовок, если он остался пустым). */
    public static function stripTables(string $md): string {
        $lines = preg_split('/\r?\n/', $md);
        $out = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*\|.*\|\s*$/', rtrim($line))) continue;
            $out[] = $line;
        }
        $text = implode("\n", $out);
        // Заголовок вида «## Данные теста и физиологии», под которым остался
        // только пробел, — вырезаем вместе с ним: таблицу заменяет каноническая.
        $text = (string) preg_replace('/^#{1,3}\s*[^\n]*(?:табли|Данные теста|Данные физиолог)[^\n]*\n(?=\s*(?:#|$))/miu', '', $text);
        return (string) preg_replace('/\n{3,}/', "\n\n", $text);
    }

    /** Minimal Markdown → HTML for interpretation text (headings, bold, tables, lists). */
    public static function mdToHtml(string $md): string {
        $h = static fn ($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $lines = preg_split('/\r?\n/', $md);
        $out = [];
        $inList = false;
        $inTable = false;
        foreach ($lines as $line) {
            $t = rtrim($line);
            // Table rows (| a | b |).
            if (preg_match('/^\s*\|.*\|\s*$/', $t)) {
                if (preg_match('/^\s*\|[\s:|-]+\|\s*$/', $t)) continue; // separator row
                if (!$inTable) { $out[] = '<table>'; $inTable = true; }
                $cells = array_map('trim', explode('|', trim($t, " |")));
                $out[] = '<tr>' . implode('', array_map(fn ($c) => '<td>' . self::inline($c, $h) . '</td>', $cells)) . '</tr>';
                continue;
            } elseif ($inTable) { $out[] = '</table>'; $inTable = false; }

            if (preg_match('/^(#{1,3})\s+(.*)$/', $t, $m)) {
                if ($inList) { $out[] = '</ul>'; $inList = false; }
                $lvl = strlen($m[1]);
                $out[] = "<h$lvl>" . self::inline($m[2], $h) . "</h$lvl>";
            } elseif (preg_match('/^\s*[-*•]\s+(.*)$/u', $t, $m)) {
                if (!$inList) { $out[] = '<ul>'; $inList = true; }
                $out[] = '<li>' . self::inline($m[1], $h) . '</li>';
            } elseif (trim($t) === '') {
                if ($inList) { $out[] = '</ul>'; $inList = false; }
                $out[] = '';
            } else {
                if ($inList) { $out[] = '</ul>'; $inList = false; }
                $out[] = '<p>' . self::inline($t, $h) . '</p>';
            }
        }
        if ($inList) $out[] = '</ul>';
        if ($inTable) $out[] = '</table>';
        return implode("\n", $out);
    }

    private static function inline(string $s, callable $h): string {
        $s = $h($s);
        $s = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $s);
        $s = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/u', '<em>$1</em>', $s);
        return $s;
    }
}
