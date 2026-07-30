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

final class Chart {
    // Brand palette derived from the NeuroPro logo (crimson) + reference teal.
    private const COG_STROKE = '#b3203b';   // cognitive polygon (crimson)
    private const COG_FILL   = '#b3203b';
    private const COG_OPACITY = '0.16';
    private const COG_TEXT   = '#8f1a30';   // подписи баллов у точек
    private const PHYS_STROKE = '#1f9da8';  // physiological polygon (teal)
    private const PHYS_FILL  = '#1f9da8';
    private const PHYS_OPACITY = '0.22';
    private const PHYS_TEXT  = '#127680';   // подписи Знач. у точек
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
        $labels = $cog = $physVals = $sig = [];
        foreach ($metrics['axes'] as $a) {
            $labels[]   = $a['short'];
            $cog[]      = $a['pct'];                 // уже % от максимума своей шкалы
            $physVals[] = $a['zna'];
            $sig[]      = $a['sig'];
        }
        return self::render($labels, $cog, $physVals, $sig, $opts + [
            'phys_scale' => $metrics['phys_scale'] ?? 0.0,
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

        $svg = [];
        $svg[] = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" '
               . 'viewBox="0 0 ' . $size . ' ' . $size . '" font-family="Verdana, Geneva, sans-serif">';
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
            $svg[] = self::wrapLabel((string) $labels[$i], $lx, $ly, $anchor);
        }

        // Physiological overlay first (drawn under cognitive so the line reads on top).
        $physPts = [];   // [axisIdx => [x, y, value, significant]]
        $hasPhys = false;
        if ($physScale > 0) {
            for ($i = 0; $i < $n; $i++) {
                $v = $phys[$i] ?? null;
                if ($v === null) continue; // нет данных — нет точки
                // Медиана (0) — на кольце 50 %, ±phys_scale — край и центр.
                $frac = max(0.02, min(1.0, 0.5 + 0.5 * ((float) $v / $physScale)));
                [$x, $y] = $pt($i, $frac);
                $physPts[$i] = [$x, $y, (float) $v, !empty($sig[$i])];
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
            foreach ($physPts as $i => [$x, $y, $v, $isSig]) {
                // Точка: при p<0.05 — заметно жирнее (крупнее + обводка).
                $r = $isSig ? 6.0 : 3.6;
                $svg[] = '<circle cx="' . round($x, 1) . '" cy="' . round($y, 1) . '" r="' . $r . '" fill="' . self::PHYS_STROKE . '" stroke="#ffffff" stroke-width="' . ($isSig ? '2' : '1.2') . '"/>';
                // Подпись Знач. — со смещением ВБОК от оси (по касательной), а не
                // вдоль неё: когнитивная точка всегда лежит на самой оси, и
                // радиальная подпись наезжала на неё, когда кривые сближались.
                $a = $ang($i);
                $tx = $x + 9 * cos($a) - 14 * sin($a);
                $ty = $y + 9 * sin($a) + 14 * cos($a) + 4;
                $svg[] = '<text x="' . round($tx, 1) . '" y="' . round($ty, 1) . '" text-anchor="middle" font-size="' . ($isSig ? '12' : '10') . '"'
                       . ($isSig ? ' font-weight="bold"' : '') . ' fill="' . self::PHYS_TEXT . '">' . self::num($v) . '</text>';
            }
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
        $svg[] = '<polygon points="' . implode(' ', $poly) . '" fill="' . self::COG_FILL . '" fill-opacity="' . self::COG_OPACITY . '" stroke="' . self::COG_STROKE . '" stroke-width="2.5"/>';
        foreach ($cogPts as $i => [$x, $y]) {
            $svg[] = '<circle cx="' . round($x, 1) . '" cy="' . round($y, 1) . '" r="4" fill="' . self::COG_STROKE . '" stroke="#ffffff" stroke-width="1.2"/>';
            if (!$showCog) continue;
            // Подпись процента — ВНУТРЬ, к центру: наружу вдоль оси уже уходят
            // подписи «Знач.», иначе они наезжают друг на друга.
            $a = $ang($i);
            $svg[] = '<text x="' . round($x - 15 * cos($a), 1) . '" y="' . round($y - 15 * sin($a) + 4, 1) . '" text-anchor="middle" '
                   . 'font-size="10" fill="' . self::COG_TEXT . '">' . round((float) ($cogPct[$i] ?? 0)) . '%</text>';
        }

        // Legend + пояснение обеих шкал.
        $ly = $size - ($hasPhys ? 34 : 16);
        $svg[] = '<rect x="' . ($cx - 175) . '" y="' . ($ly - 11) . '" width="13" height="13" fill="' . self::COG_STROKE . '"/>';
        $svg[] = '<text x="' . ($cx - 157) . '" y="' . $ly . '" font-size="12" fill="' . self::TEXT . '">Ответы теста (% от максимума шкалы)</text>';
        if ($hasPhys) {
            $svg[] = '<rect x="' . ($cx + 78) . '" y="' . ($ly - 11) . '" width="13" height="13" fill="' . self::PHYS_STROKE . '" fill-opacity="0.55" stroke="' . self::PHYS_STROKE . '"/>';
            $svg[] = '<text x="' . ($cx + 96) . '" y="' . $ly . '" font-size="12" fill="' . self::TEXT . '">Физиология</text>';
            $svg[] = '<text x="' . $cx . '" y="' . ($size - 18) . '" text-anchor="middle" font-size="10" fill="#8a949d">'
                   . 'Физиология: «Знач.» на шкале ±' . self::num($physScale)
                   . ', пунктир (50 %) — медиана; наружу — напряжение, к центру — его нет.</text>';
            $svg[] = '<text x="' . $cx . '" y="' . ($size - 6) . '" text-anchor="middle" font-size="10" fill="#8a949d">'
                   . 'Жирная точка — достоверное отклонение (p&lt;0.05).</text>';
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

    private static function wrapLabel(string $text, float $x, float $y, string $anchor): string {
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
        $dy0 = count($lines) > 1 ? -5 : 4;
        $out = '<text x="' . round($x, 1) . '" y="' . round($y + $dy0, 1) . '" text-anchor="' . $anchor . '" font-size="10.5" fill="' . self::TEXT . '">';
        foreach ($lines as $k => $ln) {
            $out .= '<tspan x="' . round($x, 1) . '" dy="' . ($k === 0 ? 0 : 12) . '">' . self::esc($ln) . '</tspan>';
        }
        $out .= '</text>';
        return $out;
    }

    /** Compact number: 9.0 → "9", -3.5 → "-3.5". */
    private static function num(float $v): string {
        return rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
    }

    private static function esc(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
