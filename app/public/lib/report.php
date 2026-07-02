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

final class Report {
    /**
     * @param array  $profile   profile array (see Profile::fromSheets)
     * @param string $interpHtml interpretation rendered to HTML
     * @param array  $cfg       config (branding)
     * @param array  $opts      ['phys' => alignedArray|null, 'autoprint' => bool]
     */
    public static function html(array $profile, string $interpHtml, array $cfg, array $opts = []): string {
        $brand = $cfg['BRAND_NAME'] ?? 'НейроПро';
        $phone = $cfg['BRAND_PHONE'] ?? '';
        $logo  = self::logoDataUri($cfg['BRAND_LOGO'] ?? '');

        $labels = array_map(static fn ($s) => Profile::shortLabel($s['label']), $profile['scores']);
        $cog    = array_map(static fn ($s) => (float) $s['score'], $profile['scores']);
        $phys   = $opts['phys'] ?? null;
        $svg    = Chart::svg($labels, $cog, (int) ($profile['score_max'] ?? 10), $phys, [
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
  table.scores { width: 100%; border-collapse: collapse; margin: 10px 0; font-size: 11.5px; }
  table.scores th, table.scores td { border: 1px solid #c9d2da; padding: 4px 8px; text-align: left; }
  table.scores th { background: #f4f6f8; }
  table.scores td.num { text-align: center; width: 56px; }
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

  <?= self::scoresTable($profile, $h) ?>

  <div class="interp"><?= $interpHtml ?></div>

  <div class="foot">
    <?php if ($phone): ?>Если у вас остались вопросы, вы можете связаться с нами по тел. <a href="tel:<?= $h($phone) ?>"><?= $h($phone) ?></a>.<?php endif; ?>
  </div>
  <?php if ($autoprint): ?><script>window.addEventListener('load',()=>setTimeout(()=>window.print(),300));</script><?php endif; ?>
</body></html>
<?php
        return ob_get_clean();
    }

    private static function scoresTable(array $profile, callable $h): string {
        $scores = $profile['scores'];
        if (!$scores) return '';
        $testKey = (string) ($profile['test_key'] ?? '');
        $isSmu = $testKey === 'smu' && count($scores) === 12;
        $unit = $testKey === 'lsi' ? '%' : 'Баллы';

        ob_start(); ?>
<table class="scores">
  <tr><th>#</th><th>Параметр</th><th><?= $h($unit) ?></th><?php if ($isSmu): ?><th></th><?php endif; ?></tr>
  <?php foreach ($scores as $i => $s):
      $rowspanCell = '';
      if ($isSmu && $i === 0) $rowspanCell = '<td class="group" rowspan="6">Мотивация достижения</td>';
      if ($isSmu && $i === 6) $rowspanCell = '<td class="group" rowspan="6">Мотивация отношения</td>';
  ?>
  <tr>
    <td class="num"><?= $h($s['n']) ?></td>
    <td><?= $h($s['label']) ?></td>
    <td class="num"><?= $h(rtrim(rtrim(number_format((float) $s['score'], 1, '.', ''), '0'), '.')) ?></td>
    <?= $rowspanCell ?>
  </tr>
  <?php endforeach; ?>
</table>
<?php if ($isSmu):
    $ach = array_sum(array_map(fn ($s) => (float) $s['score'], array_slice($scores, 0, 6)));
    $rel = array_sum(array_map(fn ($s) => (float) $s['score'], array_slice($scores, 6, 6))); ?>
<div class="totals">
  Заключение:<br>
  Мотивация достижения: <?= $h((string) round($ach)) ?>.<br>
  Мотивация отношения: <?= $h((string) round($rel)) ?>.
</div>
<?php endif; ?>
<?php if ($testKey === 'bd' && ($indices = Profile::bdIndices($scores))): ?>
<div class="totals">
  Заключение:<br>
  <?php foreach ($indices as $name => $ix): ?>
  <?= $h($name) ?> = <?= $h(self::num($ix['value'])) ?> из <?= (int) $ix['cap'] ?> (норма <?= $h(self::num($ix['min'])) ?>–<?= $h(self::num($ix['max'])) ?>) — <?= $h($ix['verdict']) ?>.<br>
  <?php endforeach; ?>
</div>
<?php endif;
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
