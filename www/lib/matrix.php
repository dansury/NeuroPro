<?php
/**
 * Матрица показателей «когниция × эмоция» — математика, не нейросеть.
 *
 * Диаграмма-паутинка показывает профиль по осям-шкалам, но не показывает
 * ГЛАВНОГО соотношения: насколько ответы теста совпадают с телесной реакцией.
 * Матрица кладёт каждый показатель в плоскость:
 *   - ось ординат (вертикальная) — результаты теста в единицах самого теста:
 *     у СМУ это баллы 0…10, у ИЖС — проценты, у Басса-Дарки шкалы имеют разные
 *     максимумы (5…13), поэтому единственная сравнимая подпись — процент от
 *     максимума своей шкалы (см. Metrics::unit()); выше — результат сильнее;
 *   - ось абсцисс (горизонтальная) — телесный отклик: «Знач.» из таблицы
 *     смысло-эмоциональной значимости Эгоскопа на симметричной шкале
 *     ±phys_scale, медиана — по центру, правее — отклик сильнее.
 *
 * Кружок = показатель. Как он выглядит, решает одна таблица — bubbleStyle():
 *   - тело откликается выше медианы → кружок разделён на секторы СМК (Y —
 *     оранжевый, X — синий, Z — зелёный): видно состав реакции. Параметра, не
 *     названного в столбце СМК, на кружке нет;
 *   - тело ниже медианы (или у неё), но ответы выше порога → ОДИН цвет,
 *     преобладающий параметр, без секторальной разбивки;
 *   - СМК не распознан → фиолетовый: отсутствие данных не должно читаться как
 *     ещё один нейтральный показатель;
 *   - ответы ниже порога, а тело откликается → красная обводка: это расхождение
 *     (левый верхний угол матрицы), а не выраженный показатель;
 *   - ниже ОБОИХ порогов → бледно-серый: только такие показатели не разбираются
 *     в отчёте.
 * Размер кружка — вес показателя (сумма когнитивного и эмоционального ответа,
 * Metrics::weight()), нормированный по разбросу профиля: настройка оператора
 * меняет РАЗНИЦУ между кружками, а не их общий размер. Наложившиеся кружки
 * разводятся в стороны (spread()), чтобы близкие значения не сливались в пятно.
 * Бледный пунктир — границы, по которым код отсекает средние показатели от
 * высоких и телесный отклик от его отсутствия (полоса медианы ±mid_band).
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
    private const C_NONE = '#8e44c8'; // СМК не распознан — фиолетовый (заметно, что данных нет)
    private const C_FLAT = '#c2ccd4'; // ниже обоих порогов — бледный серый
    private const C_ALERT = '#c0392b'; // обводка расхождения «ответы молчат — тело откликается»

    private const GRID = '#eaeef2';
    private const AXIS = '#c9d2da';
    private const DASH = '#cfd8e0';   // пунктир порогов
    private const MEDIAN = '#9fb4bd';
    private const TEXT = '#2a3138';
    private const DIM = '#8a949d';

    /** Радиус кружка: от «еле заметно» до «самый тяжёлый показатель профиля». */
    private const R_MIN = 5.0;
    private const R_MAX = 20.0;

    /** Зазор между кружками при разведении наложений (#10). */
    private const GAP = 1.5;

    /** Легенда: параметры СМК как секторы кружка + служебные случаи. */
    private const LEGEND = [
        [self::C_Y, 'Y — эмоциональный отклик'],
        [self::C_X, 'X — психическое напряжение'],
        [self::C_Z, 'Z — мышечная реакция'],
        [self::C_NONE, 'параметр не распознан'],
        [self::C_FLAT, 'фон: не разбирается в отчёте'],
    ];

    /**
     * Радиус кружка. Размер показывает, насколько показатель тяжелее ОСТАЛЬНЫХ
     * в этом же профиле: вес нормируется по разбросу профиля (wMin…wMax), а
     * настройка оператора растягивает или сжимает разницу вокруг середины.
     *
     * Раньше настройка была показателем степени (вес^POWER) и меняла не разницу,
     * а сам размер: при контрасте 2 весь профиль становился мелким, потому что
     * вес меньше единицы в квадрате только убывает. Теперь при контрасте 0 все
     * кружки одинаковые, при 1 разброс занимает весь диапазон R_MIN…R_MAX, выше
     * 1 — крайние показатели расходятся ещё сильнее (#2).
     */
    private static function radius(float $weight, float $contrast, float $wMin, float $wMax): float {
        $span = $wMax - $wMin;
        $t = $span > 1e-9 ? ($weight - $wMin) / $span : 0.5;
        $t = 0.5 + ($t - 0.5) * $contrast;
        $t = max(0.0, min(1.0, $t));
        return self::R_MIN + (self::R_MAX - self::R_MIN) * $t;
    }

    /**
     * @param array $metrics результат Metrics::build()
     * @param array $opts    ['width' => int, 'title' => string, 'interp' => array]
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
        $contrast = (float) ($metrics['size_contrast'] ?? Metrics::SIZE_CONTRAST);
        $wMin = (float) ($metrics['weight_min'] ?? 0.0);
        $wMax = (float) ($metrics['weight_max'] ?? 1.0);

        $padL = 62; $padR = 26; $padT = 46;
        $plotW = $W - $padL - $padR;
        // Вертикальная ось — результаты теста, они есть всегда (в отличие от
        // телесного отклика, который иногда не распознан), поэтому высота
        // площадки больше не зависит от наличия физиологии.
        $plotH = (int) round($plotW * 0.62);
        // Пояснений под матрицей больше нет: расшифровки цветов, пунктира и
        // обводки заказчик просил убрать — всё, что нужно, читается по легенде
        // (#4). Освободившееся место отдано самой картинке.
        $legend = self::LEGEND;
        if ($hasPhys) {
            $legend[] = [null, 'достоверно (p<0.05)'];
            $legend[] = ['alert', 'ниже порога по тесту, но тело откликается'];
        }
        // Раскладку легенды считаем ЗДЕСЬ, до высоты картинки: раньше число строк
        // оценивалось грубой формулой и внизу оставались пустые строки.
        $legendRows = self::legendRows($legend, $padL, $W - $padR);
        $padB = 34 + $legendRows * 15 + 8;
        $H = $padT + $plotH + $padB;

        // Результаты теста — по вертикали: 0 % снизу, 100 % сверху (выше —
        // сильнее выражено в ответах). Телесный отклик — по горизонтали:
        // −phys_scale слева, +phys_scale справа, медиана — точно по центру
        // области (правее — телесный отклик сильнее).
        $x = static fn (float $pct) => $padL + $plotW * max(0.0, min(100.0, $pct)) / 100.0;
        $y = static fn (float $pct) => $padT + $plotH * (1.0 - max(0.0, min(100.0, $pct)) / 100.0);
        $xZna = static fn (float $zna) => $x(50.0 + 50.0 * $zna / ($physScale ?: 1.0));

        $s = [];
        // Геометрия площадки — в data-атрибутах корня: интерактивный слой
        // (interactiveHtml) переводит по ним пиксели курсора в проценты и «Знач.»
        // при перетаскивании пунктира. В PDF атрибуты просто игнорируются.
        $s[] = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $W . '" height="' . $H . '" viewBox="0 0 ' . $W . ' ' . $H
             . '" font-family="Verdana, Geneva, sans-serif" class="mx-svg"'
             . ' data-padl="' . $padL . '" data-padt="' . $padT . '" data-plotw="' . $plotW . '" data-ploth="' . $plotH . '"'
             . ' data-phys-scale="' . Metrics::num($physScale) . '" data-has-phys="' . ($hasPhys ? '1' : '0') . '"'
             . ' data-low="' . Metrics::num($lowPct) . '" data-high="' . Metrics::num($highPct) . '"'
             . ' data-mid-band="' . Metrics::num($midBand) . '" data-contrast="' . Metrics::num($contrast) . '"'
             . ' data-wmin="' . self::num3($wMin) . '" data-wmax="' . self::num3($wMax) . '"'
             // Полосу медианы отдаём долей, а не готовым числом: обратный пересчёт
             // из округлённого «±0.8» дал бы не ту границу, по которой считал сервер.
             . ' data-band-pct="' . Metrics::num((float) ($metrics['mid_band_frac'] ?? Metrics::MID_BAND_FRAC) * 100.0) . '"'
             . ' data-rmin="' . self::R_MIN . '" data-rmax="' . self::R_MAX . '" data-gap="' . self::GAP . '">';
        $s[] = '<rect width="100%" height="100%" fill="#ffffff"/>';
        $s[] = '<text x="' . round($W / 2) . '" y="24" text-anchor="middle" font-size="14" font-weight="bold" fill="' . self::TEXT . '">'
             . self::esc($title) . '</text>';
        $s[] = '<rect x="' . $padL . '" y="' . $padT . '" width="' . $plotW . '" height="' . $plotH . '" fill="#fbfcfd" stroke="' . self::AXIS . '"/>';

        // Сетка и подписи оси ординат (вертикальной) — результаты теста, в
        // единицах самого теста. Выше — сильнее выражено в ответах.
        $unitMax = (float) ($unit['max'] ?? 100.0);
        $suffix = (string) ($unit['suffix'] ?? '%');
        for ($p = 0; $p <= 100; $p += 20) {
            $py = round($y((float) $p), 1);
            if ($p > 0 && $p < 100) {
                $s[] = '<line x1="' . $padL . '" y1="' . $py . '" x2="' . ($padL + $plotW) . '" y2="' . $py . '" stroke="' . self::GRID . '"/>';
            }
            $label = Metrics::num($unitMax * $p / 100.0) . ($suffix === '%' ? ' %' : '');
            $s[] = '<text x="' . ($padL - 6) . '" y="' . ($py + 3.2) . '" text-anchor="end" font-size="9" fill="' . self::DIM . '">'
                 . self::esc($label) . '</text>';
        }
        $s[] = '<text x="16" y="' . round($padT + $plotH / 2) . '" font-size="10.5" fill="' . self::TEXT
             . '" transform="rotate(-90 16 ' . round($padT + $plotH / 2) . ')" text-anchor="middle">'
             . self::esc('Результаты теста') . '</text>';

        // Сетка и подписи оси абсцисс (горизонтальной) — телесный отклик, в
        // единицах «Знач.» Эгоскопа. Правее — телесный отклик сильнее.
        if ($hasPhys) {
            foreach ([1.0, 0.5, 0.0, -0.5, -1.0] as $k) {
                $zna = $physScale * $k;
                $px = round($xZna($zna), 1);
                if ($k !== 1.0 && $k !== -1.0 && $k !== 0.0) {
                    $s[] = '<line x1="' . $px . '" y1="' . $padT . '" x2="' . $px . '" y2="' . ($padT + $plotH) . '" stroke="' . self::GRID . '"/>';
                }
                $s[] = '<text x="' . $px . '" y="' . ($padT + $plotH + 14) . '" text-anchor="middle" font-size="9" fill="' . self::DIM . '">'
                     . self::esc(Metrics::num($zna)) . '</text>';
            }
            $s[] = '<text x="' . round($padL + $plotW / 2) . '" y="' . ($padT + $plotH + 30) . '" text-anchor="middle" font-size="10.5" fill="' . self::TEXT . '">'
                 . self::esc('Телесный отклик') . '</text>';
        } else {
            $s[] = '<text x="' . round($padL + $plotW / 2) . '" y="' . ($padT + $plotH + 30) . '" text-anchor="middle" font-size="10.5" fill="' . self::DIM . '">'
                 . self::esc('Телесный отклик — нет данных') . '</text>';
        }

        // Пороги: бледный пунктир, по которому код отсекает средние показатели
        // от высоких (результаты теста) и телесный отклик от его отсутствия
        // (физиология). На странице результата этот пунктир можно таскать
        // мышью — отсюда классы и подписи со значением (interactiveHtml).
        foreach ([['low', $lowPct], ['high', $highPct]] as [$key, $pct]) {
            $py = round($y($pct), 1);
            $s[] = '<g class="mx-thr mx-thr-h" data-key="' . $key . '">'
                 . '<line class="mx-thr-hit" x1="' . $padL . '" y1="' . $py . '" x2="' . ($padL + $plotW) . '" y2="' . $py
                 . '" stroke="transparent" stroke-width="10"/>'
                 . '<line class="mx-thr-line" x1="' . $padL . '" y1="' . $py . '" x2="' . ($padL + $plotW) . '" y2="' . $py
                 . '" stroke="' . self::DASH . '" stroke-width="1.2" stroke-dasharray="4 4"/>'
                 . '<text class="mx-thr-val" x="' . ($padL + $plotW - 3) . '" y="' . ($py - 3) . '" text-anchor="end" font-size="8.5" fill="'
                 . self::DIM . '">' . self::esc(Metrics::num($pct) . ' %') . '</text></g>';
        }
        if ($hasPhys) {
            foreach ([['band+', $midBand], ['band-', -$midBand]] as [$key, $zna]) {
                $px = round($xZna($zna), 1);
                $s[] = '<g class="mx-thr mx-thr-v" data-key="' . $key . '">'
                     . '<line class="mx-thr-hit" x1="' . $px . '" y1="' . $padT . '" x2="' . $px . '" y2="' . ($padT + $plotH)
                     . '" stroke="transparent" stroke-width="10"/>'
                     . '<line class="mx-thr-line" x1="' . $px . '" y1="' . $padT . '" x2="' . $px . '" y2="' . ($padT + $plotH)
                     . '" stroke="' . self::DASH . '" stroke-width="1.2" stroke-dasharray="4 4"/>'
                     . '<text class="mx-thr-val" x="' . ($px + 3) . '" y="' . ($padT + 10) . '" font-size="8.5" fill="' . self::DIM . '">'
                     . self::esc(Metrics::num($zna)) . '</text></g>';
            }
            $px0 = round($xZna(0.0), 1);
            $s[] = '<line x1="' . $px0 . '" y1="' . $padT . '" x2="' . $px0 . '" y2="' . ($padT + $plotH)
                 . '" stroke="' . self::MEDIAN . '" stroke-width="1.3" stroke-dasharray="6 4"/>';
            $s[] = '<text x="' . ($px0 + 4) . '" y="' . ($padT + 10) . '" font-size="9" fill="' . self::MEDIAN . '">медиана</text>';
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
            $py = $y((float) $a['cog_pct']);
            $px = $hasPhys && $a['phys_pct'] !== null ? $x((float) $a['phys_pct']) : $padL + $plotW / 2.0;
            $r = self::radius((float) $a['weight'], $contrast, $wMin, $wMax);
            $points[] = ['a' => $a, 'x0' => $px, 'y0' => $py, 'x' => $px, 'y' => $py, 'r' => $r,
                         'nodata' => $hasPhys && $a['phys_pct'] === null];
        }
        // Крупные кружки рисуем первыми, чтобы мелкие не пропадали под ними, а
        // подписи получали место в порядке значимости показателя.
        usort($points, static fn ($p, $q) => (float) $q['a']['weight'] <=> (float) $p['a']['weight']);
        // Близкие значения дают наложение кружков вплоть до полного перекрытия
        // (заказчик прислал такую матрицу: пять мотивов в одной точке). Разводим
        // их в стороны — показатель остаётся на своём месте с точностью до
        // сдвига, но виден каждый (#10).
        $points = self::spread($points, $padL, $padL + $plotW, $padT, $padT + $plotH);

        $bubbles = array_map(static fn ($p) => [$p['x'] - $p['r'], $p['y'] - $p['r'], $p['x'] + $p['r'], $p['y'] + $p['r']], $points);
        foreach ($points as $p) {
            $a = $p['a'];
            $st = self::bubbleStyle($a);
            $isSig = !empty($a['sig']);
            $cx = round($p['x'], 1); $cy = round($p['y'], 1); $r = round($p['r'], 1);
            // Секторы печатаем ВСЕГДА, когда СМК распознан, даже если сейчас
            // кружок серый или одноцветный. Раньше у фоновых показателей секторов
            // в разметке не было совсем, и опущенный порог не мог их вернуть:
            // кружок так и оставался серым (#8).
            $sectors = self::sectorPaths((string) $a['smk']);
            $dash = $p['nodata'] ? ' stroke-dasharray="3 3"' : '';
            // Атрибуты для интерактивного слоя: математика всегда, интерпретация —
            // если она сопоставлена (interactiveHtml её подставит).
            $detail = self::esc(self::detailText($a));
            $interpAttr = isset($interp[$a['label']]) && $interp[$a['label']] !== ''
                ? ' data-interp="' . self::esc((string) $interp[$a['label']]) . '"' : '';
            $s[] = '<g class="mx-bubble" data-n="' . (int) $a['n'] . '" data-label="' . self::esc((string) $a['label'])
                 . '" data-detail="' . $detail . '"' . $interpAttr
                 // Истинные координаты (x0/y0) храним отдельно от текущих: при
                 // смене контраста интерактивный слой разводит кружки заново от
                 // них, иначе сдвиги накапливались бы с каждой правкой.
                 . ' data-x0="' . round($p['x0'], 1) . '" data-y0="' . round($p['y0'], 1) . '"'
                 . ' data-cx="' . $cx . '" data-cy="' . $cy . '" data-r="' . $r
                 // Вес — с полной точностью: Metrics::num() округлил бы до
                 // десятых, и кружки в интерактиве получались бы не того размера,
                 // что на статичной картинке в PDF.
                 . '" data-weight="' . self::num3((float) $a['weight'])
                 . '" data-cog="' . Metrics::num((float) $a['cog_pct']) . '" data-zna="' . ($a['zna'] === null ? '' : Metrics::num((float) $a['zna']))
                 . '" data-sig="' . ($isSig ? '1' : '0') . '" data-dom="' . self::dominantColor($a)
                 . '" data-pale="' . ($st['mode'] === 'flat' ? '1' : '0') . '">';
            $s[] = '<title>' . $detail . '</title>';
            $s[] = '<g class="mx-shape" transform="translate(' . $cx . ' ' . $cy . ') scale(' . $r . ')">';
            // Сплошной кружок — фон, одноцветный показатель (ниже медианы) или
            // нераспознанный СМК; секторы — когда тело откликается выше медианы.
            $s[] = '<circle class="mx-plain" cx="0" cy="0" r="1" fill="' . $st['color'] . '" fill-opacity="'
                 . $st['opacity'] . '"' . ($st['mode'] === 'sectors' ? ' display="none"' : '') . '/>';
            if ($sectors) {
                // Кружок разделён на секторы X / Y / Z: больший сектор —
                // преобладающий параметр СМК. Параметров, которых в токене нет,
                // на кружке нет тоже (#11).
                $s[] = '<g class="mx-sectors"' . ($st['mode'] === 'sectors' ? '' : ' display="none"') . '>';
                foreach ($sectors as [$path, $col]) {
                    $s[] = '<path d="' . $path . '" fill="' . $col . '" fill-opacity="0.82" stroke="#ffffff"'
                         . ' stroke-width="0.6" vector-effect="non-scaling-stroke"/>';
                }
                $s[] = '</g>';
            }
            $s[] = '<circle class="mx-ring" cx="0" cy="0" r="1" fill="none" stroke="' . $st['ring'] . '" stroke-width="' . $st['width']
                 . '" vector-effect="non-scaling-stroke"' . $dash . '/>';
            $s[] = '</g>';
            // Номер шкалы: внутри кружка, если он достаточно велик, иначе рядом.
            // Рисуем оба варианта и показываем нужный — при смене контраста
            // интерактивный слой просто переключает их видимостью.
            $s[] = '<text class="mx-num-in" x="' . $cx . '" y="' . round($p['y'] + 3.4, 1) . '" text-anchor="middle" font-size="9.5"'
                 . ' font-weight="bold" fill="' . ($st['mode'] === 'flat' ? self::TEXT : '#ffffff') . '"'
                 . ' pointer-events="none"' . ($r >= 8.5 ? '' : ' display="none"') . '>' . (int) $a['n'] . '</text>';
            $s[] = '<text class="mx-num-out" x="' . round($p['x'] - $p['r'] - 2, 1) . '" y="' . round($p['y'] - $p['r'] - 1, 1)
                 . '" text-anchor="end" font-size="8" font-weight="bold" fill="' . $st['color'] . '" pointer-events="none"'
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
                $s[] = '<text class="mx-name" data-n="' . (int) $a['n'] . '" data-dy="' . round($ty - $p['y'], 1)
                     . '" data-side="' . $anchor . '" x="' . round($tx, 1) . '" y="' . round($ty + 3.2, 1)
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
            $s[] = match ($c) {
                // Достоверность и расхождение — это обводка, а не заливка: в
                // легенде они показаны пустым кружком с той же обводкой.
                null    => '<circle cx="' . ($lx + 5) . '" cy="' . ($ly - 3) . '" r="4.5" fill="#ffffff" stroke="#2a3138" stroke-width="2.6"/>',
                'alert' => '<circle cx="' . ($lx + 5) . '" cy="' . ($ly - 3) . '" r="4.5" fill="#ffffff" stroke="' . self::C_ALERT . '" stroke-width="2.4"/>',
                default => '<circle cx="' . ($lx + 5) . '" cy="' . ($ly - 3) . '" r="5" fill="' . $c . '" fill-opacity="0.72" stroke="' . $c . '"/>',
            };
            $s[] = '<text x="' . ($lx + 14) . '" y="' . $ly . '" font-size="9.5"' . ($c === null ? ' font-weight="bold"' : '')
                 . ' fill="' . self::TEXT . '">' . self::esc($label) . '</text>';
            $lx += $width + 10;
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
  <label title="0 — все кружки одного размера; 1 — естественный разброс; больше — разница подчёркнута">Контраст размеров<input
      type="number" class="mx-f" data-k="power" min="0" max="6" step="0.1" value="<?= $n((float)($metrics['size_contrast'] ?? Metrics::SIZE_CONTRAST)) ?>"></label>
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
      RMIN=+D.rmin, RMAX=+D.rmax, GAP=+D.gap, WMIN=+D.wmin, WMAX=+D.wmax,
      C_FLAT='<?= self::C_FLAT ?>', C_TEXT='<?= self::TEXT ?>', C_ALERT='<?= self::C_ALERT ?>',
      MID_BAND_MIN=<?= Metrics::MID_BAND_MIN ?>,
      // Зеркало Metrics::categoryFor(): у методик со слиянием категорий
      // (Басса-Дарки) низкий показатель у самой медианы — тоже непроявленный.
      MERGE_MID=<?= in_array('variable', Metrics::CATEGORY_MERGE_BY_TEST[(string) ($metrics['test_key'] ?? '')] ?? [], true) ? 'true' : 'false' ?>,
      saveUrl=<?= json_encode($saveUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  var st={low:+D.low, high:+D.high, band:+D.bandPct, power:+D.contrast};
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
  /* Зеркало Metrics::zone() и Metrics::category(): из отчёта выпадают только те
     показатели, что ниже ОБОИХ порогов оператора — и по ответам теста, и по
     полосе медианы. */
  function zone(lvl, ps){
    if(ps===null) return 'unknown';
    if(ps==='above') return lvl==='high' ? 'both' : (lvl==='mid' ? 'body' : 'against');
    return lvl==='high' ? 'mind' : (lvl==='mid' ? 'middle' : 'flat');
  }
  function isSkip(lvl, ps){
    if(ps===null) return lvl==='low';
    if(ps==='median') return MERGE_MID && lvl==='low';
    return ps==='below' && lvl==='low';
  }
  function radius(w){
    var span=WMAX-WMIN, t=span>1e-9 ? (w-WMIN)/span : 0.5;
    t=0.5+(t-0.5)*st.power;
    return RMIN+(RMAX-RMIN)*Math.max(0,Math.min(1,t));
  }

  var names={};
  svg.querySelectorAll('.mx-name').forEach(function(t){ names[t.dataset.n]=t; });
  var bubbles=[].slice.call(svg.querySelectorAll('.mx-bubble'));

  /* Разведение наложений — зеркало Matrix::spread(). Стартуем всегда от истинных
     координат (x0/y0), чтобы сдвиги не накапливались от правки к правке. */
  function spread(pts){
    var n=pts.length, i, j, k;
    if(n<2) return pts;
    for(k=0;k<60;k++){
      var moved=false;
      for(i=0;i<n;i++) for(j=i+1;j<n;j++){
        var dx=pts[j].x-pts[i].x, dy=pts[j].y-pts[i].y, need=pts[i].r+pts[j].r+GAP;
        if(Math.sqrt(dx*dx+dy*dy)>=need) continue;
        // Направление — по истинным координатам, чтобы кружки не менялись
        // местами (зеркало Matrix::spread()).
        var dx0=pts[j].x0-pts[i].x0, dy0=pts[j].y0-pts[i].y0,
            d0=Math.sqrt(dx0*dx0+dy0*dy0);
        if(d0<0.01){ var ang=2*Math.PI*i/n; dx0=Math.cos(ang); dy0=Math.sin(ang); d0=1; }
        var ux=dx0/d0, uy=dy0/d0, push=(need-(dx*ux+dy*uy))/2*0.6;
        if(push<=0) continue;
        pts[i].x-=ux*push; pts[i].y-=uy*push;
        pts[j].x+=ux*push; pts[j].y+=uy*push;
        moved=true;
      }
      for(i=0;i<n;i++){
        pts[i].x+=(pts[i].x0-pts[i].x)*0.05;
        pts[i].y+=(pts[i].y0-pts[i].y)*0.05;
        pts[i].x=Math.max(padL+pts[i].r, Math.min(padL+plotW-pts[i].r, pts[i].x));
        pts[i].y=Math.max(padT+pts[i].r, Math.min(padT+plotH-pts[i].r, pts[i].y));
      }
      if(!moved) break;
    }
    return pts;
  }

  function paint(){
    var pts=bubbles.map(function(g){
      return {g:g, x0:+g.dataset.x0, y0:+g.dataset.y0, x:+g.dataset.x0, y:+g.dataset.y0,
              w:+g.dataset.weight, r:radius(+g.dataset.weight)};
    });
    // Тяжёлые первыми — тот же порядок расталкивания, что и на сервере (по весу,
    // а не по радиусу: при контрасте 0 радиусы равны и порядок был бы случайным).
    pts.sort(function(a,b){ return b.w-a.w; });
    spread(pts);
    pts.forEach(function(p){
      var g=p.g,
          zna=g.dataset.zna===''?null:+g.dataset.zna,
          sig=g.dataset.sig==='1',
          lvl=level(+g.dataset.cog), ps=physState(zna),
          pale=isSkip(lvl, ps), zn=zone(lvl, ps),
          dom=g.dataset.dom || C_FLAT,
          r=p.r, cx=p.x, cy=p.y,
          sect=g.querySelector('.mx-sectors'),
          plain=g.querySelector('.mx-plain'),
          ring=g.querySelector('.mx-ring'),
          inN=g.querySelector('.mx-num-in'),
          outN=g.querySelector('.mx-num-out'),
          // Секторы — только там, где тело откликается выше медианы; ниже
          // медианы кружок одноцветный, преобладающим параметром.
          sectors=!pale && !!sect && (zn==='both'||zn==='body'||zn==='against'),
          fill=pale?C_FLAT:dom;
      g.dataset.pale=pale?'1':'0';
      g.dataset.cx=num(cx); g.dataset.cy=num(cy); g.dataset.r=num(r);
      g.querySelector('.mx-shape').setAttribute('transform','translate('+num(cx)+' '+num(cy)+') scale('+num(r)+')');
      if(sect) sect.setAttribute('display', sectors?'inline':'none');
      plain.setAttribute('display', sectors?'none':'inline');
      plain.setAttribute('fill', fill);
      plain.setAttribute('fill-opacity', pale?'0.5':'0.72');
      ring.setAttribute('stroke', zn==='against' ? C_ALERT : (sig ? '#2a3138' : (sectors ? '#ffffff' : fill)));
      ring.setAttribute('stroke-width', sig ? '2.6' : (zn==='against' ? '2.2' : '1.2'));
      inN.setAttribute('display', r>=8.5?'inline':'none');
      inN.setAttribute('fill', pale?C_TEXT:'#ffffff');
      inN.setAttribute('x', num(cx)); inN.setAttribute('y', num(cy+3.4));
      outN.setAttribute('display', r>=8.5?'none':'inline');
      outN.setAttribute('x', num(cx-r-2)); outN.setAttribute('y', num(cy-r-1));
      outN.setAttribute('fill', fill);
      var nm=names[g.dataset.n];
      if(nm){
        // Подпись едет вместе со своим кружком: место ей подобрал сервер, но
        // после расталкивания и смены контраста кружок стоит в другой точке.
        nm.setAttribute('display', pale?'none':'inline');
        nm.setAttribute('x', num(nm.dataset.side==='start' ? cx+r+4 : cx-r-4));
        nm.setAttribute('y', num(cy+(+nm.dataset.dy||0)+3.2));
      }
    });
  }

  function drawLines(){
    svg.querySelectorAll('.mx-thr').forEach(function(g){
      var k=g.dataset.key, val=g.querySelector('.mx-thr-val');
      if(k==='low'||k==='high'){
        // Результаты теста — вертикальная ось: 0 % снизу, 100 % сверху (зеркало $y()).
        var py=padT+plotH*(1-Math.max(0,Math.min(100,st[k]))/100);
        g.querySelectorAll('line').forEach(function(l){ l.setAttribute('y1',num(py)); l.setAttribute('y2',num(py)); });
        val.setAttribute('y', num(py-3)); val.textContent=num(st[k])+' %';
      } else if(physScale>0){
        // Телесный отклик — горизонтальная ось: медиана по центру (зеркало $xZna()).
        var z=(k==='band+'?1:-1)*band(),
            px=padL+plotW*(0.5+0.5*z/physScale);
        g.querySelectorAll('line').forEach(function(l){ l.setAttribute('x1',num(px)); l.setAttribute('x2',num(px)); });
        val.setAttribute('x', num(px+3)); val.textContent=num(z);
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
          // Пороги решают не только цвет кружка, но и раздел отчёта в таблице
          // ниже — она рисуется на сервере, поэтому её саму по себе не
          // перекрасить. Раньше страница ждала, что оператор заметит и нажмёт
          // ссылку «Пересчитать»; теперь перезагружаем сами, как только правка
          // на 500 мс затихла (тот же debounce, что и у самого сохранения) —
          // раздел отчёта в таблице обновляется без лишнего клика.
          state.textContent='Сохранено, обновляю раздел отчёта…';
          location.reload();
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
          // Результаты теста — вертикальная ось, тянем по uy (зеркало $y()).
          st[k]=Math.max(1, Math.min(99, (padT+plotH-uy)/plotH*100));
          if(k==='low') st.low=Math.min(st.low, st.high-1); else st.high=Math.max(st.high, st.low+1);
        } else {
          // Телесный отклик — горизонтальная ось, тянем по ux (зеркало $xZna()).
          var z=(ux-(padL+plotW/2))/(plotW/2)*physScale;
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
     * Порядок параметров СМК: «Z*>X>Y» → ['Z','X','Y']. Буквы, которых в токене
     * НЕТ, не дописываются: если параметр в выгрузке не назван, его цвета на
     * кружке быть не должно (#11). Пустой токен → [].
     */
    private static function smkOrder(string $smk): array {
        $norm = str_replace(['Х', 'х', 'У', 'у', 'Ζ', 'x', 'y', 'z'], ['X', 'X', 'Y', 'Y', 'Z', 'X', 'Y', 'Z'], $smk);
        $order = [];
        foreach (preg_split('//u', $norm, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            if (in_array($ch, ['X', 'Y', 'Z'], true) && !in_array($ch, $order, true)) $order[] = $ch;
        }
        return $order;
    }

    /**
     * Доли секторов по числу названных параметров. Первый параметр всегда самый
     * крупный; если параметр один — кружок целиком его цвета.
     */
    private const SECTOR_WEIGHTS = [
        1 => [1.0],
        2 => [0.62, 0.38],
        3 => [0.5, 0.33, 0.17],
    ];

    /**
     * Цвет по букве доминирующего параметра СМК (или C_NONE, если параметр не
     * распознан) — публичный, чтобы диаграмма (Chart) красила точку телесного
     * отклика при наведении ровно теми же цветами, что и матрица (#4).
     */
    public static function colorForLetter(string $letter): string {
        return match ($letter) {
            'Y' => self::C_Y,
            'X' => self::C_X,
            'Z' => self::C_Z,
            default => self::C_NONE,
        };
    }

    /**
     * Секторы кружка по порядку СМК: первый параметр — самый большой сектор.
     * Доли по рангу наглядно показывают преобладание, при этом кружок честно
     * разделён между НАЗВАННЫМИ параметрами. Возвращает пары [path, color].
     *
     * Путь строится в ЕДИНИЧНОМ круге с центром в начале координат: на место его
     * ставит transform="translate(cx cy) scale(r)" у группы кружка, поэтому смена
     * контраста размеров не требует пересчёта секторов.
     *
     * Публичный: диаграмма (Chart) переиспользует его для цветовой разбивки
     * точки телесного отклика при наведении (#4) — те же секторы, что на матрице.
     *
     * @return array<int, array{0:string,1:string}>
     */
    public static function sectorPaths(string $smk): array {
        $order = self::smkOrder($smk);
        if ($order === []) return [];
        $weights = self::SECTOR_WEIGHTS[count($order)] ?? self::SECTOR_WEIGHTS[3];
        $colors = ['X' => self::C_X, 'Y' => self::C_Y, 'Z' => self::C_Z];
        $out = [];
        $a0 = -M_PI / 2;            // старт сверху
        foreach ($order as $k => $letter) {
            $frac = $weights[$k] ?? 0.0;
            if ($frac <= 0) continue;
            // Один параметр — целый круг: дуга в 360° через SVG-arc не рисуется
            // (начало совпадает с концом), поэтому это просто circle.
            if ($frac >= 0.999) {
                $out[] = ['M 0 -1 A 1 1 0 1 1 0 1 A 1 1 0 1 1 0 -1 Z', $colors[$letter] ?? self::C_NONE];
                break;
            }
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
     * Как выглядит кружок — одна таблица на всю матрицу (её зеркалит JS в
     * interactiveHtml, ровно как таблицу категорий Metrics):
     *
     *  - ниже обоих порогов («skip») — бледно-серый, в отчёте не разбирается;
     *  - тело откликается выше медианы — секторы СМК: видно состав реакции;
     *  - тело ниже медианы (или у медианы), но ответы выше порога — ОДИН цвет,
     *    преобладающий параметр, без секторальной разбивки (#6);
     *  - СМК не распознан — фиолетовый, чтобы отсутствие данных не читалось как
     *    ещё один нейтральный показатель (#7);
     *  - ответы ниже порога, а тело откликается — красная обводка: это
     *    расхождение, левый верхний угол матрицы (#9).
     *
     * @return array{mode:string,color:string,opacity:string,ring:string,width:string}
     */
    private static function bubbleStyle(array $a): array {
        $zone = (string) ($a['matrix_zone'] ?? '');
        $sig = !empty($a['sig']);
        if (self::isPale($a)) {
            return ['mode' => 'flat', 'color' => self::C_FLAT, 'opacity' => '0.5',
                    'ring' => self::C_FLAT, 'width' => '1.2'];
        }
        $dominant = self::dominantColor($a);
        $hasSectors = self::smkOrder((string) ($a['smk'] ?? '')) !== [];
        // Секторы — только там, где тело откликается выше медианы: состав реакции
        // осмыслен, когда реакция есть.
        $sectors = $hasSectors && in_array($zone, ['both', 'body', 'against'], true);
        $ring = match (true) {
            $zone === 'against' => self::C_ALERT,
            $sig                => '#2a3138',
            $sectors            => '#ffffff',
            default             => $dominant,
        };
        return [
            'mode' => $sectors ? 'sectors' : 'solid',
            'color' => $dominant,
            'opacity' => '0.72',
            'ring' => $ring,
            'width' => $sig ? '2.6' : ($zone === 'against' ? '2.2' : '1.2'),
        ];
    }

    /**
     * Разводит наложившиеся кружки (#10). Значения показателей бывают очень
     * близкими — на присланной заказчиком матрице пять мотивов сливались в одно
     * пятно, и прочитать оттуда было нечего. Кружки расталкиваются попарно и
     * пружиной тянутся обратно к своей истинной точке, поэтому смещение
     * минимально: показатель остаётся «там же», но виден отдельно.
     *
     * @param array $points [['x'=>float,'y'=>float,'r'=>float,…], …]
     */
    private static function spread(array $points, float $minX, float $maxX, float $minY, float $maxY): array {
        $n = count($points);
        if ($n < 2) return $points;
        for ($iter = 0; $iter < 60; $iter++) {
            $moved = false;
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $need = $points[$i]['r'] + $points[$j]['r'] + self::GAP;
                    $dx = $points[$j]['x'] - $points[$i]['x'];
                    $dy = $points[$j]['y'] - $points[$i]['y'];
                    if (sqrt($dx * $dx + $dy * $dy) >= $need) continue;
                    // Направление расталкивания берём по ИСТИННЫМ координатам, а
                    // не по текущим: иначе пара могла перескочить друг через
                    // друга, и показатель со «Знач.» −5 оказывался НИЖЕ соседа
                    // со «Знач.» −7 — картинка врала о порядке.
                    $dx0 = $points[$j]['x0'] - $points[$i]['x0'];
                    $dy0 = $points[$j]['y0'] - $points[$i]['y0'];
                    $d0 = sqrt($dx0 * $dx0 + $dy0 * $dy0);
                    if ($d0 < 0.01) {  // точное совпадение — расталкиваем по кругу
                        $ang = 2 * M_PI * $i / max(1, $n);
                        $dx0 = cos($ang); $dy0 = sin($ang); $d0 = 1.0;
                    }
                    $ux = $dx0 / $d0; $uy = $dy0 / $d0;
                    // Сколько не хватает ВДОЛЬ этого направления.
                    $proj = $dx * $ux + $dy * $uy;
                    $push = ($need - $proj) / 2 * 0.6;
                    if ($push <= 0) continue;
                    $points[$i]['x'] -= $ux * $push; $points[$i]['y'] -= $uy * $push;
                    $points[$j]['x'] += $ux * $push; $points[$j]['y'] += $uy * $push;
                    $moved = true;
                }
            }
            // Пружина к истинной точке + удержание внутри поля.
            foreach ($points as $k => $p) {
                $points[$k]['x'] += ($p['x0'] - $p['x']) * 0.05;
                $points[$k]['y'] += ($p['y0'] - $p['y']) * 0.05;
                $points[$k]['x'] = max($minX + $p['r'], min($maxX - $p['r'], $points[$k]['x']));
                $points[$k]['y'] = max($minY + $p['r'], min($maxY - $p['r'], $points[$k]['y']));
            }
            if (!$moved) break;
        }
        return $points;
    }

    /** Число с тремя знаками после запятой без хвостовых нулей (вес показателя). */
    private static function num3(float $v): string {
        return rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.') ?: '0';
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

    /**
     * Цвет по доминирующему параметру СМК — БЕЗ оглядки на то, разбирается ли
     * показатель в отчёте. Именно он уходит в разметку (data-dom): опущенный
     * порог возвращает серому кружку цвет, а взять его больше неоткуда. Раньше
     * в атрибут писался итоговый цвет стиля, то есть у фоновых — серый, и
     * кружок, ставший рабочим, оставался серым навсегда (#8).
     */
    private static function dominantColor(array $axis): string {
        return match ((string) ($axis['dominant'] ?? '')) {
            'Y' => self::C_Y,
            'X' => self::C_X,
            'Z' => self::C_Z,
            default => self::C_NONE,
        };
    }

    /** Цвет кружка сейчас: бледно-серый для фоновых, иначе — по СМК. */
    private static function color(array $axis): string {
        return self::isPale($axis) ? self::C_FLAT : self::dominantColor($axis);
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
