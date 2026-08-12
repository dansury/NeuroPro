<?php
/**
 * Mathematical radar ("spider") chart generator — pure geometry, no AI.
 *
 * Renders the cognitive parameters (12 for СМУ, 8 for LSI/Басса-Дарки) as a polygon
 * and, when physiological data from the OCR'd "смысло-эмоциональная значимость"
 * screenshot is supplied, overlays it as a second polygon on the same axes —
 * exactly the "наложить физиологические на когнитивные" requirement.
 *
 * ОБЩАЯ ШКАЛА. Раньше оба полигона рисовались по «сырым» значениям на кольцах,
 * подписанных баллами (1…13): когнитивные баллы шкал с разными максимумами
 * (у Басса-Дарки 5…13) шли по одной шкале, а физиология — по третьей, своей,
 * подобранной по максимуму текущего профиля. Три разных смысла на одном радиусе.
 * Теперь радиус — это ПРОЦЕНТ, единый для обеих кривых:
 *   - когниция: доля от максимума ИМЕННО ЭТОЙ шкалы (0…100 %);
 *   - физиология: «Знач.» на симметричной шкале ±phys_scale, медиана (0) —
 *     на кольце 50 %, наружу — напряжение, к центру — его отсутствие.
 * Кольцо 50 % подписано как медиана физиологии, предел шкалы — в легенде.
 *
 * Оси с p<0.05 выделяются жирной точкой. Оси без данных честно пропускаются:
 * полигон физиологии рвётся, а не замыкается через пропуск (никаких
 * точек-заглушек и ложных рёбер — Конституция, принцип II).
 *
 * Output is a standalone SVG string (crisp at any size, embeds straight into the
 * web page and the branded PDF). Font is Verdana throughout. Сетка — круговая,
 * по визуальному референсу (Sources/diagram_reference.jpg).
 */

require_once __DIR__ . '/metrics.php';
// Секторы кружка (Matrix::sectorPaths) — точка телесного отклика при наведении
// красится ровно теми же цветами СМК, что и матрица (#4), одной таблицей.
require_once __DIR__ . '/matrix.php';

final class Chart {
    // Ответы теста — синие, физиологический ответ — оранжевый (по просьбе
    // заказчика; синий совпадает с осью X матрицы, оранжевый — с параметром Y).
    private const COG_STROKE = '#2f6fd0';   // cognitive polygon (blue)
    private const COG_FILL   = '#2f6fd0';
    private const COG_OPACITY = '0.16';
    private const COG_TEXT   = '#24549c';   // подписи баллов у точек
    private const PHYS_STROKE = '#e8850c';  // physiological polygon (orange)
    private const PHYS_FILL  = '#e8850c';
    private const PHYS_OPACITY = '0.20';
    private const PHYS_TEXT  = '#b8660a';   // подписи Знач. у точек
    private const GRID       = '#e2e7ec';
    private const GRID_OUTER = '#c9d2da';
    private const MEDIAN     = '#9fb4bd';   // кольцо медианы физиологии
    private const AXIS       = '#d7dde3';
    private const TEXT       = '#2a3138';

    /** Кольца сетки: единая процентная шкала для обеих кривых. */
    private const RINGS = 5;

    /**
     * Диаграмма прямо из расчёта Metrics::build() — предпочтительный вход:
     * нормировка, шкала физиологии и достоверность уже посчитаны там.
     */
    public static function fromMetrics(array $metrics, array $opts = []): string {
        $labels = $cog = $physVals = $sig = $scores = $smk = [];
        // Единицы когнитивной оси — «как в самом тесте»: у ИЖС это проценты, у
        // СМУ баллы 0…10, у Басса-Дарки максимумы шкал разные, поэтому балл без
        // своего максимума не читается.
        $unit = $metrics['unit'] ?? [];
        $sameMax = ($unit['suffix'] ?? '%') !== '%';
        foreach ($metrics['axes'] as $a) {
            $labels[]   = $a['short'];
            $cog[]      = $a['pct'];                 // уже % от максимума своей шкалы
            $physVals[] = $a['zna'];
            $sig[]      = $a['sig'];
            $smk[]      = (string) ($a['smk'] ?? '');
            // Результат теста у подписи оси — для ЛЮБОЙ методики, а не только у
            // ИЖС, где он совпадал с процентом на кольцах (#3).
            $scores[]   = ($metrics['test_key'] ?? '') === 'lsi'
                ? self::num((float) $a['pct']) . ' %'
                : ($sameMax
                    ? self::num((float) $a['score'])
                    : self::num((float) $a['score']) . ' из ' . (int) $a['scale_max']);
        }
        return self::render($labels, $cog, $physVals, $sig, $opts + [
            'phys_scale' => $metrics['phys_scale'] ?? 0.0,
            'axis_values' => $scores,
            'smk' => $smk,
        ]);
    }

    /**
     * Совместимый вход по «сырым» значениям.
     *
     * @param array  $labels      axis labels (clockwise from top)
     * @param array  $cognitive   numeric values aligned with $labels
     * @param int    $max         единый максимум шкалы (используется, если не
     *                            передан opts['scale_maxes'] — пошкальные максимумы)
     * @param array|null $phys    optional physiological values aligned with
     *                            $labels (signed "Знач."); null entries skipped
     * @param array  $opts        size, title, scale_maxes, phys_scale, phys_sig
     */
    public static function svg(array $labels, array $cognitive, int $max = 10, ?array $phys = null, array $opts = []): string {
        $maxes = $opts['scale_maxes'] ?? [];
        $pct = [];
        foreach ($labels as $i => $_) {
            $m = (float) ($maxes[$i] ?? $max);
            $v = (float) ($cognitive[$i] ?? 0);
            $pct[] = $m > 0 ? $v / $m * 100.0 : 0.0;
        }
        $scale = (float) ($opts['phys_scale'] ?? 0.0);
        if ($scale <= 0 && $phys !== null) $scale = Metrics::physScale($phys);
        return self::render($labels, $pct, $phys ?? [], $opts['phys_sig'] ?? [], $opts + ['phys_scale' => $scale]);
    }

    /**
     * @param array $labels подписи осей
     * @param array $cogPct когниция в % от максимума своей шкалы (0…100)
     * @param array $phys   «Знач.» по осям (null = нет данных)
     * @param array $sig    p<0.05 по осям
     */
    private static function render(array $labels, array $cogPct, array $phys, array $sig, array $opts): string {
        $n = count($labels);
        if ($n < 3) return '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"></svg>';

        $size  = (int) ($opts['size'] ?? 560);
        $title = (string) ($opts['title'] ?? '');
        $physScale = (float) ($opts['phys_scale'] ?? 0.0);
        // Подписи процентов у когнитивных точек по умолчанию выключены: кольца
        // теперь подписаны в процентах, а точные баллы есть в таблице под
        // диаграммой — иначе на горизонтальных осях подпись балла наезжает на
        // подпись «Знач.» соседней кривой.
        $showCog = (bool) ($opts['show_cog_values'] ?? false);
        $cx = $size / 2;
        $cy = $size / 2 + 2;
        $R  = $size * 0.30;            // outer radius (room for labels)
        $labelR = $R + $size * 0.055;

        $ang = static fn (int $i) => -M_PI / 2 + 2 * M_PI * $i / $n;
        $pt  = static function (int $i, float $frac) use ($ang, $cx, $cy, $R) {
            $a = $ang($i);
            return [$cx + $R * $frac * cos($a), $cy + $R * $frac * sin($a)];
        };

        // Легенда получает СВОЮ полосу высоты под картинкой, а не делит место
        // с диаграммой: раньше подпись нижней оси и первая строка легенды
        // считались от одного и того же $size и на уменьшенных картинках
        // накладывались друг на друга (#5).
        $legendPad = 24;
        $imgH = $size + $legendPad;
        $svg = [];
        $svg[] = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $imgH . '" '
               . 'viewBox="0 0 ' . $size . ' ' . $imgH . '" font-family="Verdana, Geneva, sans-serif">';
        $svg[] = '<rect width="100%" height="100%" fill="#ffffff"/>';
        if ($title !== '') {
            $svg[] = '<text x="' . $cx . '" y="26" text-anchor="middle" font-size="16" font-weight="bold" fill="' . self::TEXT . '">' . self::esc($title) . '</text>';
        }

        // Круговая сетка (по референсу): заливка-подложка + кольца через 20 %.
        $svg[] = '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . round($R, 1) . '" fill="#fbfcfd" stroke="none"/>';
        for ($g = 1; $g <= self::RINGS; $g++) {
            $frac = $g / self::RINGS;
            $isOuter = $g === self::RINGS;
            $svg[] = '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . round($R * $frac, 1) . '" fill="none" stroke="'
                   . ($isOuter ? self::GRID_OUTER : self::GRID) . '" stroke-width="' . ($isOuter ? '1.6' : '1') . '"/>';
            $svg[] = '<text x="' . round($cx + 4) . '" y="' . round($cy - $R * $frac + 3.5) . '" font-size="9" fill="#9aa4ad">'
                   . round($frac * 100) . '%</text>';
        }

        // Axes + outer labels.
        for ($i = 0; $i < $n; $i++) {
            [$ex, $ey] = $pt($i, 1.0);
            $svg[] = '<line x1="' . $cx . '" y1="' . $cy . '" x2="' . round($ex, 1) . '" y2="' . round($ey, 1) . '" stroke="' . self::AXIS . '" stroke-width="1"/>';
            $a = $ang($i);
            $lx = $cx + $labelR * cos($a);
            $ly = $cy + $labelR * sin($a);
            $anchor = abs(cos($a)) < 0.25 ? 'middle' : (cos($a) > 0 ? 'start' : 'end');
            // Сколько места остаётся подписи до края картинки: у боковых осей это
            // расстояние до края, у верхней/нижней — вдвое меньшее из двух.
            $room = match ($anchor) {
                'start' => $size - $lx - 3,
                'end'   => $lx - 3,
                default => 2 * min($lx, $size - $lx) - 6,
            };
            // Достоверное отклонение выделяем и на подписи оси, а не только точкой:
            // так шкалы с p<0.05 видно с первого взгляда (#5). Под подписью —
            // сам результат теста по этой шкале (#3).
            $svg[] = self::wrapLabel((string) $labels[$i], $lx, $ly, $anchor, !empty($sig[$i]), (float) $room,
                (string) ($opts['axis_values'][$i] ?? ''));
        }

        // Physiological overlay first (drawn under cognitive so the line reads on top).
        $physPts = [];   // [axisIdx => [x, y, value, significant, smk]]
        $hasPhys = false;
        $smkByAxis = (array) ($opts['smk'] ?? []);
        if ($physScale > 0) {
            for ($i = 0; $i < $n; $i++) {
                $v = $phys[$i] ?? null;
                if ($v === null) continue; // нет данных — нет точки
                // Медиана (0) — на кольце 50 %, ±phys_scale — край и центр.
                $frac = max(0.02, min(1.0, 0.5 + 0.5 * ((float) $v / $physScale)));
                [$x, $y] = $pt($i, $frac);
                $physPts[$i] = [$x, $y, (float) $v, !empty($sig[$i]), (string) ($smkByAxis[$i] ?? '')];
            }
            $hasPhys = count($physPts) > 0;
        }
        if ($hasPhys) {
            // Кольцо медианы: точка отсчёта физиологии. Без него отрицательные
            // «Знач.» у центра читаются как «низкий балл».
            $svg[] = '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . round($R * 0.5, 1) . '" fill="none" stroke="'
                   . self::MEDIAN . '" stroke-width="1.4" stroke-dasharray="5 4"/>';
            $svg[] = '<text x="' . round($cx - $R * 0.5 + 4) . '" y="' . round($cy - 5) . '" font-size="9" fill="'
                   . self::MEDIAN . '">медиана</text>';
            $svg[] = self::physShape($physPts, $n);
            foreach ($physPts as $i => [$x, $y, $v, $isSig, $ptSmk]) {
                // Точка: при p<0.05 — заметно жирнее (крупнее + обводка).
                $r = $isSig ? 6.0 : 3.6;
                // При наведении точка укрупняется и красится по составу СМК —
                // ровно теми же секторами и цветами, что кружок на матрице
                // (Matrix::sectorPaths), а не отдельной палитрой (#4).
                $hoverR = $r * 2.0;
                $sectors = Matrix::sectorPaths($ptSmk);
                $cxp = round($x, 1); $cyp = round($y, 1);
                $svg[] = '<g class="np-phys-pt">';
                $svg[] = '<circle class="np-pt-hit" cx="' . $cxp . '" cy="' . $cyp . '" r="' . max($hoverR + 4, 11) . '" fill="transparent"/>';
                $svg[] = '<circle class="np-pt-plain" cx="' . $cxp . '" cy="' . $cyp . '" r="' . $r . '" fill="' . self::PHYS_STROKE
                       . '" stroke="#ffffff" stroke-width="' . ($isSig ? '2' : '1.2') . '"/>';
                $svg[] = '<g class="np-pt-hover" transform="translate(' . $cxp . ' ' . $cyp . ') scale(' . round($hoverR, 2) . ')">';
                if ($sectors) {
                    foreach ($sectors as [$path, $col]) {
                        $svg[] = '<path d="' . $path . '" fill="' . $col . '" fill-opacity="0.92" stroke="#ffffff"'
                               . ' stroke-width="0.6" vector-effect="non-scaling-stroke"/>';
                    }
                } else {
                    // СМК не распознан — фиолетовый, как на матрице (Matrix::C_NONE):
                    // отсутствие данных не должно читаться как ещё один канал.
                    $svg[] = '<circle cx="0" cy="0" r="1" fill="' . Matrix::colorForLetter('') . '"/>';
                }
                $svg[] = '<circle cx="0" cy="0" r="1" fill="none" stroke="#ffffff" stroke-width="' . ($isSig ? '0.12' : '0.08') . '" vector-effect="non-scaling-stroke"/>';
                $svg[] = '</g>';
                $svg[] = '</g>';
                // Подпись Знач. — со смещением ВБОК от оси (по касательной), а не
                // вдоль неё: когнитивная точка всегда лежит на самой оси, и
                // радиальная подпись наезжала на неё, когда кривые сближались.
                $a = $ang($i);
                $tx = $x + 9 * cos($a) - 14 * sin($a);
                $ty = $y + 9 * sin($a) + 14 * cos($a) + 4;
                $svg[] = '<text x="' . round($tx, 1) . '" y="' . round($ty, 1) . '" text-anchor="middle" font-size="' . ($isSig ? '12' : '10') . '"'
                       . ($isSig ? ' font-weight="bold"' : '') . ' fill="' . self::PHYS_TEXT . '" pointer-events="none">' . self::num($v) . '</text>';
            }
            $svg[] = '<style>'
                   . '.np-phys-pt{cursor:pointer}'
                   . '.np-pt-hover{opacity:0;pointer-events:none}'
                   . '.np-phys-pt:hover .np-pt-hover{opacity:1}'
                   . '.np-phys-pt:hover .np-pt-plain{opacity:0}'
                   . '</style>';
        }

        // Cognitive polygon (в процентах от максимума своей шкалы).
        $poly = [];
        $cogPts = [];
        for ($i = 0; $i < $n; $i++) {
            $frac = max(0.0, min(1.0, (float) ($cogPct[$i] ?? 0) / 100.0));
            [$x, $y] = $pt($i, $frac);
            $poly[] = round($x, 1) . ',' . round($y, 1);
            $cogPts[$i] = [$x, $y];
        }
        // pointer-events:none — иначе синий полигон (рисуется поверх физиологии,
        // чтобы линия читалась сверху) перехватывал наведение на оранжевую точку
        // физиологии под собой, и разбивка по каналам СМК при ховере не
        // показывалась там, где кривые пересекались.
        $svg[] = '<polygon points="' . implode(' ', $poly) . '" fill="' . self::COG_FILL . '" fill-opacity="' . self::COG_OPACITY
               . '" stroke="' . self::COG_STROKE . '" stroke-width="2.5" pointer-events="none"/>';
        foreach ($cogPts as $i => [$x, $y]) {
            $svg[] = '<circle cx="' . round($x, 1) . '" cy="' . round($y, 1) . '" r="4" fill="' . self::COG_STROKE
                   . '" stroke="#ffffff" stroke-width="1.2" pointer-events="none"/>';
            if (!$showCog) continue;
            // Подпись процента — ВНУТРЬ, к центру: наружу вдоль оси уже уходят
            // подписи «Знач.», иначе они наезжают друг на друга.
            $a = $ang($i);
            $svg[] = '<text x="' . round($x - 15 * cos($a), 1) . '" y="' . round($y - 15 * sin($a) + 4, 1) . '" text-anchor="middle" '
                   . 'font-size="10" fill="' . self::COG_TEXT . '">' . round((float) ($cogPct[$i] ?? 0)) . '%</text>';
        }

        // Легенда и пояснения. Раньше координаты квадратиков и подписей были
        // вбиты числами (cx−175, cx+78), а пояснения печатались одной строкой:
        // на уменьшенной картинке (диаграмма и матрица на одном листе PDF, #7)
        // подпись первого пункта заезжала под квадратик второго, а пояснения
        // уходили за край. Теперь размер шрифта подбирается под ширину, а текст
        // переносится по словам — наложиться пункты не могут.
        $items = [['Ответы теста (% от максимума шкалы)', self::COG_STROKE, '1']];
        if ($hasPhys) $items[] = ['Физиологический ответ', self::PHYS_FILL, '0.55'];
        $notes = [];
        if ($hasPhys) {
            $notes[] = 'Физиология: «Знач.» на шкале ±' . self::num($physScale)
                     . ', пунктир (50 %) — медиана; наружу — напряжение, к центру — его нет.';
            $notes[] = 'Жирная точка и жирная подпись оси — достоверное отклонение (p<0.05).';
        }
        $avail = $size - 16;
        $noteLines = [];
        foreach ($notes as $note) {
            foreach (self::fitLines($note, $avail, 9.0) as $ln) $noteLines[] = $ln;
        }
        // Кегль легенды — самый крупный из тех, при котором строка ещё влезает.
        $sq = 12; $gap = 22;
        $fs = 12.0;
        $width = static fn (array $items, float $fs, float $sq, float $gap): float =>
            array_sum(array_map(static fn ($it) => $sq + 6 + self::textW($it[0], $fs), $items)) + $gap * (count($items) - 1);
        foreach ([12.0, 11.0, 10.0, 9.0] as $try) {
            $fs = $try;
            if ($width($items, $try, $sq, $gap) <= $avail) break;
        }
        $ly = $imgH - 5 - count($noteLines) * 11 - 5;
        $sqY = $ly - $sq + 2;     // низ квадрата совпадает с базовой линией текста
        $lx = max(6.0, $cx - $width($items, $fs, $sq, $gap) / 2);
        foreach ($items as [$label, $color, $opacity]) {
            $svg[] = '<rect x="' . round($lx, 1) . '" y="' . round($sqY, 1) . '" width="' . $sq . '" height="' . $sq . '" fill="' . $color
                   . '" fill-opacity="' . $opacity . '" stroke="' . $color . '"/>';
            $svg[] = '<text x="' . round($lx + $sq + 6, 1) . '" y="' . round($ly, 1) . '" font-size="' . self::num($fs) . '" fill="'
                   . self::TEXT . '">' . self::esc($label) . '</text>';
            $lx += $sq + 6 + self::textW($label, $fs) + $gap;
        }
        foreach ($noteLines as $k => $line) {
            $svg[] = '<text x="' . $cx . '" y="' . round($imgH - 5 - (count($noteLines) - 1 - $k) * 11, 1)
                   . '" text-anchor="middle" font-size="9" fill="#8a949d">' . self::esc($line) . '</text>';
        }

        $svg[] = '</svg>';
        return implode("\n", $svg);
    }

    /**
     * Контур физиологии. Если данные есть по всем осям — замкнутый полигон.
     * Если часть осей не распознана, замыкать нельзя: ребро через пропуск —
     * выдуманная связь. Рисуем ломаные по непрерывным участкам.
     */
    private static function physShape(array $physPts, int $n): string {
        $xy = static fn (array $p) => round($p[0], 1) . ',' . round($p[1], 1);
        if (count($physPts) === $n) {
            $poly = array_map($xy, $physPts);
            return '<polygon points="' . implode(' ', $poly) . '" fill="' . self::PHYS_FILL . '" fill-opacity="' . self::PHYS_OPACITY
                 . '" stroke="' . self::PHYS_STROKE . '" stroke-width="2"/>';
        }
        $out = [];
        $run = [];
        // Обходим оси по кругу, разрывая ломаную на пропусках.
        for ($i = 0; $i <= $n; $i++) {
            $idx = $i % $n;
            if ($i < $n && isset($physPts[$idx])) {
                $run[] = $xy($physPts[$idx]);
                continue;
            }
            if (count($run) >= 2) {
                $out[] = '<polyline points="' . implode(' ', $run) . '" fill="none" stroke="' . self::PHYS_STROKE
                       . '" stroke-width="2" stroke-linecap="round"/>';
            }
            $run = [];
        }
        return implode("\n", $out);
    }

    private static function wrapLabel(string $text, float $x, float $y, string $anchor, bool $bold = false, float $room = 0.0, string $value = ''): string {
        // Honor explicit line breaks; otherwise split long labels onto 2 lines.
        if (strpos($text, "\n") !== false) {
            $lines = explode("\n", trim($text));
        } else {
            $words = preg_split('/\s+/', trim($text));
            if (count($words) <= 1) {
                $lines = $words;
            } else {
                $mid = (int) ceil(count($words) / 2);
                $lines = [implode(' ', array_slice($words, 0, $mid)), implode(' ', array_slice($words, $mid))];
            }
        }
        // Подпись шире, чем есть места до края картинки? Сначала переносим по
        // словам, а длинное слово («Интеллектуализация» у ИЖС) — через дефис;
        // если и так не помещается в две строки, уменьшаем кегль. Обрезать
        // подпись нельзя: «Интеллек-туализац-» не читается.
        $fs = 10.5;
        if ($room > 0) {
            foreach ([10.5, 9.5, 8.5, 7.5] as $try) {
                $fs = $try;
                $fit = [];
                foreach ($lines as $ln) {
                    foreach (self::fitLines($ln, $room, $try, 9) as $part) $fit[] = $part;
                }
                if (count($fit) <= 2 || $try === 7.5) { $lines = $fit; break; }
            }
        }
        // Строка со значением идёт последней и считается в общей высоте блока,
        // иначе подпись съезжала бы вверх относительно своей оси.
        $total = count($lines) + ($value !== '' ? 1 : 0);
        $lh = round($fs + 1.5, 1);
        $dy0 = $total > 1 ? -($lh * ($total - 1) / 2 - 1) : 4;
        $out = '<text x="' . round($x, 1) . '" y="' . round($y + $dy0, 1) . '" text-anchor="' . $anchor . '" font-size="' . self::num($fs) . '"'
             . ($bold ? ' font-weight="bold"' : '') . ' fill="' . self::TEXT . '">';
        foreach ($lines as $k => $ln) {
            $out .= '<tspan x="' . round($x, 1) . '" dy="' . ($k === 0 ? 0 : $lh) . '">' . self::esc($ln) . '</tspan>';
        }
        if ($value !== '') {
            // Цветом кривой ответов теста: значение относится к синему полигону,
            // а не к оранжевой физиологии.
            $out .= '<tspan x="' . round($x, 1) . '" dy="' . ($lines ? $lh : 0) . '" font-weight="bold" fill="'
                  . self::COG_TEXT . '">' . self::esc($value) . '</tspan>';
        }
        $out .= '</text>';
        return $out;
    }

    /** Ширина строки Verdana в пикселях (оценка по среднему кеглю). */
    private static function textW(string $s, float $fontSize): float {
        return mb_strlen($s) * $fontSize * 0.575;
    }

    /**
     * Разбивает текст на строки, влезающие в заданную ширину. Слово длиннее
     * строки переносится через дефис: у ИЖС есть подписи вроде
     * «Интеллектуализация», которые иначе уезжают за край картинки.
     *
     * @return string[]
     */
    private static function fitLines(string $text, float $maxW, float $fontSize, int $maxLines = 3): array {
        $per = max(4, (int) floor($maxW / ($fontSize * 0.575)));
        $lines = [];
        $cur = '';
        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $word) {
            while (mb_strlen($word) > $per) {
                if ($cur !== '') { $lines[] = $cur; $cur = ''; }
                $lines[] = mb_substr($word, 0, $per - 1) . '-';
                $word = mb_substr($word, $per - 1);
            }
            $try = $cur === '' ? $word : $cur . ' ' . $word;
            if (mb_strlen($try) > $per && $cur !== '') { $lines[] = $cur; $cur = $word; }
            else $cur = $try;
        }
        if ($cur !== '') $lines[] = $cur;
        if (count($lines) > $maxLines) $lines = array_slice($lines, 0, $maxLines);
        return $lines ?: [''];
    }

    /** Compact number: 9.0 → "9", -3.5 → "-3.5". */
    private static function num(float $v): string {
        return rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
    }

    private static function esc(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
