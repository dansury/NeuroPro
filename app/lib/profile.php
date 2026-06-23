<?php
/**
 * Turns a parsed Эгоскоп workbook into a structured cognitive profile:
 * client metadata, detected test type, and the per-parameter cognitive scores
 * that drive the radar chart.
 *
 * The physiological side (X/Y/Z/p/Зна) is NOT in the workbook — it comes from
 * the OCR'd "смысло-эмоциональная значимость" screenshot (see Ocr) and is
 * merged later by the chart/interpretation layer.
 */

require_once __DIR__ . '/excel.php';

final class Profile {
    /** Known test types → prompt key. Matched against the methodic string. */
    public const TEST_TYPES = [
        'СМУ'  => ['key' => 'smu', 'label' => 'Структура мотивации участия (СМУ)'],
        'LSI'  => ['key' => 'lsi', 'label' => 'Индекс жизненного стиля (ИЖС/LSI)'],
        'ИЖС'  => ['key' => 'lsi', 'label' => 'Индекс жизненного стиля (ИЖС/LSI)'],
    ];

    /** Compact axis labels for the radar chart (full label → short), matching the
     *  original NeuroPro report so long Russian names don't overflow the diagram. */
    public const SHORT_LABELS = [
        'Познавательный мотив' => "Познавательный\nмотив",
        'Состязательный мотив' => "Состязательный\nмотив",
        'Мотив достижения успеха' => "Достижение\nуспеха",
        'Внутренний мотив' => "Внутренний\nмотив",
        'Мотив значения результатов' => "Значение\nрезультатов",
        'Мотив сложности заданий' => "Сложность\nзаданий",
        'Мотив инициации' => "Мотив\nинициации",
        'Мотив самооценки волевого усилия' => "Самооценка\nволевого усилия",
        'Мотив самомобилизации' => "Мотив\nсамомобилизации",
        'Мотив самооценки личностного потенциала' => "Самооценка\nпотенциала",
        'Мотив личностного осмысления работы' => "Осмысление\nработы",
        'Мотив позитивного личностного ожидания' => "Позитивное\nожидание",
    ];

    public static function shortLabel(string $full): string {
        return self::SHORT_LABELS[$full] ?? $full;
    }

    /** Build a profile array from a spreadsheet file. */
    public static function fromFile(string $path): array {
        $book = Excel::read($path);
        return self::fromSheets($book['sheets']);
    }

    public static function fromSheets(array $sheets): array {
        $meta = self::extractMeta($sheets);
        $scores = self::extractScores($sheets);
        $testKey = self::detectTestKey($meta['methodic']);

        return [
            'name'      => $meta['name'],
            'age'       => $meta['age'],
            'sex'       => $meta['sex'],
            'date'      => $meta['date'],
            'methodic'  => $meta['methodic'],
            'test_key'  => $testKey,
            'scores'    => $scores,          // [['n'=>1,'label'=>..,'score'=>9.0], ...]
            'score_max' => self::scoreMax($scores, $testKey),
        ];
    }

    /** Pull name/age/sex/date/methodic, preferring the clean `import` sheet. */
    private static function extractMeta(array $sheets): array {
        $meta = ['name' => '', 'age' => '', 'sex' => '', 'date' => '', 'methodic' => ''];

        // `import` sheet: rows like ["2","","","Name=","Дронов Андрей"].
        $import = $sheets['import'] ?? [];
        $map = ['Age=' => 'age', 'Sex=' => 'sex', 'Name=' => 'name', 'Date=' => 'date', 'Methodic=' => 'methodic'];
        foreach ($import as $row) {
            foreach ($row as $i => $cell) {
                $key = is_string($cell) ? trim($cell) : '';
                if (isset($map[$key]) && isset($row[$i + 1])) {
                    $meta[$map[$key]] = self::cellStr($row[$i + 1]);
                }
            }
        }

        // Fallback: `in` sheet header (ФИО / Возраст / Пол / Методика / Дата).
        if ($meta['name'] === '' || $meta['methodic'] === '') {
            $labels = [
                'ФИО' => 'name', 'Возраст' => 'age', 'Пол' => 'sex',
                'Методика' => 'methodic', 'Дата' => 'date',
            ];
            foreach (($sheets['in'] ?? []) as $row) {
                $key = isset($row[0]) ? trim((string) self::cellStr($row[0])) : '';
                if (isset($labels[$key]) && $meta[$labels[$key]] === '' && isset($row[1])) {
                    $meta[$labels[$key]] = self::cellStr($row[1]);
                }
            }
        }
        return $meta;
    }

    /**
     * Extract the per-parameter cognitive scores. Strategy: find the table whose
     * header contains "Баллы" (СМУ) or a percentage column (LSI); each data row
     * is [n, label, score]. Falls back to scanning the `in` sheet block.
     */
    private static function extractScores(array $sheets): array {
        $in = $sheets['in'] ?? [];
        $scores = [];
        $started = false;
        foreach ($in as $row) {
            $joined = implode(' ', array_map([self::class, 'cellStr'], $row));
            // The scores table header carries the "Баллы" (СМУ) or "%" (LSI) column.
            if (!$started) {
                if (mb_strpos($joined, 'Баллы') !== false || mb_strpos($joined, '%') !== false) {
                    $started = true;
                }
                continue;
            }
            // Data rows: a numeric index, a text label, and a numeric score.
            [$n, $label, $score] = self::scoreRow($row);
            if ($label === '' || $score === null || preg_match('/^ReCalc/i', $label)) {
                if ($scores) break; // table ended
                continue;
            }
            $scores[] = ['n' => $n ?: count($scores) + 1, 'label' => $label, 'score' => $score];
        }
        return $scores;
    }

    /** Locate [index, label, score] within a row regardless of leading blanks. */
    private static function scoreRow(array $row): array {
        $n = 0; $label = ''; $score = null;
        $vals = array_values($row);
        for ($i = 0; $i < count($vals); $i++) {
            $v = $vals[$i];
            if (is_float($v) && $label === '' && $n === 0 && $v >= 1 && $v <= 99 && floor($v) === $v) {
                $n = (int) $v;
            } elseif (is_string($v) && trim($v) !== '' && $label === '') {
                $label = trim($v);
            } elseif ($label !== '' && is_float($v)) {
                $score = (float) $v;
                break;
            }
        }
        return [$n, $label, $score];
    }

    private static function detectTestKey(string $methodic): string {
        $m = mb_strtoupper($methodic);
        foreach (self::TEST_TYPES as $needle => $info) {
            if (mb_strpos($m, mb_strtoupper($needle)) !== false) return $info['key'];
        }
        return '';
    }

    /** СМУ uses a 0–10 scale; LSI is reported as a percentage (0–100). */
    private static function scoreMax(array $scores, string $testKey): int {
        if ($testKey === 'lsi') return 100;
        $max = 10;
        foreach ($scores as $s) { if ($s['score'] > $max) $max = (int) ceil($s['score']); }
        return $max <= 10 ? 10 : 100;
    }

    private static function cellStr($v): string {
        if (is_float($v)) {
            return floor($v) === $v ? (string) (int) $v : (string) $v;
        }
        return (string) $v;
    }
}
