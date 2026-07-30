<?php
/**
 * Parse the OCR'd "смысло-эмоциональная значимость" table into structured
 * physiological rows and align the signed "Знач." value (визуальная шкала
 * Эгоскопа: отклонение от медианы, столбец «Знач.»/«Значение») with the
 * cognitive axes so the chart can overlay it. Also extracts per row:
 * достоверность (p), доминирующий параметр (столбец «СМК»: Z>X>Y) и «Балл»
 * — последний нужен только для сверки с Excel, источник истины — Excel.
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
     * Достоверность в OCR-тексте. Латинская «p» часто распознаётся как русская
     * «р», ноль — как «О»/«o», а «<» может прийти как «&lt;» или «‹». Раньше
     * шаблон принимал только латинскую «p» с цифровым нулём, и строка
     * «Обида −12 p<0.05» теряла достоверность: в отчёте вместо реального
     * p<0.05 появлялся прочерк.
     */
    private const P_RE = '/[pрP\x{0420}]\s*(=|<|>|≤|≥|&lt;|&gt;|‹|›)?\s*([01OoОо][.,]\d+)/u';

    /** Тот же шаблон для вырезания p из строки перед поиском чисел. */
    private const P_STRIP_RE = '/[pрP\x{0420}]\s*(?:=|<|>|≤|≥|&lt;|&gt;|‹|›)?\s*[01OoОо][.,]\d+/u';

    /**
     * @param string $ocrText  recognized text of the screenshot
     * @param array  $labels   cognitive axis labels (defines order + count)
     * @return array ['aligned' => [signedZna|null,...], 'p' => [float|null,...],
     *                'sig' => [bool,...], 'smk' => [string,...], 'ball' => [float|null,...],
     *                'rows' => [...], 'error' => null]
     */
    public static function parse(string $ocrText, array $labels): array {
        $n = count($labels);
        $out = [
            'aligned' => array_fill(0, $n, null),
            'p'       => array_fill(0, $n, null),
            'sig'     => array_fill(0, $n, false),
            'smk'     => array_fill(0, $n, ''),
            'ball'    => array_fill(0, $n, null),
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

        // 2. Построчный (row-major) проход: Знач., p и СМК ищем в «полосе» строк от
        //    подписи оси до подписи следующей найденной оси — Yandex OCR может
        //    разнести ячейки одной строки таблицы по нескольким строкам текста.
        $order = array_keys($found);
        $lineIdxs = array_values($found);
        foreach ($order as $k => $idx) {
            $from = $lineIdxs[$k];
            // Для последней оси полоса тянется до конца текста, но не больше 4 строк:
            // хвост таблицы у Эгоскопа — пустая строка, а p может уехать на вторую.
            $to = $lineIdxs[$k + 1] ?? min(count($lines), $from + 4);
            $span = implode(' ', array_slice($lines, $from, max(1, $to - $from)));
            $clean = self::stripLabel($span, (string) $labels[$idx]);
            $nums = self::allSigned($clean);
            if (!$nums) continue;
            $out['aligned'][$idx] = $nums[0];                       // «Знач.» — первый числовой столбец
            $out['p'][$idx] = self::pValue($span);
            $out['smk'][$idx] = self::smk($clean);
            // «Балл» — последний числовой столбец, есть только если столбцов ≥ 3
            // («Знач.», «m», …, «Балл»). Нужен исключительно для сверки с Excel.
            $out['ball'][$idx] = count($nums) >= 3 ? $nums[count($nums) - 1] : null;
            $out['rows'][$idx] = [
                'label' => $labels[$idx], 'zna' => $nums[0], 'p' => $out['p'][$idx],
                'smk' => $out['smk'][$idx], 'ball' => $out['ball'][$idx], 'raw' => trim($span),
            ];
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
                $joined = implode(' ', $tail);
                $pvals = self::allPValues($joined);
                $smks = self::allSmk($joined);
                foreach ($order as $k => $idx) {
                    $out['aligned'][$idx] = $nums[$k];
                    $out['p'][$idx] = $pvals[$k] ?? null;
                    $out['smk'][$idx] = $smks[$k] ?? '';
                    $out['ball'][$idx] = null; // в колоночном режиме столбцы не различить
                    $out['rows'][$idx] = [
                        'label' => $labels[$idx], 'zna' => $nums[$k], 'p' => $out['p'][$idx],
                        'smk' => $out['smk'][$idx], 'ball' => null, 'raw' => 'column-major',
                    ];
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

    /**
     * Выравнивание структурированных строк vision-распознавания (LLM::recognizeSignificance)
     * по осям профиля. В отличие от parse() здесь не гадаем по столбцам — знак и
     * величина «Знач.» уже сверены моделью с графиком; наша задача только
     * сопоставить строку модели с нужной осью (по ключевому слову подписи).
     *
     * @param array $rows   строки из LLM::recognizeSignificance()['rows']
     * @param array $labels подписи осей профиля (порядок и состав)
     */
    public static function fromVision(array $rows, array $labels): array {
        $n = count($labels);
        $out = [
            'aligned' => array_fill(0, $n, null),
            'p'       => array_fill(0, $n, null),
            'sig'     => array_fill(0, $n, false),
            'smk'     => array_fill(0, $n, ''),
            'ball'    => array_fill(0, $n, null),
            'rows'    => [],
            'error'   => null,
        ];
        // Индекс строк модели по ключевому слову их подписи.
        $byKey = [];
        foreach ($rows as $r) {
            $key = self::keyword((string) ($r['label'] ?? ''));
            if ($key !== '') $byKey[$key] = $r;
        }
        foreach ($labels as $idx => $label) {
            $r = null;
            $key = self::keyword((string) $label);
            // 1) точное совпадение по ключевому слову; 2) вхождение ключа оси в
            //    подпись модели (или наоборот) — OCR/модель могут обрезать слово.
            if ($key !== '' && isset($byKey[$key])) {
                $r = $byKey[$key];
            } else {
                foreach ($rows as $cand) {
                    $cl = mb_strtolower((string) ($cand['label'] ?? ''));
                    if ($key !== '' && ($cl !== '' && (mb_stripos($cl, $key) !== false))) { $r = $cand; break; }
                }
            }
            // 3) позиционный резерв: модель обычно сохраняет порядок ожидаемых шкал.
            if ($r === null && isset($rows[$idx]) && is_array($rows[$idx])) $r = $rows[$idx];
            if ($r === null) continue;

            $zna = isset($r['zna']) && is_numeric($r['zna']) ? (float) $r['zna'] : null;
            $p   = null;
            if (isset($r['p']) && $r['p'] !== null && $r['p'] !== '') {
                foreach (self::allPValues('p' . (string) $r['p']) as $pv) { $p = $pv; break; }
            }
            $sig = !empty($r['sig']) || ($p !== null && $p < self::SIG);
            if ($sig && $p === null) $p = self::SIG - 0.001;
            $out['aligned'][$idx] = $zna;
            $out['p'][$idx] = $p;
            $out['sig'][$idx] = $sig;
            $out['smk'][$idx] = self::normalizeSmk((string) ($r['smk'] ?? ''));
            $out['ball'][$idx] = isset($r['ball']) && is_numeric($r['ball']) ? (float) $r['ball'] : null;
            $out['rows'][$idx] = [
                'label' => $label, 'zna' => $zna, 'p' => $p,
                'smk' => $out['smk'][$idx], 'ball' => $out['ball'][$idx], 'raw' => 'vision',
            ];
        }
        ksort($out['rows']);
        $out['rows'] = array_values($out['rows']);
        return $out;
    }

    /** Нормализация СМК-токена из vision-ответа (кириллические двойники X/Y/Z). */
    private static function normalizeSmk(string $smk): string {
        $norm = str_replace(['Х', 'х', 'У', 'у', 'Ζ', 'x', 'y', 'z', '≥'], ['X', 'X', 'Y', 'Y', 'Z', 'X', 'Y', 'Z', '>'], $smk);
        return trim($norm);
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

    /** Все знаковые числа строки, кроме p-значений (p>0.05 и т.п.). */
    private static function allSigned(string $line): array {
        // Вырезаем p-значения, иначе «p>0.05» перехватывается как Зна, когда
        // само Зна целое (формат Басса-Дарки: «Косвенная агрессия 6 6 p>0.05»).
        $line = (string) preg_replace(self::P_STRIP_RE, ' ', $line);
        $out = [];
        if (preg_match_all('/[-−–]?\d+(?:[.,]\d+)?/u', $line, $m)) {
            foreach ($m[0] as $tok) {
                $out[] = (float) str_replace([',', '−', '–', ' '], ['.', '-', '-', ''], $tok);
            }
        }
        return $out;
    }

    /** Extract a p-value if the line reports one (p=0.01, p<0,05 …). */
    private static function pValue(string $line): ?float {
        $all = self::allPValues($line);
        return $all[0] ?? null;
    }

    /**
     * Все p-значения строки по порядку. Неравенство сохраняем в числе: «p<0.05»
     * достоверно, «p>0.05» — нет. Если знак неравенства не распознан, значение
     * пропускаем: у Эгоскопа достоверность всегда напечатана неравенством, и
     * догадка «наверное, недостоверно» была бы выдуманными данными.
     */
    private static function allPValues(string $line): array {
        $out = [];
        if (preg_match_all(self::P_RE, $line, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $op = $hit[1] ?? '';
                $num = str_replace(['O', 'o', 'О', 'о', ','], ['0', '0', '0', '0', '.'], $hit[2]);
                $v = (float) $num;
                $out[] = match ($op) {
                    '<', '≤', '&lt;', '‹' => round($v - 0.001, 4),
                    '>', '≥', '&gt;', '›' => round($v + 0.001, 4),
                    '='                   => round($v, 4),
                    default               => null, // знак не распознан — достоверность неизвестна
                };
            }
        }
        return $out;
    }

    /**
     * Столбец «СМК» — порядок доминирования параметров: «Z>X>Y», «X*Z*>Y»,
     * «Y>XZ». Возвращаем токен как есть (первая буква = доминирующий параметр,
     * «*» = «значительно»). Кириллические двойники X/Y нормализуем.
     */
    private static function smk(string $line): string {
        $all = self::allSmk($line);
        return $all[0] ?? '';
    }

    /** Все СМК-токены строки по порядку. */
    private static function allSmk(string $line): array {
        $norm = str_replace(
            ['Х', 'х', 'У', 'у', 'Ζ', 'x', 'y', 'z', '≥'],
            ['X', 'X', 'Y', 'Y', 'Z', 'X', 'Y', 'Z', '>'],
            (string) preg_replace(self::P_STRIP_RE, ' ', $line)
        );
        $out = [];
        if (preg_match_all('/[XYZ][XYZ*>]{2,}/u', $norm, $m)) {
            foreach ($m[0] as $tok) {
                // Осмысленный токен содержит «>» и минимум две разные буквы из
                // X/Y/Z — иначе это русский текст, в котором «х»/«у» стали X/Y.
                if (!str_contains($tok, '>')) continue;
                $letters = array_unique(preg_split('//', preg_replace('/[^XYZ]/', '', $tok), -1, PREG_SPLIT_NO_EMPTY) ?: []);
                if (count($letters) >= 2) $out[] = $tok;
            }
        }
        return $out;
    }

    /**
     * Ручная правка оператором: «Знач.» по осям + флажки достоверности. Раньше
     * возвращался только aligned, из-за чего правка одного значения обнуляла
     * распознанные p / СМК по всем осям.
     *
     * @param array      $values  ['axisIdx' => 'знач.'] из формы
     * @param array      $sigPost ['axisIdx' => '1'] отмеченные p<0.05
     * @param array|null $old     прежняя структура (p / smk / ball сохраняем)
     */
    public static function fromManual(array $values, int $count, array $sigPost = [], ?array $old = null): array {
        $out = [
            'aligned' => array_fill(0, $count, null),
            'p'       => $old['p'] ?? array_fill(0, $count, null),
            'sig'     => array_fill(0, $count, false),
            'smk'     => $old['smk'] ?? array_fill(0, $count, ''),
            'ball'    => $old['ball'] ?? array_fill(0, $count, null),
            'rows'    => $old['rows'] ?? [],
            'error'   => null, // оператор поправил вручную — прежняя ошибка OCR снята
        ];
        foreach ($values as $i => $v) {
            $i = (int) $i;
            if ($i < 0 || $i >= $count) continue;
            $v = trim((string) $v);
            $out['aligned'][$i] = $v === '' ? null : (float) str_replace([',', '−', '–'], ['.', '-', '-'], $v);
        }
        for ($i = 0; $i < $count; $i++) {
            $out['sig'][$i] = !empty($sigPost[$i]);
            $wasSig = ($out['p'][$i] ?? null) !== null && $out['p'][$i] < self::SIG;
            // Флажок оператора главнее распознанного p — но только если он его менял.
            if ($out['sig'][$i] !== $wasSig) {
                $out['p'][$i] = $out['sig'][$i] ? self::SIG - 0.001 : self::SIG + 0.001;
            }
        }
        return $out;
    }

    /**
     * Decode the stored phys_json into the canonical structure. Backward
     * compatible: older profiles stored a plain aligned array, and profiles
     * saved before СМК-парсинга не имеют полей smk/ball.
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
            'smk'     => array_map('strval', $norm($data['smk'] ?? [], '')),
            'ball'    => $norm($data['ball'] ?? [], null),
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
