<?php
/**
 * Branded client report renderer (Verdana). Produces a self-contained HTML
 * document with the logo header, the mathematical radar chart (SVG, cognitive +
 * physiological overlay), the matrix «когниция × эмоция» и the interpretation text.
 *
 * ТАБЛИЦ В ОТЧЁТЕ КЛИЕНТА НЕТ. Раньше под диаграммой печаталась таблица со
 * баллами, «Знач.» и достоверностью — клиенту она читалась как медицинский
 * протокол и требовала расшифровки. Те же соотношения показывает матрица
 * (www/lib/matrix.php): по осям — когнитивный и эмоциональный ответ в единицах
 * самого теста, цвет кружка — доминирующий параметр, размер — суммарная
 * выраженность. Числа никуда не делись: их считает Metrics, а оператор видит их
 * в полной таблице на странице результата.
 *
 * The same HTML is used for: on-screen preview, "download as PDF" (print CSS,
 * Verdana, A4), and the email body. No external assets — the logo is inlined as
 * a data URI so the file is portable.
 */

require_once __DIR__ . '/chart.php';
require_once __DIR__ . '/matrix.php';
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
        // Матрица под диаграммой — она заменила таблицы отчёта.
        $matrix = Matrix::svg($metrics, ['width' => 620]);

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
  .matrix { page-break-inside: avoid; }
  .legend { color: #6b7682; font-size: 10.5px; margin: -4px 0 10px; }
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

  <?php if ($matrix !== ''): ?>
    <div class="chart matrix"><?= $matrix ?></div>
    <?= Matrix::legendHtml($metrics) ?>
  <?php endif; ?>

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
     * Итоги методики — из общего списка Metrics::totals(): суммы мотивации СМУ,
     * напряжённость защит ИЖС, индексы Басса-Дарки. Раньше отчёт считал суммы
     * СМУ сам, в шаблоне, и нейросеть их не видела; теперь и отчёт, и промпт
     * печатают один и тот же расчёт.
     */
    private static function totals(array $metrics, array $profile, callable $h): string {
        $totals = $metrics['totals'] ?? [];
        if (!$totals) return '';
        ob_start(); ?>
<div class="totals">
  Заключение:<br>
  <?php foreach ($totals as $t): ?>
    <?= $h($t['text']) ?>.<br>
  <?php endforeach; ?>
</div>
<?php
        return ob_get_clean();
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
     * таблицы: в отчёте клиента таблиц нет вообще — соотношения показывает
     * матрица. Таблица от нейросети — это те же числа, пересказанные второй раз,
     * и она расходилась с расчётом (у Обиды достоверность «—» против «p<0.05»).
     * Применяется и к старым интерпретациям, сделанным до промптов v3.
     */
    public static function interpToHtml(string $md): string {
        return self::mdToHtml(self::stripTables($md));
    }

    /**
     * ─── Правка интерпретации по абзацам ───────────────────────────────────
     *
     * Отчёт правится ДО выгрузки в PDF: оператор двойным кликом открывает абзац
     * и переписывает его. Единица правки — блок Markdown (абзац, заголовок или
     * список), а не HTML: в базе лежит текст нейросети, и правка должна остаться
     * тем же текстом, иначе следующий PDF собрать уже нечем.
     *
     * Нумерация блоков одинакова при показе и при сохранении, потому что оба
     * пути начинаются с normalizeForEdit(): без этого удалённая таблица от
     * модели сдвигала индексы, и правка уходила не в тот абзац.
     */

    /** Канонический вид текста для правки: без таблиц модели, без лишних пустых строк. */
    public static function normalizeForEdit(string $md): string {
        $text = str_replace("\r\n", "\n", self::stripTables($md));
        return trim((string) preg_replace('/\n{3,}/', "\n\n", $text));
    }

    /**
     * Блоки Markdown — по одному на правку. Кроме пустых строк режем и по
     * заголовкам: модель часто пишет заголовок и абзац без пустой строки между
     * ними, а править заголовок вместе с текстом неудобно. Список остаётся одним
     * блоком: разбитый по пунктам, он при сборке превратился бы в несколько
     * списков подряд.
     */
    public static function editBlocks(string $md): array {
        $norm = self::normalizeForEdit($md);
        if ($norm === '') return [];
        $out = [];
        foreach (preg_split('/\n[ \t]*\n/', $norm) ?: [] as $chunk) {
            $rest = [];
            foreach (preg_split('/\n/', trim($chunk)) as $line) {
                if (preg_match('/^#{1,3}\s+\S/', $line)) {
                    if ($rest) { $out[] = implode("\n", $rest); $rest = []; }
                    $out[] = trim($line);
                    continue;
                }
                $rest[] = $line;
            }
            if ($rest) $out[] = implode("\n", $rest);
        }
        return array_values(array_filter(array_map('trim', $out), static fn ($b) => $b !== ''));
    }

    /**
     * Заменяет один блок. Пустой текст = удалить абзац (оператор так убирает
     * лишнее). Возвращает новый полный текст интерпретации.
     */
    public static function replaceBlock(string $md, int $index, string $newText): string {
        $blocks = self::editBlocks($md);
        if (!array_key_exists($index, $blocks)) {
            throw new RuntimeException('Абзац не найден: текст изменился, обновите страницу.');
        }
        $new = trim(str_replace("\r\n", "\n", $newText));
        if ($new === '') unset($blocks[$index]);
        else $blocks[$index] = $new;
        return implode("\n\n", array_values($blocks));
    }

    /**
     * Интерпретация для страницы результата: каждый блок обёрнут в контейнер с
     * номером и исходным Markdown, чтобы правка открывалась ровно тем текстом,
     * который лежит в базе.
     */
    public static function interpToEditableHtml(string $md): string {
        $h = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $out = [];
        foreach (self::editBlocks($md) as $i => $block) {
            $out[] = '<div class="np-block" data-block="' . $i . '" data-src="' . $h($block)
                   . '" title="Двойной клик — редактировать">' . self::mdToHtml($block) . '</div>';
        }
        if (!$out) $out[] = '<div class="np-block" data-block="0" data-src="">'
                          . '<p class="muted">Текст пуст.</p></div>';
        return implode("\n", $out);
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
