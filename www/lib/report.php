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
     * @param array  $opts      ['phys' => Phys::decode-структура|null, 'autoprint' => bool,
     *                          'conf' => настройки слоя математики на момент интерпретации]
     */
    public static function html(array $profile, string $interpHtml, array $cfg, array $opts = []): string {
        $brand = $cfg['BRAND_NAME'] ?? 'НейроПро';
        $phone = $cfg['BRAND_PHONE'] ?? '';
        $logo  = self::logoDataUri($cfg['BRAND_LOGO'] ?? '');

        $phys   = $opts['phys'] ?? null;
        // Обратная совместимость: старые вызовы передавали голый aligned-массив.
        if ($phys !== null && array_is_list($phys)) $phys = ['aligned' => $phys, 'p' => [], 'sig' => []];
        // Все числа отчёта — из одного детерминированного расчёта, и он должен
        // быть ТЕМ ЖЕ, по которому писался текст: пороги матрицы оператор двигает
        // мышью, поэтому вместе с интерпретацией сохраняется их снимок (#14).
        $conf = (array) ($opts['conf'] ?? []);
        $metrics = $conf
            ? Metrics::withConf($conf, static fn () => Metrics::build($profile, $phys))
            : Metrics::build($profile, $phys);
        // Диаграмма и матрица должны уместиться на ОДИН лист PDF, поэтому обе
        // уменьшены примерно на треть от прежних 600/620 px, а затем — ещё на
        // 10% (360/387 px): у СМУ с 12 шкалами картинки по-прежнему съезжали
        // на второй лист. Пояснения под матрицей сжаты (compact).
        $svg = Chart::fromMetrics($metrics, [
            'title' => Profile::chartTitle((string) ($profile['test_key'] ?? '')),
            'size'  => 360,
        ]);
        // Матрица под диаграммой — она заменила таблицы отчёта.
        $matrix = Matrix::svg($metrics, ['width' => 387]);

        $h = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $title = $h(($profile['name'] ?: 'Профиль') . ' — ' . ($profile['methodic'] ?: ''));
        $autoprint = !empty($opts['autoprint']);

        // Шапка отчёта: печатаем ТОЛЬКО заполненные поля. Пустые ФИО/Возраст/Пол
        // раньше уходили в PDF как «ФИО: , Возраст: , Пол: ;» — клиент видел
        // пустые ярлыки и лишние «;» в конце строк (#1). Точек с запятой в конце
        // строк больше нет — их тоже просили убрать.
        $ident = [];
        if (trim((string) ($profile['name'] ?? '')) !== '') $ident[] = 'ФИО: ' . $h($profile['name']);
        if (trim((string) ($profile['age'] ?? '')) !== '')  $ident[] = 'Возраст: ' . $h($profile['age']);
        if (trim((string) ($profile['sex'] ?? '')) !== '')  $ident[] = 'Пол: ' . $h($profile['sex']);
        $metaLines = [];
        if ($ident) $metaLines[] = implode(', ', $ident);
        if (trim((string) ($profile['methodic'] ?? '')) !== '') $metaLines[] = 'Методика: ' . $h($profile['methodic']);
        if (trim((string) ($profile['date'] ?? '')) !== '')     $metaLines[] = 'Дата исследования: ' . $h($profile['date']);

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
  .chart { text-align: center; margin: 2px 0; }
  .chart svg { max-width: 100%; height: auto; }
  /* Диаграмма + матрица + расшифровка номеров — один неразрывный блок: обе
     картинки печатаются на одном листе, разрыв между ними запрещён. */
  .figures { page-break-inside: avoid; }
  .legend { color: #6b7682; font-size: 10px; margin: -2px 0 8px; text-align: center; }
  .totals { margin: 8px 0 18px; }
  /* Текст интерпретации всегда начинается с НОВОГО листа: картинки и итоги —
     первая страница, разбор — со второй. Раньше заголовок «… — интерпретация
     теста …» дописывался под матрицей и разрывался между листами как придётся. */
  .interp { margin-top: 8px; page-break-before: always; break-before: page; }
  /* h1/h2 — заголовок отчёта и разделы («## Сильные мотиваторы»): крупнее и с
     чертой снизу, чтобы раздел был виден с первого взгляда, даже бегло листая
     страницы. h3 — подзаголовок шкалы («### Название шкалы») внутри раздела:
     заметно мельче h2 и без черты — раньше оба уровня рисовались одним и тем
     же стилем 14px и визуально не различались. */
  .interp h1, .interp h2 { color: #b3203b; font-size: 16px; margin: 18px 0 8px; border-bottom: 1px solid #e6c3cb; padding-bottom: 3px; }
  .interp h3 { color: #b3203b; font-size: 13px; margin: 12px 0 4px; }
  .interp h4, .interp h5, .interp h6 { color: #b3203b; font-size: 12px; margin: 10px 0 3px; }
  .interp h1:first-child, .interp h2:first-child, .interp h3:first-child { margin-top: 0; }
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

  <?php if ($metaLines): ?>
  <div class="meta">
    <?= implode('<br>', $metaLines) ?>
  </div>
  <?php endif; ?>

  <div class="figures">
    <div class="chart"><?= $svg ?></div>
    <?php if ($matrix !== ''): ?>
      <div class="chart matrix"><?= $matrix ?></div>
      <?= Matrix::legendHtml($metrics) ?>
    <?php endif; ?>
  </div>

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
     * ─── Правка интерпретации ───────────────────────────────────────────────
     *
     * Отчёт правится ДО выгрузки в PDF: двойной клик по тексту на странице
     * результата открывает ОДИН общий Markdown-редактор на весь текст (курсор —
     * там, где кликнули), клик за его пределами сохраняет правку автоматически.
     * Правки построчно по отдельным абзацам сервис больше не поддерживает —
     * переставить разделы или переписать отчёт целиком было нельзя (#6).
     */

    /** Канонический вид текста для правки: без таблиц модели, без лишних пустых строк. */
    public static function normalizeForEdit(string $md): string {
        $text = str_replace("\r\n", "\n", self::stripTables($md));
        return trim((string) preg_replace('/\n{3,}/', "\n\n", $text));
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

            // Заголовки до шестого уровня: модель часто выделяет шкалу как
            // «#### Состязательный мотив», и при потолке в три решётки такая
            // строка печаталась в отчёте как обычный абзац с решётками.
            if (preg_match('/^(#{1,6})\s+(.*)$/', $t, $m)) {
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
