<?php
/**
 * Parse the OCR'd "смысло-эмоциональная значимость" table into structured
 * physiological rows and align the signed "Зна" value with the cognitive axes
 * so the chart can overlay them.
 *
 * OCR of a screenshot table is inherently noisy, so this is best-effort and the
 * UI always lets the operator review/override the aligned values before the
 * (purely mathematical) chart is drawn.
 */

final class Phys {
    /** Russian stop-words ignored when matching a table row to an axis label. */
    private const STOP = ['мотив', 'мотива', 'и', 'в', 'на', 'по', 'самооценки', 'личностного'];

    /**
     * @param string $ocrText  recognized text of the screenshot
     * @param array  $labels   cognitive axis labels (defines order + count)
     * @return array ['aligned' => [signedZna|null,...], 'rows' => [...]]
     */
    public static function parse(string $ocrText, array $labels): array {
        $lines = preg_split('/\r?\n/', trim($ocrText));
        $aligned = array_fill(0, count($labels), null);
        $rows = [];

        foreach ($labels as $idx => $label) {
            $key = self::keyword($label);
            if ($key === '') continue;
            foreach ($lines as $line) {
                if (mb_stripos($line, $key) === false) continue;
                $zna = self::firstSigned($line);
                if ($zna === null) continue;
                $aligned[$idx] = $zna;
                $rows[] = [
                    'label'  => $label,
                    'zna'    => $zna,
                    'p'      => self::pValue($line),
                    'star'   => (bool) preg_match('/\*/', $line),
                    'raw'    => trim($line),
                ];
                break;
            }
        }
        return ['aligned' => $aligned, 'rows' => $rows];
    }

    /** Most distinctive token of a label (longest non-stopword). */
    private static function keyword(string $label): string {
        $best = '';
        foreach (preg_split('/[\s\n]+/', mb_strtolower($label)) as $w) {
            $w = preg_replace('/[^\p{L}]/u', '', $w);
            if ($w === '' || in_array($w, self::STOP, true)) continue;
            // Use a stable stem (first 6 chars) to survive OCR/case endings.
            $stem = mb_substr($w, 0, 6);
            if (mb_strlen($stem) > mb_strlen($best)) $best = $stem;
        }
        return $best;
    }

    /** First signed decimal number on a line (the Зна value). */
    private static function firstSigned(string $line): ?float {
        if (preg_match('/[-−]?\d+[.,]\d+/u', $line, $m)) {
            return (float) str_replace([',', '−'], ['.', '-'], $m[0]);
        }
        if (preg_match('/[-−]\s*\d+/u', $line, $m)) {
            return (float) str_replace(['−', ' '], ['-', ''], $m[0]);
        }
        return null;
    }

    /** Extract a p-value if the line reports one (p=0.01, p<0,05 …). */
    private static function pValue(string $line): ?float {
        if (preg_match('/p\s*[=<>]?\s*([01][.,]\d+)/ui', $line, $m)) {
            return (float) str_replace(',', '.', $m[1]);
        }
        return null;
    }

    /** Build the aligned array from operator-entered values keyed by axis index. */
    public static function fromManual(array $values, int $count): array {
        $aligned = array_fill(0, $count, null);
        foreach ($values as $i => $v) {
            $i = (int) $i;
            if ($i < 0 || $i >= $count) continue;
            $v = trim((string) $v);
            $aligned[$i] = $v === '' ? null : (float) str_replace(',', '.', $v);
        }
        return $aligned;
    }
}
