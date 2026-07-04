<?php
/**
 * Parse the OCR'd "смысло-эмоциональная значимость" table into structured
 * physiological rows and align the signed "Знач." value (визуальная шкала
 * Эгоскопа: отклонение от медианы, столбец «Знач.»/«Значение») with the
 * cognitive axes so the chart can overlay it. Also extracts the p-value per
 * row ("Достоверность"): rows with p<0.05 are rendered bold on the chart.
 *
 * OCR of a screenshot table is inherently noisy, so this is best-effort and the
 * UI always lets the operator review/override the aligned values before the
 * (purely mathematical) chart is drawn.
 */

final class Phys {
    /** Порог достоверности: строки с p < SIG выделяются жирным. */
    public const SIG = 0.05;

    /** Russian stop-words ignored when matching a table row to an axis label. */
    private const STOP = ['мотив', 'мотива', 'и', 'в', 'на', 'по', 'самооценки', 'личностного'];

    /**
     * @param string $ocrText  recognized text of the screenshot
     * @param array  $labels   cognitive axis labels (defines order + count)
     * @return array ['aligned' => [signedZna|null,...], 'p' => [float|null,...],
     *                'sig' => [bool,...], 'rows' => [...], 'error' => null]
     */
    public static function parse(string $ocrText, array $labels): array {
        $n = count($labels);
        $out = [
            'aligned' => array_fill(0, $n, null),
            'p'       => array_fill(0, $n, null),
            'sig'     => array_fill(0, $n, false),
            'rows'    => [],
            'error'   => null,
        ];
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', trim($ocrText))), 'strlen'));
        if (!$lines) return $out;

        // 1. Находим строку каждой оси по её ключевому слову.
        $found = []; // axisIdx => lineIdx
        foreach ($labels as $idx => $label) {
            $key = self::keyword($label);
            if ($key === '') continue;
            foreach ($lines as $li => $line) {
                if (mb_stripos($line, $key) !== false) { $found[$idx] = $li; break; }
            }
        }
        if (!$found) return $out;
        asort($found); // в порядке появления в OCR-тексте

        // 2. Построчный (row-major) проход: Знач. и p ищем в «полосе» строк от
        //    подписи оси до подписи следующей найденной оси — Yandex OCR может
        //    разнести ячейки одной строки таблицы по нескольким строкам текста.
        $order = array_keys($found);
        $lineIdxs = array_values($found);
        foreach ($order as $k => $idx) {
            $from = $lineIdxs[$k];
            $to = $lineIdxs[$k + 1] ?? min(count($lines), $from + 3); // хвост таблицы: не дальше 2 строк
            $span = implode(' ', array_slice($lines, $from, max(1, $to - $from)));
            $zna = self::firstSigned(self::stripLabel($span, (string) $labels[$idx]));
            if ($zna === null) continue;
            $out['aligned'][$idx] = $zna;
            $out['p'][$idx] = self::pValue($span);
            $out['rows'][$idx] = ['label' => $labels[$idx], 'zna' => $zna, 'p' => $out['p'][$idx], 'raw' => trim($span)];
        }

        // 3. Колоночный (column-major) fallback: OCR отдал сначала столбец имён,
        //    затем столбец Знач. Берём первые N чисел после последней подписи.
        $got = count(array_filter($out['aligned'], static fn ($v) => $v !== null));
        if ($got < (int) ceil(count($found) / 2)) {
            $lastLabelLine = max($lineIdxs);
            $tail = array_slice($lines, $lastLabelLine + 1);
            $nums = [];
            foreach ($tail as $line) {
                foreach (self::allSigned($line) as $v) $nums[] = $v;
                if (count($nums) >= count($found)) break;
            }
            if (count($nums) >= count($found)) {
                $pvals = self::allPValues(implode(' ', $tail));
                foreach ($order as $k => $idx) {
                    $out['aligned'][$idx] = $nums[$k];
                    $out['p'][$idx] = $pvals[$k] ?? null;
                    $out['rows'][$idx] = ['label' => $labels[$idx], 'zna' => $nums[$k], 'p' => $out['p'][$idx], 'raw' => 'column-major'];
                }
            }
        }

        foreach ($out['p'] as $i => $p) {
            $out['sig'][$i] = $p !== null && $p < self::SIG;
        }
        ksort($out['rows']);
        $out['rows'] = array_values($out['rows']);
        return $out;
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

    /** Убирает подпись оси из строки, чтобы цифры в подписи не считались Знач. */
    private static function stripLabel(string $line, string $label): string {
        foreach (preg_split('/[\s\n]+/', $label) as $w) {
            if ($w !== '') $line = (string) preg_replace('/' . preg_quote($w, '/') . '/ui', ' ', $line, 1);
        }
        return $line;
    }

    /** First signed number on a line (the Знач. value — первый числовой столбец). */
    private static function firstSigned(string $line): ?float {
        $all = self::allSigned($line);
        return $all[0] ?? null;
    }

    /** Все знаковые числа строки, кроме p-значений (p>0.05 и т.п.). */
    private static function allSigned(string $line): array {
        // Вырезаем p-значения, иначе «p>0.05» перехватывается как Зна, когда
        // само Зна целое (формат Басса-Дарки: «Косвенная агрессия 6 6 p>0.05»).
        $line = (string) preg_replace('/p\s*[=<>≤≥]?\s*[01][.,]\d+/ui', ' ', $line);
        $out = [];
        if (preg_match_all('/[-−]?\d+(?:[.,]\d+)?/u', $line, $m)) {
            foreach ($m[0] as $tok) {
                $out[] = (float) str_replace([',', '−', ' '], ['.', '-', ''], $tok);
            }
        }
        return $out;
    }

    /** Extract a p-value if the line reports one (p=0.01, p<0,05 …). */
    private static function pValue(string $line): ?float {
        $all = self::allPValues($line);
        return $all[0] ?? null;
    }

    /** Все p-значения строки по порядку. «p<X» трактуем чуть ниже X, «p>X» — выше. */
    private static function allPValues(string $line): array {
        $out = [];
        if (preg_match_all('/p\s*([=<>≤≥]?)\s*([01][.,]\d+)/ui', $line, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $v = (float) str_replace(',', '.', $hit[2]);
                // Неравенство сохраняем в числе: p<0.05 достоверно, p>0.05 — нет.
                if ($hit[1] === '<' || $hit[1] === '≤') $v -= 0.001;
                elseif ($hit[1] === '>' || $hit[1] === '≥') $v += 0.001;
                $out[] = round($v, 4);
            }
        }
        return $out;
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

    /**
     * Decode the stored phys_json into the canonical structure. Backward
     * compatible: older profiles stored a plain aligned array.
     */
    public static function decode(?string $json, int $count): ?array {
        if ($json === null || trim($json) === '') return null;
        $data = json_decode($json, true);
        if (!is_array($data)) return null;
        if (array_is_list($data)) { // старый формат: только aligned
            $data = ['aligned' => $data];
        }
        $norm = static function (array $src, $fill) use ($count): array {
            $out = array_fill(0, $count, $fill);
            foreach ($src as $i => $v) { if ((int) $i < $count) $out[(int) $i] = $v; }
            return $out;
        };
        return [
            'aligned' => $norm($data['aligned'] ?? [], null),
            'p'       => $norm($data['p'] ?? [], null),
            'sig'     => array_map('boolval', $norm($data['sig'] ?? [], false)),
            'rows'    => $data['rows'] ?? [],
            'error'   => isset($data['error']) && $data['error'] !== '' ? (string) $data['error'] : null,
        ];
    }

    /** Есть ли хоть одно распознанное значение. */
    public static function hasData(?array $phys): bool {
        if ($phys === null) return false;
        foreach ($phys['aligned'] ?? [] as $v) { if ($v !== null) return true; }
        return false;
    }
}
