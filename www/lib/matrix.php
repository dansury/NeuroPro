<?php
/**
 * Матрица показателей «когниция × эмоция» — математика, не нейросеть.
 *
 * Диаграмма-паутинка показывает профиль по осям-шкалам, но не показывает
 * ГЛАВНОГО соотношения: насколько ответы теста совпадают с телесной реакцией.
 * Матрица кладёт каждый показатель в плоскость:
 *   - ось X — когнитивный ответ (ответы теста) в единицах самого теста:
 *     у СМУ это баллы 0…10, у ИЖС — проценты, у Басса-Дарки шкалы имеют разные
 *     максимумы (5…13), поэтому единственная сравнимая подпись — процент от
 *     максимума своей шкалы (см. Metrics::unit());
 *   - ось Y — эмоциональный ответ: «Знач.» из таблицы смысло-эмоциональной
 *     значимости Эгоскопа на симметричной шкале ±phys_scale, медиана — по центру.
 *
 * Кружок = показатель. Цвет — по доминирующему параметру СМК: Y (эмоциональный
 * отклик) — красный, X (психическое напряжение) — синий, Z (мышечная реакция) —
 * зелёный. Показатели с низкой выраженностью И без телесного отклика — бледные
 * серые кружки: они в отчёте не разбираются. Размер кружка — вес показателя,
 * то есть выраженность СУММЫ когнитивного и эмоционального ответа
 * (Metrics::weight()). Бледный пунктир — границы, по которым код отсекает
 * средние показатели от высоких (40 % и 60 % шкалы) и телесный отклик от его
 * отсутствия (полоса медианы ±mid_band).
 *
 * Всё это считается ДО нейросети: Matrix только рисует то, что посчитал
 * Metrics::build() (Конституция, принцип I).
 *
 * Вывод — самостоятельный SVG (Verdana), пригодный и для страницы, и для PDF.
 */

declare(strict_types=1);

require_once __DIR__ . '/metrics.php';

final class Matrix {
    /** Цвета параметров СМК (секторы кружка). Y — оранжевый (по просьбе заказчика). */
    private const C_Y = '#e8850c';   // эмоциональный отклик — оранжевый
    private const C_X = '#2f6fd0';   // психическое напряжение — синий
    private const C_Z = '#2e9e5b';   // мышечная реакция — зелёный
    private const C_NONE = '#7d8a97'; // СМК не распознан — нейтральный серо-синий
    private const C_FLAT = '#c2ccd4'; // низкая выраженность без отклика — бледный серый

    private const GRID = '#eaeef2';
    private const AXIS = '#c9d2da';
    private const DASH = '#cfd8e0';   // пунктир порогов
    private const MEDIAN = '#9fb4bd';
    private const TEXT = '#2a3138';
    private const DIM = '#8a949d';

    /** Радиус кружка: от «еле заметно» до «максимальная сумма ответов». */
    private const R_MIN = 5.0;
    private const R_MAX = 17.0;

    /** Легенда: параметры СМК как секторы кружка + служебные случаи. */
    private const LEGEND = [
        [self::C_Y, 'Y — эмоциональный отклик'],
        [self::C_X, 'X — психическое напряжение'],
        [self::C_Z, 'Z — мышечная реакция'],
        [self::C_NONE, 'параметр не распознан'],
        [self::C_FLAT, 'фон: не разбирается в отчёте'],
    ];

    /**
     * @param array $metrics результат Metrics::build()
     * @param array $opts    ['width' => int, 'title' => string]
     */
    public static function svg(array $metrics, array $opts = []): string {
        $axes = $metrics['axes'] ?? [];
        if (!$axes) return '';

        $W = (int) ($opts['width'] ?? 640);
        $title = (string) ($opts['title'] ?? 'Матрица показателей: ответы теста × телесный отклик');
        // Интерпретация по шкалам (label => фрагмент текста): подставляет
        // interactiveHtml(), чтобы кружок при наведении показывал и математику,
        // и интерпретацию (#9). В статичном SVG (PDF) карта пуста.
        $interp = (array) ($opts['interp'] ?? []);
        $physScale = (float) ($metrics['phys_scale'] ?? 0.0);
        $midBand = (float) ($metrics['mid_band'] ?? Metrics::MID_BAND_MIN);
        $hasPhys = !empty($metrics['has_phys']) && $physScale > 0;
        $unit = $metrics['unit'] ?? ['max' => 100.0, 'suffix' => '%', 'title' => '% от максимума шкалы'];

        // Пояснения под матрицей переносим по ширине заранее: от числа строк
        // зависит высота картинки (иначе текст уезжал за край SVG).
        $notes = ['Размер кружка — выраженность суммы когнитивного и эмоционального ответа.'];
        $notes[] = 'Кружок разделён на секторы X (синий), Y (оранжевый), Z (зелёный): больший сектор — преобладающий параметр СМК; серый — фоновые показатели, в отчёте не разбираются.';
        $notes[] = 'Бледный пунктир — границы, по которым показатели делятся на низкие / средние / высокие ('
                 . Metrics::num(Metrics::LOW_PCT) . ' % и ' . Metrics::num(Metrics::HIGH_PCT) . ' % от максимума шкалы)'
                 . ($hasPhys ? ' и полоса медианы (±' . Metrics::num($midBand) . ').' : '.');
        $notes[] = $hasPhys
            ? 'Жирная обводка — достоверное отклонение (p<0.05); пунктирная — физиология по этой шкале не распознана.'
            : 'Физиология не распознана: показатели отложены только по когнитивной оси.';

        $padL = 62; $padR = 26; $padT = 46;
        $plotW = $W - $padL - $padR;
        // Без физиологии вертикальной оси нет — незачем оставлять пустое поле.
        $plotH = (int) round($plotW * ($hasPhys ? 0.62 : 0.28));
        $noteLines = [];
        foreach ($notes as $note) {
            foreach (self::wrap($note, (int) floor($plotW / 4.9)) as $ln) $noteLines[] = $ln;
        }
        $legendRows = (int) ceil(count(self::LEGEND) / max(1, (int) floor($plotW / 190)));
        $padB = 34 + $legendRows * 15 + count($noteLines) * 12 + 8;
        $H = $padT + $plotH + $padB;

        // Когниция: 0…100 % слева направо. Физиология: +phys_scale сверху,
        // −phys_scale снизу, медиана — точно по центру области.
        $x = static fn (float $pct) => $padL + $plotW * max(0.0, min(100.0, $pct)) / 100.0;
        $y = static fn (float $pct) => $padT + $plotH * (1.0 - max(0.0, min(100.0, $pct)) / 100.0);
        $yZna = static fn (float $zna) => $y(50.0 + 50.0 * $zna / ($physScale ?: 1.0));

        $s = [];
        $s[] = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $W . '" height="' . $H . '" viewBox="0 0 ' . $W . ' ' . $H
             . '" font-family="Verdana, Geneva, sans-serif">';
        $s[] = '<rect width="100%" height="100%" fill="#ffffff"/>';
        $s[] = '<text x="' . round($W / 2) . '" y="24" text-anchor="middle" font-size="14" font-weight="bold" fill="' . self::TEXT . '">'
             . self::esc($title) . '</text>';
        $s[] = '<rect x="' . $padL . '" y="' . $padT . '" width="' . $plotW . '" height="' . $plotH . '" fill="#fbfcfd" stroke="' . self::AXIS . '"/>';

        // Сетка и подписи оси X — в единицах самого теста.
        $unitMax = (float) ($unit['max'] ?? 100.0);
        $suffix = (string) ($unit['suffix'] ?? '%');
        for ($p = 0; $p <= 100; $p += 20) {
            $px = round($x((float) $p), 1);
            if ($p > 0 && $p < 100) {
                $s[] = '<line x1="' . $px . '" y1="' . $padT . '" x2="' . $px . '" y2="' . ($padT + $plotH) . '" stroke="' . self::GRID . '"/>';
            }
            $label = Metrics::num($unitMax * $p / 100.0) . ($suffix === '%' ? ' %' : '');
            $s[] = '<text x="' . $px . '" y="' . ($padT + $plotH + 14) . '" text-anchor="middle" font-size="9" fill="' . self::DIM . '">'
                 . self::esc($label) . '</text>';
        }
        $s[] = '<text x="' . round($padL + $plotW / 2) . '" y="' . ($padT + $plotH + 30) . '" text-anchor="middle" font-size="10.5" fill="' . self::TEXT . '">'
             . self::esc('Когнитивный показатель — ответы теста (' . $unit['title'] . ')') . '</text>';

        // Сетка и подписи оси Y — в единицах «Знач.» Эгоскопа.
        if ($hasPhys) {
            foreach ([1.0, 0.5, 0.0, -0.5, -1.0] as $k) {
                $zna = $physScale * $k;
                $py = round($yZna($zna), 1);
                if ($k !== 1.0 && $k !== -1.0 && $k !== 0.0) {
                    $s[] = '<line x1="' . $padL . '" y1="' . $py . '" x2="' . ($padL + $plotW) . '" y2="' . $py . '" stroke="' . self::GRID . '"/>';
                }
                $s[] = '<text x="' . ($padL - 6) . '" y="' . ($py + 3.2) . '" text-anchor="end" font-size="9" fill="' . self::DIM . '">'
                     . self::esc(Metrics::num($zna)) . '</text>';
            }
            $s[] = '<text x="16" y="' . round($padT + $plotH / 2) . '" font-size="10.5" fill="' . self::TEXT
                 . '" transform="rotate(-90 16 ' . round($padT + $plotH / 2) . ')" text-anchor="middle">'
                 . self::esc('Эмоциональный показатель — «Знач.»') . '</text>';
        } else {
            $s[] = '<text x="16" y="' . round($padT + $plotH / 2) . '" font-size="10.5" fill="' . self::DIM
                 . '" transform="rotate(-90 16 ' . round($padT + $plotH / 2) . ')" text-anchor="middle">'
                 . self::esc('Эмоциональный показатель — нет данных') . '</text>';
        }

        // Пороги: бледный пунктир, по которому код отсекает средние показатели
        // от высоких (когниция) и телесный отклик от его отсутствия (физиология).
        foreach ([Metrics::LOW_PCT, Metrics::HIGH_PCT] as $pct) {
            $px = round($x($pct), 1);
            $s[] = '<line x1="' . $px . '" y1="' . $padT . '" x2="' . $px . '" y2="' . ($padT + $plotH)
                 . '" stroke="' . self::DASH . '" stroke-width="1.2" stroke-dasharray="4 4"/>';
        }
        if ($hasPhys) {
            foreach ([$midBand, -$midBand] as $zna) {
                $py = round($yZna($zna), 1);
                $s[] = '<line x1="' . $padL . '" y1="' . $py . '" x2="' . ($padL + $plotW) . '" y2="' . $py
                     . '" stroke="' . self::DASH . '" stroke-width="1.2" stroke-dasharray="4 4"/>';
            }
            $py0 = round($yZna(0.0), 1);
            $s[] = '<line x1="' . $padL . '" y1="' . $py0 . '" x2="' . ($padL + $plotW) . '" y2="' . $py0
                 . '" stroke="' . self::MEDIAN . '" stroke-width="1.3" stroke-dasharray="6 4"/>';
            $s[] = '<text x="' . ($padL + 4) . '" y="' . ($py0 - 4) . '" font-size="9" fill="' . self::MEDIAN . '">медиана</text>';
        }

        // Кружки. Без физиологии все точки честно лежат на линии «нет данных»:
        // выдумывать вертикальную координату нельзя (Конституция, принцип II).
        $points = [];
        foreach ($axes as $a) {
            $px = $x((float) $a['cog_pct']);
            $py = $hasPhys && $a['phys_pct'] !== null ? $y((float) $a['phys_pct']) : $padT + $plotH / 2.0;
            $r = self::R_MIN + (self::R_MAX - self::R_MIN) * max(0.0, min(1.0, (float) $a['weight']));
            $points[] = ['a' => $a, 'x' => $px, 'y' => $py, 'r' => $r, 'nodata' => $hasPhys && $a['phys_pct'] === null];
        }
        // Крупные кружки рисуем первыми, чтобы мелкие не пропадали под ними, а
        // подписи получали место в порядке значимости показателя.
        usort($points, static fn ($p, $q) => (float) $q['a']['weight'] <=> (float) $p['a']['weight']);

        $bubbles = array_map(static fn ($p) => [$p['x'] - $p['r'], $p['y'] - $p['r'], $p['x'] + $p['r'], $p['y'] + $p['r']], $points);
        $labels = [];   // занятые подписями прямоугольники
        foreach ($points as $p) {
            $a = $p['a'];
            $isFlat = self::isPale($a);         // фон: в отчёте не разбирается (#7)
            $cx = round($p['x'], 1); $cy = round($p['y'], 1); $r = round($p['r'], 1);
            $sw = !empty($a['sig']) ? '2.2' : '1.2';
            $dash = $p['nodata'] ? ' stroke-dasharray="3 3"' : '';
            // Атрибуты для интерактивного слоя: математика всегда, интерпретация —
            // если она сопоставлена (interactiveHtml её подставит).
            $detail = self::esc(self::detailText($a));
            $interpAttr = isset($interp[$a['label']]) && $interp[$a['label']] !== ''
                ? ' data-interp="' . self::esc((string) $interp[$a['label']]) . '"' : '';
            $s[] = '<g class="mx-bubble" data-n="' . (int) $a['n'] . '" data-label="' . self::esc((string) $a['label'])
                 . '" data-detail="' . $detail . '"' . $interpAttr . '>';
            $s[] = '<title>' . $detail . '</title>';
            if ($isFlat || self::smkOrder((string) $a['smk']) === []) {
                // Фон или нераспознанный СМК — сплошной кружок (серый / нейтральный).
                $color = $isFlat ? self::C_FLAT : self::C_NONE;
                $s[] = '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="' . $color
                     . '" fill-opacity="' . ($isFlat ? '0.5' : '0.72') . '" stroke="' . $color . '" stroke-width="' . $sw . '"' . $dash . '/>';
            } else {
                // Кружок разделён на секторы X / Y / Z: больший сектор — преобладающий
                // параметр СМК (#5). Порядок и веса берём из столбца СМК.
                foreach (self::sectorPaths($cx, $cy, (float) $r, (string) $a['smk']) as [$path, $col]) {
                    $s[] = '<path d="' . $path . '" fill="' . $col . '" fill-opacity="0.82" stroke="#ffffff" stroke-width="0.6"/>';
                }
                $s[] = '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="none" stroke="'
                     . (!empty($a['sig']) ? '#2a3138' : '#ffffff') . '" stroke-width="' . $sw . '"' . $dash . '/>';
            }
            // Номер шкалы: внутри кружка, если он достаточно велик, иначе рядом.
            if ($p['r'] >= 8.5) {
                $s[] = '<text x="' . $cx . '" y="' . round($p['y'] + 3.4, 1) . '" text-anchor="middle" font-size="9.5"'
                     . ' font-weight="bold" fill="' . ($isFlat ? self::TEXT : '#ffffff') . '" pointer-events="none">' . (int) $a['n'] . '</text>';
            } else {
                $s[] = '<text x="' . round($p['x'] - $p['r'] - 2, 1) . '" y="' . round($p['y'] - $p['r'] - 1, 1) . '" text-anchor="end"'
                     . ' font-size="8" font-weight="bold" fill="' . self::color($a) . '" pointer-events="none">' . (int) $a['n'] . '</text>';
            }
            $s[] = '</g>';
            // Название — сбоку, только для разбираемых показателей: фоновые (#7)
            // подписываются лишь номером, подробности — при наведении.
            if ($isFlat) continue;
            $text = self::flat((string) $a['short']);
            $box = self::labelBox($p, $text, $bubbles, $labels, $padL, $W - 4);
            if ($box === null) continue;
            [$tx, $ty, $anchor, $rect] = $box;
            $labels[] = $rect;
            $s[] = '<text x="' . round($tx, 1) . '" y="' . round($ty + 3.2, 1) . '" text-anchor="' . $anchor . '" font-size="9" fill="'
                 . self::TEXT . '" pointer-events="none">' . self::esc($text) . '</text>';
        }

        // Легенда: цвет = доминирующий параметр, размер = сумма ответов.
        $ly = $padT + $plotH + 46;
        $lx = $padL;
        foreach (self::LEGEND as $k => [$c, $label]) {
            $width = 22 + (int) round(mb_strlen($label) * 5.4);
            if ($k > 0 && $lx + $width > $W - $padR) { $lx = $padL; $ly += 15; }
            $s[] = '<circle cx="' . ($lx + 5) . '" cy="' . ($ly - 3) . '" r="5" fill="' . $c . '" fill-opacity="0.72" stroke="' . $c . '"/>';
            $s[] = '<text x="' . ($lx + 14) . '" y="' . $ly . '" font-size="9.5" fill="' . self::TEXT . '">' . self::esc($label) . '</text>';
            $lx += $width + 10;
        }
        foreach ($noteLines as $k => $line) {
            $s[] = '<text x="' . $padL . '" y="' . ($ly + 18 + $k * 12) . '" font-size="9" fill="' . self::DIM . '">' . self::esc($line) . '</text>';
        }

        $s[] = '</svg>';
        return implode("\n", $s);
    }

    /**
     * Расшифровка номеров кружков — короткий текстовый список под матрицей.
     * Не таблица данных: только «номер — название», чтобы клиент понимал, какой
     * кружок к какому показателю относится.
     */
    public static function legendHtml(array $metrics): string {
        $axes = $metrics['axes'] ?? [];
        if (!$axes) return '';
        $h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $items = [];
        foreach ($axes as $a) {
            $items[] = '<b>' . (int) $a['n'] . '</b>&nbsp;— ' . $h($a['label']);
        }
        return '<p class="legend">' . implode('; ', $items) . '.</p>';
    }

    /**
     * ─── Интерактивная матрица для страницы результата (#7, #9) ───────────────
     *
     * Тот же SVG, но обёрнутый в контейнер с JS-подсказкой: при наведении на
     * кружок показываются и математика (уровень, «Знач.», зона), и — если
     * интерпретация уже сделана — относящийся к этой шкале фрагмент текста.
     * Фоновые показатели («не упоминается») на матрице серые и без подписи —
     * подробности видны только при наведении. В PDF/письмо по-прежнему уходит
     * статичный svg() (интерактивность там не нужна и невозможна).
     *
     * @param array  $metrics    Metrics::build()
     * @param string $interpText текст последней интерпретации (или '')
     * @param array  $opts       как у svg() (width, title)
     */
    public static function interactiveHtml(array $metrics, string $interpText = '', array $opts = []): string {
        $snippets = self::interpSnippets($metrics, $interpText);
        $svg = self::svg($metrics, $opts + ['interp' => $snippets]);
        if ($svg === '') return '';
        $hasInterp = $interpText !== '';
        $wid = 'mx' . substr(bin2hex(random_bytes(4)), 0, 8);
        ob_start(); ?>
<div class="mx-wrap" id="<?= $wid ?>"><?= $svg ?>
  <div class="mx-tip" hidden><div class="mx-tip-title"></div><div class="mx-tip-math"></div><div class="mx-tip-interp"></div></div>
</div>
<style>
  .mx-wrap{position:relative}
  .mx-wrap .mx-bubble{cursor:pointer}
  .mx-wrap .mx-bubble:hover{filter:brightness(1.06)}
  .mx-tip{position:absolute;z-index:20;max-width:320px;background:#2a3138;color:#fff;padding:9px 11px;border-radius:7px;
          font-size:12px;line-height:1.45;box-shadow:0 6px 20px rgba(0,0,0,.25);pointer-events:none}
  .mx-tip-title{font-weight:bold;margin-bottom:3px}
  .mx-tip-math{color:#d6dde3}
  .mx-tip-interp{margin-top:6px;padding-top:6px;border-top:1px solid #4a545d;color:#fff}
  .mx-tip-interp:empty{display:none;border:0;margin:0;padding:0}
</style>
<p class="muted"><?= $hasInterp
    ? 'Наведите на кружок — покажутся расчёт и относящийся к шкале фрагмент интерпретации.'
    : 'Наведите на кружок — покажется расчёт. После интерпретации здесь появится и её текст по каждой шкале.' ?></p>
<script>
(function(){
  var wrap=document.getElementById('<?= $wid ?>');
  if(!wrap) return;
  var tip=wrap.querySelector('.mx-tip'),
      tt=tip.querySelector('.mx-tip-title'),
      tm=tip.querySelector('.mx-tip-math'),
      ti=tip.querySelector('.mx-tip-interp');
  wrap.querySelectorAll('.mx-bubble').forEach(function(g){
    g.addEventListener('mouseenter', function(){
      tt.textContent = (g.dataset.n?g.dataset.n+'. ':'') + (g.dataset.label||'');
      tm.textContent = g.dataset.detail||'';
      ti.textContent = g.dataset.interp||'';
      tip.hidden=false;
    });
    g.addEventListener('mousemove', function(e){
      var b=wrap.getBoundingClientRect();
      var x=e.clientX-b.left+14, y=e.clientY-b.top+14;
      if(x+tip.offsetWidth>b.width) x=b.width-tip.offsetWidth-4;
      if(y+tip.offsetHeight>b.height) y=e.clientY-b.top-tip.offsetHeight-10;
      tip.style.left=Math.max(0,x)+'px'; tip.style.top=Math.max(0,y)+'px';
    });
    g.addEventListener('mouseleave', function(){ tip.hidden=true; });
  });
})();
</script>
<?php
        return ob_get_clean();
    }

    /**
     * Фрагмент интерпретации по каждой шкале: первый абзац текста, где встречается
     * название шкалы (или его короткая форма). Это честное сопоставление без
     * додумывания — если модель шкалу не упомянула, фрагмента нет.
     *
     * @return array<string,string> label => фрагмент
     */
    private static function interpSnippets(array $metrics, string $interpText): array {
        $out = [];
        if (trim($interpText) === '') return $out;
        // Абзацы: по пустой строке. Заголовки Markdown убираем из начала абзаца.
        $paras = preg_split('/\n\s*\n/u', str_replace("\r\n", "\n", $interpText)) ?: [];
        $paras = array_values(array_filter(array_map('trim', $paras), static fn ($p) => $p !== ''));
        foreach ($metrics['axes'] ?? [] as $a) {
            $needles = array_unique(array_filter([
                (string) ($a['label'] ?? ''),
                trim(str_replace("\n", ' ', (string) ($a['short'] ?? ''))),
            ]));
            foreach ($paras as $para) {
                $hit = false;
                foreach ($needles as $nd) {
                    if ($nd !== '' && mb_stripos($para, $nd) !== false) { $hit = true; break; }
                }
                if (!$hit) continue;
                $clean = trim((string) preg_replace('/^#{1,6}\s*/u', '', $para));
                $clean = trim((string) preg_replace('/[*_`#]+/u', '', $clean));
                if (mb_strlen($clean) > 400) $clean = mb_substr($clean, 0, 397) . '…';
                $out[(string) $a['label']] = $clean;
                break;
            }
        }
        return $out;
    }

    /** Плоское описание математики шкалы — для <title> и подсказки. */
    private static function detailText(array $a): string {
        $parts = [];
        $parts[] = 'Ответ теста: ' . Metrics::num((float) $a['score']) . ' из ' . (int) $a['scale_max']
                 . ' (' . Metrics::num((float) $a['pct']) . ' %, ' . $a['level_label'] . ')';
        if ($a['zna'] !== null) {
            $parts[] = 'Знач.: ' . Metrics::num((float) $a['zna']) . ' — ' . $a['phys_label'];
        } else {
            $parts[] = 'Физиология не распознана';
        }
        if (!empty($a['smk'])) $parts[] = 'СМК: ' . $a['smk'];
        $zone = Metrics::MATRIX_ZONES[$a['matrix_zone']] ?? '';
        if ($zone !== '') $parts[] = 'Зона: ' . $zone;
        $cat = (string) ($a['category_title'] ?? '');
        $parts[] = 'Раздел: ' . ($cat !== '' ? $cat : 'не упоминается в отчёте');
        return implode('. ', $parts) . '.';
    }

    /**
     * Порядок параметров СМК: «Z*>X>Y» → ['Z','X','Y']. Недостающие до трёх
     * буквы дописываются в конец (наименьший вес). Пустой токен → [].
     */
    private static function smkOrder(string $smk): array {
        $norm = str_replace(['Х', 'х', 'У', 'у', 'Ζ', 'x', 'y', 'z'], ['X', 'X', 'Y', 'Y', 'Z', 'X', 'Y', 'Z'], $smk);
        $order = [];
        foreach (preg_split('//u', $norm, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            if (in_array($ch, ['X', 'Y', 'Z'], true) && !in_array($ch, $order, true)) $order[] = $ch;
        }
        if ($order === []) return [];
        foreach (['X', 'Y', 'Z'] as $ch) if (!in_array($ch, $order, true)) $order[] = $ch;
        return $order;
    }

    /**
     * Секторы кружка по порядку СМК: первый параметр — самый большой сектор.
     * Веса по рангу (0.5 / 0.33 / 0.17) наглядно показывают преобладание, при
     * этом кружок честно разделён на X/Y/Z. Возвращает пары [path, color].
     *
     * @return array<int, array{0:string,1:string}>
     */
    private static function sectorPaths(float $cx, float $cy, float $r, string $smk): array {
        $order = self::smkOrder($smk);
        if ($order === []) return [];
        $weights = [0.5, 0.33, 0.17];
        $colors = ['X' => self::C_X, 'Y' => self::C_Y, 'Z' => self::C_Z];
        $out = [];
        $a0 = -M_PI / 2;            // старт сверху
        foreach ($order as $k => $letter) {
            $frac = $weights[$k] ?? 0.0;
            if ($frac <= 0) continue;
            $a1 = $a0 + 2 * M_PI * $frac;
            $x0 = $cx + $r * cos($a0); $y0 = $cy + $r * sin($a0);
            $x1 = $cx + $r * cos($a1); $y1 = $cy + $r * sin($a1);
            $large = ($a1 - $a0) > M_PI ? 1 : 0;
            $path = 'M ' . round($cx, 1) . ' ' . round($cy, 1)
                  . ' L ' . round($x0, 1) . ' ' . round($y0, 1)
                  . ' A ' . round($r, 1) . ' ' . round($r, 1) . ' 0 ' . $large . ' 1 ' . round($x1, 1) . ' ' . round($y1, 1) . ' Z';
            $out[] = [$path, $colors[$letter] ?? self::C_NONE];
            $a0 = $a1;
        }
        return $out;
    }

    /**
     * Бледный (серый) кружок — показатель, который в отчёте НЕ упоминается: ровно
     * та же категория «skip», что и в тексте интерпретации (Interpret и Metrics).
     * Так серые кружки на матрице совпадают со шкалами, о которых отчёт молчит
     * (требование заказчика: «не упоминается» → серый, подробности по наведению).
     */
    private static function isPale(array $axis): bool {
        return (string) ($axis['category'] ?? '') === 'skip';
    }

    /** Цвет кружка: бледно-серый для фоновых, иначе — по доминирующему параметру. */
    private static function color(array $axis): string {
        if (self::isPale($axis)) return self::C_FLAT;
        return match ((string) ($axis['dominant'] ?? '')) {
            'Y' => self::C_Y,
            'X' => self::C_X,
            'Z' => self::C_Z,
            default => self::C_NONE,
        };
    }

    /**
     * Место для подписи кружка: справа или слева, с вертикальным сдвигом.
     * Перебираем варианты и берём первый, который не залезает ни на другой
     * кружок, ни на уже поставленную подпись, ни за край картинки. Если места
     * нет — подписи не будет: показатель опознаётся по номеру (список под
     * матрицей), а нагромождение текста делает матрицу нечитаемой.
     *
     * @return array{0:float,1:float,2:string,3:array{0:float,1:float,2:float,3:float}}|null
     */
    private static function labelBox(array $p, string $text, array $bubbles, array $labels, float $minX, float $maxX): ?array {
        $w = mb_strlen($text) * 5.05 + 3.0;    // ширина строки Verdana 9 px
        foreach ([0.0, -12.0, 12.0, -22.0, 22.0] as $dy) {
            $ty = $p['y'] + $dy;
            foreach (['start', 'end'] as $anchor) {
                $tx = $anchor === 'start' ? $p['x'] + $p['r'] + 4 : $p['x'] - $p['r'] - 4;
                $rect = $anchor === 'start' ? [$tx, $ty - 5.5, $tx + $w, $ty + 5.5] : [$tx - $w, $ty - 5.5, $tx, $ty + 5.5];
                if ($rect[0] < $minX || $rect[2] > $maxX) continue;
                if (self::hits($rect, $bubbles) || self::hits($rect, $labels)) continue;
                return [$tx, $ty, $anchor, $rect];
            }
        }
        return null;
    }

    /** Пересекается ли прямоугольник с любым из списка. */
    private static function hits(array $rect, array $boxes): bool {
        foreach ($boxes as $b) {
            if ($rect[0] < $b[2] && $rect[2] > $b[0] && $rect[1] < $b[3] && $rect[3] > $b[1]) return true;
        }
        return false;
    }

    /** Перенос пояснения по словам: SVG сам не переносит текст. */
    private static function wrap(string $text, int $maxChars): array {
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $lines = [];
        $cur = '';
        foreach ($words as $word) {
            $try = $cur === '' ? $word : $cur . ' ' . $word;
            if (mb_strlen($try) > max(20, $maxChars) && $cur !== '') { $lines[] = $cur; $cur = $word; }
            else $cur = $try;
        }
        if ($cur !== '') $lines[] = $cur;
        return $lines;
    }

    /** Подпись оси в одну строку: в SHORT_LABELS переносы заданы для паутинки. */
    private static function flat(string $label): string {
        return trim((string) preg_replace('/\s+/u', ' ', str_replace("\n", ' ', $label)));
    }

    private static function esc(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
