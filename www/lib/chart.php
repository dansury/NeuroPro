<?php
/**
 * Mathematical radar ("spider") chart generator — pure geometry, no AI.
 *
 * Renders the cognitive parameters (12 for СМУ, 8 for LSI/Басса-Дарки) as a polygon and, when
 * physiological data from the OCR'd "смысло-эмоциональная значимость" screenshot
 * is supplied, overlays it as a second translucent polygon on the same axes —
 * exactly the "наложить физиологические на когнитивные" requirement.
 *
 * Физиология — это столбец «Знач.» (визуальная шкала Эгоскопа): 0 = медиана
 * (середина радиуса), >0 — наружу, <0 — к центру. Оси с p<0.05 выделяются
 * жирной точкой и жирной подписью значения. Оси без данных честно пропускаются
 * (никаких точек-заглушек — Конституция, принцип II).
 *
 * Output is a standalone SVG string (crisp at any size, embeds straight into the
 * web page and the branded PDF). Font is Verdana throughout. Сетка — круговая,
 * по визуальному референсу (Sources/diagram_reference.jpg).
 */

final class Chart {
    // Brand palette derived from the NeuroPro logo (crimson) + reference teal.
    private const COG_STROKE = '#b3203b';   // cognitive polygon (crimson)
    private const COG_FILL   = '#b3203b';
    private const COG_OPACITY = '0.16';
    private const PHYS_STROKE = '#1f9da8';  // physiological polygon (teal)
    private const PHYS_FILL  = '#1f9da8';
    private const PHYS_OPACITY = '0.30';
    private const PHYS_TEXT  = '#127680';   // подписи Знач. у точек
    private const GRID       = '#e2e7ec';
    private const GRID_OUTER = '#c9d2da';
    private const AXIS       = '#d7dde3';
    private const TEXT       = '#2a3138';

    /**
     * @param array  $labels      axis labels (clockwise from top)
     * @param array  $cognitive   numeric values aligned with $labels
     * @param int    $max         scale maximum (10 for СМУ, 100 for LSI, 13 for Басса-Дарки)
     * @param array|null $phys     optional physiological values aligned with
     *                             $labels (signed "Знач."); null entries skipped
     * @param array  $opts        size, title, rings, phys_max, phys_sig (bool[] — p<0.05)
     */
    public static function svg(array $labels, array $cognitive, int $max = 10, ?array $phys = null, array $opts = []): string {
        $n = count($labels);
        if ($n < 3) return '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"></svg>';

        $size  = (int) ($opts['size'] ?? 560);
        $rings = (int) ($opts['rings'] ?? ($max <= 20 ? $max : 10)); // кольцо на каждый балл, пока шкала короткая
        $title = (string) ($opts['title'] ?? '');
        $physSig = $opts['phys_sig'] ?? [];
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

        // Круговая сетка (по референсу): заливка-подложка + тонкие концентрические
        // кольца, внешнее кольцо акцентировано.
        $svg[] = '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . round($R, 1) . '" fill="#fbfcfd" stroke="none"/>';
        for ($g = 1; $g <= $rings; $g++) {
            $frac = $g / $rings;
            $isOuter = $g === $rings;
            $svg[] = '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . round($R * $frac, 1) . '" fill="none" stroke="'
                   . ($isOuter ? self::GRID_OUTER : self::GRID) . '" stroke-width="' . ($isOuter ? '1.6' : '1') . '"/>';
            $scaleVal = round($max * $frac);
            $svg[] = '<text x="' . round($cx + 4) . '" y="' . round($cy - $R * $frac + 3.5) . '" font-size="9" fill="#9aa4ad">' . $scaleVal . '</text>';
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
        if ($phys !== null) {
            $pmax = (float) ($opts['phys_max'] ?? self::absMax($phys));
            if ($pmax <= 0) $pmax = 1.0;
            for ($i = 0; $i < $n; $i++) {
                $v = $phys[$i] ?? null;
                if ($v === null) continue; // нет данных — нет точки
                // Map signed Знач. onto the radius: median (0) sits at mid-ring,
                // right-of-median (>0, tension) reaches outward, left inward.
                $frac = max(0.04, min(1.0, 0.5 + 0.5 * ((float) $v / $pmax)));
                [$x, $y] = $pt($i, $frac);
                $physPts[$i] = [$x, $y, (float) $v, !empty($physSig[$i])];
            }
            if (count($physPts) >= 3) {
                $poly = array_map(static fn ($p) => round($p[0], 1) . ',' . round($p[1], 1), $physPts);
                $svg[] = '<polygon points="' . implode(' ', $poly) . '" fill="' . self::PHYS_FILL . '" fill-opacity="' . self::PHYS_OPACITY . '" stroke="' . self::PHYS_STROKE . '" stroke-width="2"/>';
            }
            foreach ($physPts as $i => [$x, $y, $v, $sig]) {
                // Точка: при p<0.05 — заметно жирнее (крупнее + обводка).
                $r = $sig ? 6.0 : 3.6;
                $svg[] = '<circle cx="' . round($x, 1) . '" cy="' . round($y, 1) . '" r="' . $r . '" fill="' . self::PHYS_STROKE . '" stroke="#ffffff" stroke-width="' . ($sig ? '2' : '1.2') . '"/>';
                // Подпись Знач. рядом с точкой, наружу вдоль оси; жирная при p<0.05.
                $a = $ang($i);
                $tx = $x + 14 * cos($a);
                $ty = $y + 14 * sin($a) + 4;
                $anchor = abs(cos($a)) < 0.25 ? 'middle' : (cos($a) > 0 ? 'start' : 'end');
                $svg[] = '<text x="' . round($tx, 1) . '" y="' . round($ty, 1) . '" text-anchor="' . $anchor . '" font-size="' . ($sig ? '12' : '10') . '"'
                       . ($sig ? ' font-weight="bold"' : '') . ' fill="' . self::PHYS_TEXT . '">' . self::num($v) . '</text>';
            }
        }

        // Cognitive polygon.
        $poly = [];
        for ($i = 0; $i < $n; $i++) {
            $v = (float) ($cognitive[$i] ?? 0);
            $frac = $max > 0 ? max(0.0, min(1.0, $v / $max)) : 0.0;
            [$x, $y] = $pt($i, $frac);
            $poly[] = round($x, 1) . ',' . round($y, 1);
        }
        $svg[] = '<polygon points="' . implode(' ', $poly) . '" fill="' . self::COG_FILL . '" fill-opacity="' . self::COG_OPACITY . '" stroke="' . self::COG_STROKE . '" stroke-width="2.5"/>';
        foreach (explode(' ', implode(' ', $poly)) as $pair) {
            [$x, $y] = explode(',', $pair);
            $svg[] = '<circle cx="' . $x . '" cy="' . $y . '" r="4" fill="' . self::COG_STROKE . '" stroke="#ffffff" stroke-width="1.2"/>';
        }

        // Legend + пояснение шкалы физиологии.
        $hasPhys = !empty($physPts);
        $ly = $size - ($hasPhys ? 30 : 16);
        $svg[] = '<rect x="' . ($cx - 150) . '" y="' . ($ly - 11) . '" width="13" height="13" fill="' . self::COG_STROKE . '"/>';
        $svg[] = '<text x="' . ($cx - 132) . '" y="' . $ly . '" font-size="12" fill="' . self::TEXT . '">Когнитивный профиль</text>';
        if ($hasPhys) {
            $svg[] = '<rect x="' . ($cx + 20) . '" y="' . ($ly - 11) . '" width="13" height="13" fill="' . self::PHYS_STROKE . '"/>';
            $svg[] = '<text x="' . ($cx + 38) . '" y="' . $ly . '" font-size="12" fill="' . self::TEXT . '">Физиология (Знач.)</text>';
            $svg[] = '<text x="' . $cx . '" y="' . ($size - 10) . '" text-anchor="middle" font-size="10" fill="#8a949d">'
                   . 'Знач.: 0 (медиана) — середина шкалы, &gt;0 — наружу, &lt;0 — к центру; жирная точка — p&lt;0.05</text>';
        }

        $svg[] = '</svg>';
        return implode("\n", $svg);
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

    private static function absMax(array $vals): float {
        $m = 0.0;
        foreach ($vals as $v) { if ($v !== null && abs((float) $v) > $m) $m = abs((float) $v); }
        return $m;
    }

    /** Compact number: 9.0 → "9", -3.5 → "-3.5". */
    private static function num(float $v): string {
        return rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
    }

    private static function esc(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
