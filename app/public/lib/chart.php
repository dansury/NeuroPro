<?php
/**
 * Mathematical radar ("spider") chart generator — pure geometry, no AI.
 *
 * Renders the cognitive parameters (12 for СМУ, 8 for LSI/Басса-Дарки) as a polygon and, when
 * physiological data from the OCR'd "смысло-эмоциональная значимость" screenshot
 * is supplied, overlays it as a second translucent polygon on the same axes —
 * exactly the "наложить физиологические на когнитивные" requirement.
 *
 * Output is a standalone SVG string (crisp at any size, embeds straight into the
 * web page and the branded PDF). Font is Verdana throughout.
 */

final class Chart {
    // Brand palette derived from the NeuroPro logo (crimson) + reference teal.
    private const COG_STROKE = '#b3203b';   // cognitive polygon (crimson)
    private const COG_FILL   = '#b3203b';
    private const COG_OPACITY = '0.14';
    private const PHYS_STROKE = '#1f9da8';  // physiological polygon (teal)
    private const PHYS_FILL  = '#1f9da8';
    private const PHYS_OPACITY = '0.28';
    private const GRID       = '#d7dde3';
    private const AXIS       = '#b9c2cc';
    private const TEXT       = '#2a3138';

    /**
     * @param array  $labels      axis labels (clockwise from top)
     * @param array  $cognitive   numeric values aligned with $labels
     * @param int    $max         scale maximum (10 for СМУ, 100 for LSI, 13 for Басса-Дарки)
     * @param array|null $phys     optional physiological values aligned with
     *                             $labels (signed "Зна"); null entries skipped
     * @param array  $opts        size, title, rings, phys_max
     */
    public static function svg(array $labels, array $cognitive, int $max = 10, ?array $phys = null, array $opts = []): string {
        $n = count($labels);
        if ($n < 3) return '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"></svg>';

        $size  = (int) ($opts['size'] ?? 560);
        $rings = (int) ($opts['rings'] ?? ($max <= 20 ? $max : 10)); // кольцо на каждый балл, пока шкала короткая
        $title = (string) ($opts['title'] ?? '');
        $cx = $size / 2;
        $cy = $size / 2 + 6;
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

        // Concentric grid rings (polygonal) + ring scale labels.
        for ($g = 1; $g <= $rings; $g++) {
            $frac = $g / $rings;
            $poly = [];
            for ($i = 0; $i < $n; $i++) { [$x, $y] = $pt($i, $frac); $poly[] = round($x, 1) . ',' . round($y, 1); }
            $svg[] = '<polygon points="' . implode(' ', $poly) . '" fill="none" stroke="' . self::GRID . '" stroke-width="1"/>';
            $scaleVal = round($max * $frac);
            $svg[] = '<text x="' . round($cx + 3) . '" y="' . round($cy - $R * $frac + 3) . '" font-size="9" fill="#9aa4ad">' . $scaleVal . '</text>';
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
        if ($phys !== null) {
            $pmax = (float) ($opts['phys_max'] ?? self::absMax($phys));
            if ($pmax <= 0) $pmax = 1.0;
            $poly = [];
            for ($i = 0; $i < $n; $i++) {
                $v = $phys[$i] ?? null;
                // Map signed Зна onto the radius: median (0) sits at mid-ring,
                // right-of-median (>0, tension) reaches outward, left inward.
                $frac = $v === null ? 0.5 : max(0.04, min(1.0, 0.5 + 0.5 * ($v / $pmax)));
                [$x, $y] = $pt($i, $frac);
                $poly[] = round($x, 1) . ',' . round($y, 1);
            }
            $svg[] = '<polygon points="' . implode(' ', $poly) . '" fill="' . self::PHYS_FILL . '" fill-opacity="' . self::PHYS_OPACITY . '" stroke="' . self::PHYS_STROKE . '" stroke-width="2"/>';
            foreach (explode(' ', implode(' ', $poly)) as $pair) {
                [$x, $y] = explode(',', $pair);
                $svg[] = '<circle cx="' . $x . '" cy="' . $y . '" r="3.4" fill="' . self::PHYS_STROKE . '"/>';
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
            $svg[] = '<circle cx="' . $x . '" cy="' . $y . '" r="3.8" fill="' . self::COG_STROKE . '"/>';
        }

        // Legend.
        $ly = $size - 16;
        $svg[] = '<rect x="' . ($cx - 150) . '" y="' . ($ly - 11) . '" width="13" height="13" fill="' . self::COG_STROKE . '"/>';
        $svg[] = '<text x="' . ($cx - 132) . '" y="' . $ly . '" font-size="12" fill="' . self::TEXT . '">Когнитивный профиль</text>';
        if ($phys !== null) {
            $svg[] = '<rect x="' . ($cx + 20) . '" y="' . ($ly - 11) . '" width="13" height="13" fill="' . self::PHYS_STROKE . '"/>';
            $svg[] = '<text x="' . ($cx + 38) . '" y="' . $ly . '" font-size="12" fill="' . self::TEXT . '">Физиология (значимость)</text>';
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

    private static function esc(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
