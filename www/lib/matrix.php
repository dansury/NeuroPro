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

    /**
     * Радиус кружка: от «еле заметно» до «максимальная сумма ответов». Разброс
     * шире прежнего (5…17) — заказчик просил, чтобы разница читалась; за
     * контраст внутри диапазона отвечает мультипликатор (Metrics::sizePower()).
     */
    private const R_MIN = 4.0;
    private const R_MAX = 22.0;

    /** Легенда: параметры СМК как секторы кружка + служебные случаи. */
    private const LEGEND = [
        [self::C_Y, 'Y — эмоциональный отклик'],
        [self::C_X, 'X — психическое напряжение'],
        [self::C_Z, 'Z — мышечная реакция'],
        [self::C_NONE, 'параметр не распознан'],
        [self::C_FLAT, 'фон: не разбирается в отчёте'],
    ];

    /** Радиус кружка по весу и мультипликатору контраста. */
    private static function radius(float $weight, float $power): float {
        $w = max(0.0, min(1.0, $weight));
        return self::R_MIN + (self::R_MAX - self::R_MIN) * ($power === 1.0 ? $w : $w ** $power);
    }

    /**
     * @param array $metrics результат Metrics::build()
     * @param array $opts    ['width' => int, 'title' => string, 'compact' => bool]
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
        // Пороги и контраст размеров — настройка оператора (Metrics::configure).
        $lowPct = (float) ($metrics['low_pct'] ?? Metrics::LOW_PCT);
        $highPct = (float) ($metrics['high_pct'] ?? Metrics::HIGH_PCT);
        $power = (float) ($metrics['size_power'] ?? Metrics::SIZE_POWER);
        // Компактный режим — для PDF: диаграмма и матрица должны уместиться на
        // один лист, поэтому пояснения сжаты до сути (#7).
        $compact = !empty($opts['compact']);

        // Пояснения под матрицей переносим по ширине заранее: от числа строк
        // зависит высота картинки (иначе текст уезжал за край SVG).
        if ($compact) {
            $notes = ['Размер кружка — суммарная выраженность; секторы X / Y / Z — преобладающий параметр; серые кружки в отчёте не разбираются.'];
            $notes[] = 'Пунктир — границы уровней (' . Metrics::num($lowPct) . ' % и ' . Metrics::num($highPct) . ' %)'
                     . ($hasPhys ? ' и полоса медианы (±' . Metrics::num($midBand) . '); жирная обводка — достоверное отклонение (p<0.05).' : '.');
        } else {
            $notes = ['Размер кружка — выраженность суммы когнитивного и эмоционального ответа.'];
            $notes[] = 'Кружок разделён на секторы X (синий), Y (оранжевый), Z (зелёный): больший сектор — преобладающий параметр СМК; серый — фоновые показатели, в отчёте не разбираются.';
            $notes[] = 'Бледный пунктир — границы, по которым показатели делятся на низкие / средние / высокие ('
                     . Metrics::num($lowPct) . ' % и ' . Metrics::num($highPct) . ' % от максимума шкалы)'
                     . ($hasPhys ? ' и полоса медианы (±' . Metrics::num($midBand) . ').' : '.');
            $notes[] = $hasPhys
                ? 'Жирная обводка и жирная подпись — достоверное отклонение (p<0.05); пунктирная обводка — физиология по этой шкале не распознана.'
                : 'Физиология не распознана: показатели отложены только по когнитивной оси.';
        }

        $padL = 62; $padR = 26; $padT = 46;
        $plotW = $W - $padL - $padR;
        // Без физиологии вертикальной оси нет — незачем оставлять пустое поле.
        $plotH = (int) round($plotW * ($hasPhys ? 0.62 : 0.28));
        $noteLines = [];
        foreach ($notes as $note) {
            foreach (self::wrap($note, (int) floor($plotW / 4.9)) as $ln) $noteLines[] = $ln;
        }
        // Легенда: цвет = доминирующий параметр, размер = сумма ответов; последним
        // пунктом — жирная обводка достоверного отклонения (#5). Раскладку считаем
        // ЗДЕСЬ, до высоты картинки: раньше число строк оценивалось грубой формулой
        // и в узкой (PDF) матрице внизу оставалось до трёх пустых строк.
        $legend = self::LEGEND;
        if ($hasPhys) $legend[] = [null, 'достоверно (p<0.05)'];
        $legendRows = self::legendRows($legend, $padL, $W - $padR);
        $padB = 34 + $legendRows * 15 + count($noteLines) * 12 + 8;
        $H = $padT + $plotH + $padB;

        // Когниция: 0…100 % слева направо. Физиология: +phys_scale сверху,
        // −phys_scale снизу, медиана — точно по центру области.
        $x = static fn (float $pct) => $padL + $plotW * max(0.0, min(100.0, $pct)) / 100.0;
        $y = static fn (float $pct) => $padT + $plotH * (1.0 - max(0.0, min(100.0, $pct)) / 100.0);
        $yZna = static fn (float $zna) => $y(50.0 + 50.0 * $zna / ($physScale ?: 1.0));

        $s = [];
        // Геометрия площадки — в data-атрибутах корня: интерактивный слой
        // (interactiveHtml) переводит по ним пиксели курсора в проценты и «Знач.»
        // при перетаскивании пунктира. В PDF атрибуты просто игнорируются.
        $s[] = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $W . '" height="' . $H . '" viewBox="0 0 ' . $W . ' ' . $H
             . '" font-family="Verdana, Geneva, sans-serif" class="mx-svg"'
             . ' data-padl="' . $padL . '" data-padt="' . $padT . '" data-plotw="' . $plotW . '" data-ploth="' . $plotH . '"'
             . ' data-phys-scale="' . Metrics::num($physScale) . '" data-has-phys="' . ($hasPhys ? '1' : '0') . '"'
             . ' data-low="' . Metrics::num($lowPct) . '" data-high="' . Metrics::num($highPct) . '"'
             . ' data-mid-band="' . Metrics::num($midBand) . '" data-power="' . Metrics::num($power) . '"'
             // Полосу медианы отдаём долей, а не готовым числом: обратный пересчёт
             // из округлённого «±0.8» дал бы не ту границу, по которой считал сервер.
             . ' data-band-pct="' . Metrics::num((float) ($metrics['mid_band_frac'] ?? Metrics::MID_BAND_FRAC) * 100.0) . '"'
             . ' data-rmin="' . self::R_MIN . '" data-rmax="' . self::R_MAX . '">';
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
        // На странице результата этот пунктир можно таскать мышью — отсюда
        // классы и подписи со значением (интерактив живёт в interactiveHtml).
        foreach ([['low', $lowPct], ['high', $highPct]] as [$key, $pct]) {
            $px = round($x($pct), 1);
            $s[] = '<g class="mx-thr mx-thr-v" data-key="' . $key . '">'
                 . '<line class="mx-thr-hit" x1="' . $px . '" y1="' . $padT . '" x2="' . $px . '" y2="' . ($padT + $plotH)
                 . '" stroke="transparent" stroke-width="10"/>'
                 . '<line class="mx-thr-line" x1="' . $px . '" y1="' . $padT . '" x2="' . $px . '" y2="' . ($padT + $plotH)
                 . '" stroke="' . self::DASH . '" stroke-width="1.2" stroke-dasharray="4 4"/>'
                 . '<text class="mx-thr-val" x="' . ($px + 3) . '" y="' . ($padT + 10) . '" font-size="8.5" fill="' . self::DIM . '">'
                 . self::esc(Metrics::num($pct) . ' %') . '</text></g>';
        }
        if ($hasPhys) {
            foreach ([['band+', $midBand], ['band-', -$midBand]] as [$key, $zna]) {
                $py = round($yZna($zna), 1);
                $s[] = '<g class="mx-thr mx-thr-h" data-key="' . $key . '">'
                     . '<line class="mx-thr-hit" x1="' . $padL . '" y1="' . $py . '" x2="' . ($padL + $plotW) . '" y2="' . $py
                     . '" stroke="transparent" stroke-width="10"/>'
                     . '<line class="mx-thr-line" x1="' . $padL . '" y1="' . $py . '" x2="' . ($padL + $plotW) . '" y2="' . $py
                     . '" stroke="' . self::DASH . '" stroke-width="1.2" stroke-dasharray="4 4"/>'
                     . '<text class="mx-thr-val" x="' . ($padL + $plotW - 3) . '" y="' . ($py - 3) . '" text-anchor="end" font-size="8.5" fill="'
                     . self::DIM . '">' . self::esc(Metrics::num($zna)) . '</text></g>';
            }
            $py0 = round($yZna(0.0), 1);
            $s[] = '<line x1="' . $padL . '" y1="' . $py0 . '" x2="' . ($padL + $plotW) . '" y2="' . $py0
                 . '" stroke="' . self::MEDIAN . '" stroke-width="1.3" stroke-dasharray="6 4"/>';
            $s[] = '<text x="' . ($padL + 4) . '" y="' . ($py0 - 4) . '" font-size="9" fill="' . self::MEDIAN . '">медиана</text>';
        }

        // Кружки. Без физиологии все точки честно лежат на линии «нет данных»:
        // выдумывать вертикальную координату нельзя (Конституция, принцип II).
        //
        // Форма кружка рисуется РАДИУСОМ 1 в начале координат и ставится на место
        // трансформацией translate+scale. Так интерактивный слой меняет размер
        // одним атрибутом, когда оператор двигает мультипликатор, — и не должен
        // пересобирать секторы. Обводка от масштаба не зависит
        // (vector-effect="non-scaling-stroke").
        $points = [];
        foreach ($axes as $a) {
            $px = $x((float) $a['cog_pct']);
            $py = $hasPhys && $a['phys_pct'] !== null ? $y((float) $a['phys_pct']) : $padT + $plotH / 2.0;
            $r = self::radius((float) $a['weight'], $power);
            $points[] = ['a' => $a, 'x' => $px, 'y' => $py, 'r' => $r, 'nodata' => $hasPhys && $a['phys_pct'] === null];
        }
        // Крупные кружки рисуем первыми, чтобы мелкие не пропадали под ними, а
        // подписи получали место в порядке значимости показателя.
        usort($points, static fn ($p, $q) => (float) $q['a']['weight'] <=> (float) $p['a']['weight']);

        $bubbles = array_map(static fn ($p) => [$p['x'] - $p['r'], $p['y'] - $p['r'], $p['x'] + $p['r'], $p['y'] + $p['r']], $points);
        foreach ($points as $p) {
            $a = $p['a'];
            $isFlat = self::isPale($a);         // фон: в отчёте не разбирается (#7)
            $isSig = !empty($a['sig']);
            $cx = round($p['x'], 1); $cy = round($p['y'], 1); $r = round($p['r'], 1);
            $sectors = $isFlat ? [] : self::sectorPaths((string) $a['smk']);
            // Достоверное отклонение читается сразу: тёмная жирная обводка (#5).
            $sw = $isSig ? '2.6' : '1.2';
            $dash = $p['nodata'] ? ' stroke-dasharray="3 3"' : '';
            $plain = $isFlat ? self::C_FLAT : self::C_NONE;
            $ring = $isSig ? '#2a3138' : ($sectors ? '#ffffff' : $plain);
            // Атрибуты для интерактивного слоя: математика всегда, интерпретация —
            // если она сопоставлена (interactiveHtml её подставит).
            $detail = self::esc(self::detailText($a));
            $interpAttr = isset($interp[$a['label']]) && $interp[$a['label']] !== ''
                ? ' data-interp="' . self::esc((string) $interp[$a['label']]) . '"' : '';
            $s[] = '<g class="mx-bubble" data-n="' . (int) $a['n'] . '" data-label="' . self::esc((string) $a['label'])
                 . '" data-detail="' . $detail . '"' . $interpAttr
                 // Вес — с полной точностью: Metrics::num() округлил бы до
                 // десятых, и кружки в интерактиве получались бы не того размера,
                 // что на статичной картинке в PDF.
                 . ' data-cx="' . $cx . '" data-cy="' . $cy . '" data-r="' . $r
                 . '" data-weight="' . rtrim(rtrim(number_format((float) $a['weight'], 3, '.', ''), '0'), '.')
                 . '" data-cog="' . Metrics::num((float) $a['cog_pct']) . '" data-zna="' . ($a['zna'] === null ? '' : Metrics::num((float) $a['zna']))
                 . '" data-sig="' . ($isSig ? '1' : '0') . '" data-pale="' . ($isFlat ? '1' : '0') . '">';
            $s[] = '<title>' . $detail . '</title>';
            $s[] = '<g class="mx-shape" transform="translate(' . $cx . ' ' . $cy . ') scale(' . $r . ')">';
            // Сплошной кружок — фон или нераспознанный СМК; секторы — когда СМК есть.
            $s[] = '<circle class="mx-plain" cx="0" cy="0" r="1" fill="' . $plain . '" fill-opacity="'
                 . ($isFlat ? '0.5' : '0.72') . '"' . ($sectors ? ' display="none"' : '') . '/>';
            if ($sectors) {
                // Кружок разделён на секторы X / Y / Z: больший сектор — преобладающий
                // параметр СМК. Порядок и веса берём из столбца СМК.
                $s[] = '<g class="mx-sectors">';
                foreach ($sectors as [$path, $col]) {
                    $s[] = '<path d="' . $path . '" fill="' . $col . '" fill-opacity="0.82" stroke="#ffffff"'
                         . ' stroke-width="0.6" vector-effect="non-scaling-stroke"/>';
                }
                $s[] = '</g>';
            }
            $s[] = '<circle class="mx-ring" cx="0" cy="0" r="1" fill="none" stroke="' . $ring . '" stroke-width="' . $sw
                 . '" vector-effect="non-scaling-stroke"' . $dash . '/>';
            $s[] = '</g>';
            // Номер шкалы: внутри кружка, если он достаточно велик, иначе рядом.
            // Рисуем оба варианта и показываем нужный — при смене мультипликатора
            // интерактивный слой просто переключает их видимостью.
            $s[] = '<text class="mx-num-in" x="' . $cx . '" y="' . round($p['y'] + 3.4, 1) . '" text-anchor="middle" font-size="9.5"'
                 . ' font-weight="bold" fill="' . ($isFlat ? self::TEXT : '#ffffff') . '"'
                 . ' pointer-events="none"' . ($r >= 8.5 ? '' : ' display="none"') . '>' . (int) $a['n'] . '</text>';
            $s[] = '<text class="mx-num-out" x="' . round($p['x'] - $p['r'] - 2, 1) . '" y="' . round($p['y'] - $p['r'] - 1, 1)
                 . '" text-anchor="end" font-size="8" font-weight="bold" fill="' . self::color($a) . '" pointer-events="none"'
                 . ($r >= 8.5 ? ' display="none"' : '') . '>' . (int) $a['n'] . '</text>';
            $s[] = '</g>';
        }

        // Названия шкал — отдельным проходом ПОСЛЕ всех кружков: подпись не должна
        // прятаться под соседним кружком. Сначала места получают разбираемые
        // показатели, фоновые — из того, что осталось (и по умолчанию скрыты:
        // на матрице они серые и подписаны только номером). Скрытые подписи всё
        // равно печатаются в разметку — интерактивный слой показывает их, когда
        // сдвинутый порог переводит показатель из фона в рабочие.
        $labels = [];   // занятые подписями прямоугольники
        foreach ([false, true] as $paleTurn) {
            foreach ($points as $p) {
                $a = $p['a'];
                $isFlat = self::isPale($a);
                if ($isFlat !== $paleTurn) continue;
                $text = self::flat((string) $a['short']);
                $box = self::labelBox($p, $text, $bubbles, $labels, $padL, $W - 4);
                if ($box === null) continue;
                [$tx, $ty, $anchor, $rect] = $box;
                $labels[] = $rect;
                $s[] = '<text class="mx-name" data-n="' . (int) $a['n'] . '" data-cx="' . round($p['x'], 1) . '" data-r="'
                     . round($p['r'], 1) . '" data-side="' . $anchor . '" x="' . round($tx, 1) . '" y="' . round($ty + 3.2, 1)
                     . '" text-anchor="' . $anchor . '" font-size="9"' . (!empty($a['sig']) ? ' font-weight="bold"' : '')
                     . ' fill="' . self::TEXT . '" pointer-events="none"' . ($isFlat ? ' display="none"' : '') . '>'
                     . self::esc($text) . '</text>';
            }
        }

        // Легенда — по той же раскладке, по которой выше посчитана высота картинки.
        $ly = $padT + $plotH + 46;
        $lx = $padL;
        foreach ($legend as $k => [$c, $label]) {
            $width = self::legendWidth($label);
            if ($k > 0 && $lx + $width > $W - $padR) { $lx = $padL; $ly += 15; }
            $s[] = $c === null
                ? '<circle cx="' . ($lx + 5) . '" cy="' . ($ly - 3) . '" r="4.5" fill="#ffffff" stroke="#2a3138" stroke-width="2.6"/>'
                : '<circle cx="' . ($lx + 5) . '" cy="' . ($ly - 3) . '" r="5" fill="' . $c . '" fill-opacity="0.72" stroke="' . $c . '"/>';
            $s[] = '<text x="' . ($lx + 14) . '" y="' . $ly . '" font-size="9.5"' . ($c === null ? ' font-weight="bold"' : '')
                 . ' fill="' . self::TEXT . '">' . self::esc($label) . '</text>';
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
     * Плюс настройка прямо на картинке: пунктир порогов таскается мышью, кружки
     * при этом перекрашиваются на лету (серые ⇄ цветные — ровно по той же
     * таблице категорий, что и в Metrics), а маленькое поле под легендой меняет
     * контраст размеров. Оба значения сохраняются в настройки сервиса, поэтому
     * следующий расчёт, отчёт и PDF считаются уже по ним.
     *
     * @param array  $metrics    Metrics::build()
     * @param string $interpText текст последней интерпретации (или '')
     * @param array  $opts       как у svg() (width, title) + ['save_url' => string]
     */
    public static function interactiveHtml(array $metrics, string $interpText = '', array $opts = []): string {
        $snippets = self::interpSnippets($metrics, $interpText);
        $svg = self::svg($metrics, $opts + ['interp' => $snippets]);
        if ($svg === '') return '';
        $hasInterp = $interpText !== '';
        $hasPhys = !empty($metrics['has_phys']) && (float) ($metrics['phys_scale'] ?? 0) > 0;
        $saveUrl = (string) ($opts['save_url'] ?? '');
        $n = static fn (float $v) => Metrics::num($v);
        $wid = 'mx' . substr(bin2hex(random_bytes(4)), 0, 8);
        ob_start(); ?>
<div class="mx-wrap" id="<?= $wid ?>"><?= $svg ?>
  <div class="mx-tip" hidden><div class="mx-tip-title"></div><div class="mx-tip-math"></div><div class="mx-tip-interp"></div></div>
</div>
<div class="mx-ctl" id="<?= $wid ?>-ctl">
  <label>Порог «средние», %<input type="number" class="mx-f" data-k="low" min="1" max="98" step="1" value="<?= $n((float)($metrics['low_pct'] ?? Metrics::LOW_PCT)) ?>"></label>
  <label>Порог «высокие», %<input type="number" class="mx-f" data-k="high" min="2" max="99" step="1" value="<?= $n((float)($metrics['high_pct'] ?? Metrics::HIGH_PCT)) ?>"></label>
  <?php if ($hasPhys): ?>
    <label>Полоса медианы, % шкалы<input type="number" class="mx-f" data-k="band" min="0" max="50" step="0.5" value="<?= $n((float)($metrics['mid_band_frac'] ?? Metrics::MID_BAND_FRAC) * 100.0) ?>"></label>
  <?php endif; ?>
  <label>Мультипликатор размера<input type="number" class="mx-f" data-k="power" min="0.2" max="6" step="0.1" value="<?= $n((float)($metrics['size_power'] ?? Metrics::SIZE_POWER)) ?>"></label>
  <span class="mx-state muted">Пунктир можно тащить мышью — значения сохраняются.</span>
</div>
<style>
  .mx-wrap{position:relative}
  .mx-wrap .mx-bubble{cursor:pointer}
  .mx-wrap .mx-bubble:hover{filter:brightness(1.06)}
  .mx-wrap .mx-thr{cursor:default}
  .mx-wrap .mx-thr-v{cursor:ew-resize} .mx-wrap .mx-thr-h{cursor:ns-resize}
  .mx-wrap .mx-thr:hover .mx-thr-line{stroke:#b3203b;stroke-width:1.8}
  .mx-wrap .mx-thr:hover .mx-thr-val{fill:#b3203b;font-weight:bold}
  .mx-tip{position:absolute;z-index:20;max-width:320px;background:#2a3138;color:#fff;padding:9px 11px;border-radius:7px;
          font-size:12px;line-height:1.45;box-shadow:0 6px 20px rgba(0,0,0,.25);pointer-events:none}
  .mx-tip-title{font-weight:bold;margin-bottom:3px}
  .mx-tip-math{color:#d6dde3}
  .mx-tip-interp{margin-top:6px;padding-top:6px;border-top:1px solid #4a545d;color:#fff}
  .mx-tip-interp:empty{display:none;border:0;margin:0;padding:0}
  .mx-ctl{display:flex;flex-wrap:wrap;gap:10px 16px;align-items:flex-end;justify-content:center;margin:4px 0 0;font-size:11px;color:#6b7682}
  .mx-ctl label{display:block;margin:0}
  .mx-ctl input{width:74px;padding:3px 6px;border:1px solid #cfd6dd;border-radius:4px;font:inherit;text-align:center;display:block;margin-top:2px}
  .mx-ctl .mx-state{align-self:center}
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

  /* ─── Пороги и мультипликатор: тянем пунктир — кружки перекрашиваются ───────
     Таблица категорий здесь ТА ЖЕ, что в Metrics::category(): это её зеркало для
     мгновенной перерисовки, а не второй источник истины. Сохранённые значения
     уходят в настройки, и следующий расчёт (отчёт, PDF, промпт) считается по ним
     уже на сервере. */
  var svg=wrap.querySelector('.mx-svg'); if(!svg) return;
  var D=svg.dataset,
      padL=+D.padl, padT=+D.padt, plotW=+D.plotw, plotH=+D.ploth,
      physScale=+D.physScale||0, hasPhys=D.hasPhys==='1',
      RMIN=+D.rmin, RMAX=+D.rmax,
      C_FLAT='<?= self::C_FLAT ?>', C_NONE='<?= self::C_NONE ?>', C_TEXT='<?= self::TEXT ?>',
      MID_BAND_MIN=<?= Metrics::MID_BAND_MIN ?>,
      saveUrl=<?= json_encode($saveUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  var st={low:+D.low, high:+D.high, band:+D.bandPct, power:+D.power};
  var ctl=document.getElementById('<?= $wid ?>-ctl');
  if(!ctl) return;
  var state=ctl.querySelector('.mx-state'),
      fields=ctl.querySelectorAll('.mx-f'),
      // Округляем по модулю: Math.round(−6.75) даёт −6.7, и симметричные
      // границы полосы медианы подписывались бы разными числами.
      num=function(v){ return String((v<0?-1:1)*Math.round(Math.abs(v)*10)/10); };

  function band(){ return Math.max(MID_BAND_MIN, st.band/100*physScale); }
  function level(cog){ return cog<st.low ? 'low' : (cog<st.high ? 'mid' : 'high'); }
  function physState(zna){
    if(zna===null) return null;
    if(Math.abs(zna)<=band()) return 'median';
    return zna>0 ? 'above' : 'below';
  }
  function isSkip(lvl, ps, sig){
    if(ps==='above'&&sig) return false;
    if(ps==='below') return !(lvl==='high' || (sig&&lvl==='mid'));
    return lvl==='low';
  }
  function radius(w){ return RMIN+(RMAX-RMIN)*Math.pow(Math.max(0,Math.min(1,w)), st.power); }

  var names={};
  svg.querySelectorAll('.mx-name').forEach(function(t){ names[t.dataset.n]=t; });

  function paint(){
    svg.querySelectorAll('.mx-bubble').forEach(function(g){
      var zna=g.dataset.zna===''?null:+g.dataset.zna,
          sig=g.dataset.sig==='1',
          pale=isSkip(level(+g.dataset.cog), physState(zna), sig),
          r=radius(+g.dataset.weight),
          cx=+g.dataset.cx, cy=+g.dataset.cy,
          sect=g.querySelector('.mx-sectors'),
          plain=g.querySelector('.mx-plain'),
          ring=g.querySelector('.mx-ring'),
          inN=g.querySelector('.mx-num-in'),
          outN=g.querySelector('.mx-num-out');
      g.dataset.pale=pale?'1':'0';
      g.querySelector('.mx-shape').setAttribute('transform','translate('+cx+' '+cy+') scale('+num(r)+')');
      if(sect) sect.setAttribute('display', pale?'none':'inline');
      plain.setAttribute('display', (pale||!sect)?'inline':'none');
      plain.setAttribute('fill', pale?C_FLAT:C_NONE);
      plain.setAttribute('fill-opacity', pale?'0.5':'0.72');
      ring.setAttribute('stroke', sig ? '#2a3138' : (!pale&&sect ? '#ffffff' : (pale?C_FLAT:C_NONE)));
      inN.setAttribute('display', r>=8.5?'inline':'none');
      inN.setAttribute('fill', pale?C_TEXT:'#ffffff');
      inN.setAttribute('y', num(cy+3.4));
      outN.setAttribute('display', r>=8.5?'none':'inline');
      outN.setAttribute('x', num(cx-r-2)); outN.setAttribute('y', num(cy-r-1));
      outN.setAttribute('fill', pale?C_FLAT:C_NONE);
      var nm=names[g.dataset.n];
      if(nm){
        nm.setAttribute('display', pale?'none':'inline');
        nm.setAttribute('x', num(nm.dataset.side==='start' ? cx+r+4 : cx-r-4));
      }
    });
  }

  function drawLines(){
    svg.querySelectorAll('.mx-thr').forEach(function(g){
      var k=g.dataset.key, val=g.querySelector('.mx-thr-val');
      if(k==='low'||k==='high'){
        var px=padL+plotW*Math.max(0,Math.min(100,st[k]))/100;
        g.querySelectorAll('line').forEach(function(l){ l.setAttribute('x1',num(px)); l.setAttribute('x2',num(px)); });
        val.setAttribute('x', num(px+3)); val.textContent=num(st[k])+' %';
      } else if(physScale>0){
        var z=(k==='band+'?1:-1)*band(),
            py=padT+plotH*(0.5-0.5*z/physScale);
        g.querySelectorAll('line').forEach(function(l){ l.setAttribute('y1',num(py)); l.setAttribute('y2',num(py)); });
        val.setAttribute('y', num(py-3)); val.textContent=num(z);
      }
    });
  }

  var timer=null;
  function save(){
    if(!saveUrl) return;
    clearTimeout(timer);
    timer=setTimeout(function(){
      var body=new URLSearchParams({low:st.low, high:st.high, band:st.band, power:st.power});
      fetch(saveUrl,{method:'POST',body:body,
        headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}})
        .then(function(r){ return r.json(); })
        .then(function(d){
          if(!d.ok) throw new Error(d.error||'не удалось сохранить');
          // Пороги решают не только цвет кружка, но и раздел отчёта, поэтому
          // предлагаем пересчитать страницу, а не делаем это молча посреди правки.
          state.innerHTML='Сохранено. <a href="">Пересчитать разделы</a>';
        })
        .catch(function(e){ state.textContent='Не сохранено: '+e.message; });
    }, 500);
  }

  function apply(fromField){
    if(st.high<=st.low) st.high=Math.min(99, st.low+1);
    drawLines(); paint();
    // Поле, в котором сейчас печатают, не перебиваем — иначе курсор прыгает;
    // его значение выравнивается по 'change' (уход фокуса / Enter).
    fields.forEach(function(f){ if(f!==fromField) f.value=num(st[f.dataset.k]); });
    save();
  }

  fields.forEach(function(f){
    f.addEventListener('input', function(){
      var v=parseFloat(f.value.replace(',','.'));
      if(!isFinite(v)) return;
      st[f.dataset.k]=Math.max(+f.min, Math.min(+f.max, v));
      apply(f);
    });
    f.addEventListener('change', function(){ fields.forEach(function(g){ g.value=num(st[g.dataset.k]); }); });
  });

  // Перетаскивание пунктира. Координаты курсора переводим в единицы SVG:
  // картинка масштабируется по ширине контейнера (max-width:100%).
  svg.querySelectorAll('.mx-thr').forEach(function(g){
    g.addEventListener('pointerdown', function(e){
      e.preventDefault();
      var k=g.dataset.key;
      if((k==='band+'||k==='band-') && physScale<=0) return;
      g.setPointerCapture(e.pointerId);
      var box=svg.getBoundingClientRect(), scale=box.width/svg.width.baseVal.value;
      var move=function(ev){
        var ux=(ev.clientX-box.left)/scale, uy=(ev.clientY-box.top)/scale;
        if(k==='low'||k==='high'){
          st[k]=Math.max(1, Math.min(99, (ux-padL)/plotW*100));
          if(k==='low') st.low=Math.min(st.low, st.high-1); else st.high=Math.max(st.high, st.low+1);
        } else {
          var z=(padT+plotH/2-uy)/(plotH/2)*physScale;
          st.band=Math.max(0, Math.min(50, Math.abs(z)/physScale*100));
        }
        drawLines(); paint();
        fields.forEach(function(f){ f.value=num(st[f.dataset.k]); });
      };
      var up=function(ev){
        g.releasePointerCapture(e.pointerId);
        g.removeEventListener('pointermove', move);
        g.removeEventListener('pointerup', up);
        st.low=Math.round(st.low*10)/10; st.high=Math.round(st.high*10)/10; st.band=Math.round(st.band*10)/10;
        apply(null);
      };
      g.addEventListener('pointermove', move);
      g.addEventListener('pointerup', up);
    });
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
        if (!empty($a['smk'])) {
            $meaning = (string) ($a['smk_meaning'] ?? '');
            $parts[] = 'СМК: ' . $a['smk'] . ($meaning !== '' ? ' — ' . $meaning : '');
        }
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
     * Путь строится в ЕДИНИЧНОМ круге с центром в начале координат: на место его
     * ставит transform="translate(cx cy) scale(r)" у группы кружка, поэтому смена
     * мультипликатора размеров не требует пересчёта секторов.
     *
     * @return array<int, array{0:string,1:string}>
     */
    private static function sectorPaths(string $smk): array {
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
            $x0 = cos($a0); $y0 = sin($a0);
            $x1 = cos($a1); $y1 = sin($a1);
            $large = ($a1 - $a0) > M_PI ? 1 : 0;
            $path = 'M 0 0'
                  . ' L ' . round($x0, 4) . ' ' . round($y0, 4)
                  . ' A 1 1 0 ' . $large . ' 1 ' . round($x1, 4) . ' ' . round($y1, 4) . ' Z';
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

    /** Ширина пункта легенды (кружок + подпись Verdana 9.5 px). */
    private static function legendWidth(string $label): float {
        return 22 + round(mb_strlen($label) * 5.4);
    }

    /** Сколько строк займёт легенда при данной ширине — та же раскладка, что при отрисовке. */
    private static function legendRows(array $legend, float $minX, float $maxX): int {
        $rows = 1;
        $lx = $minX;
        foreach ($legend as $k => [$c, $label]) {
            $width = self::legendWidth($label);
            if ($k > 0 && $lx + $width > $maxX) { $lx = $minX; $rows++; }
            $lx += $width + 10;
        }
        return $rows;
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
