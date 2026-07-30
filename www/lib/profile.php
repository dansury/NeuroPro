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
        'СМУ'         => ['key' => 'smu', 'label' => 'Структура мотивации участия (СМУ)'],
        'LSI'         => ['key' => 'lsi', 'label' => 'Индекс жизненного стиля (ИЖС/LSI)'],
        'ИЖС'         => ['key' => 'lsi', 'label' => 'Индекс жизненного стиля (ИЖС/LSI)'],
        'Басса-Дарки' => ['key' => 'bd',  'label' => 'Опросник Басса-Дарки (агрессивность)'],
        'Басса'       => ['key' => 'bd',  'label' => 'Опросник Басса-Дарки (агрессивность)'], // вариант написания без дефиса
    ];

    /** Заголовок диаграммы по типу теста (для страницы и отчёта). */
    public const CHART_TITLES = [
        'smu' => 'Структура мотивации',
        'lsi' => 'Индекс жизненного стиля',
        'bd'  => 'Профиль агрессивности',
    ];

    /**
     * Басса-Дарки: максимум каждой шкалы = число её вопросов (шкалы НЕ сравнимы
     * по сырым баллам между собой). Ключ — точная подпись шкалы из выгрузки.
     */
    public const BD_SCALE_MAX = [
        'Физическая агрессия' => 10,
        'Косвенная агрессия'  => 9,
        'Раздражение'         => 11,
        'Негативизм'          => 5,
        'Обида'               => 8,
        'Подозрительность'    => 10,
        'Вербальная агрессия' => 13,
        'Чувство вины'        => 9,
    ];

    /**
     * ИЖС/LSI: у каждой защиты свой максимум = число её вопросов в опроснике
     * Плутчика–Келлермана–Конте (сырые баллы разных защит НЕ сравнимы между
     * собой — их выравнивает именно доля от своего максимума). Эти же максимумы
     * лежат в самой выгрузке Эгоскопа, поэтому «%» из файла и наш расчёт
     * совпадают до десятых. Ключ — точная подпись защиты из выгрузки.
     */
    public const LSI_SCALE_MAX = [
        'Отрицание'              => 13,
        'Подавление'             => 12,
        'Регрессия'              => 14,
        'Компенсация'            => 10,
        'Проекция'               => 13,
        'Замещение'              => 13,
        'Интеллектуализация'     => 12,
        'Реактивное образование' => 10,
    ];

    /** Индексы Басса-Дарки — детерминированные суммы шкал + границы нормы. */
    public const BD_INDICES = [
        'Индекс агрессивности' => ['scales' => ['Физическая агрессия', 'Раздражение', 'Вербальная агрессия'], 'min' => 17.0, 'max' => 25.0, 'cap' => 34],
        'Индекс враждебности'  => ['scales' => ['Обида', 'Подозрительность'], 'min' => 3.5, 'max' => 10.0, 'cap' => 18],
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

    /**
     * СМУ uses a 0–10 scale; LSI и Басса-Дарки — сырые баллы, но у каждой шкалы
     * свой максимум (число её вопросов), поэтому единый `score_max` для них — это
     * лишь ЗАПАСНОЙ предел на случай нераспознанной подписи шкалы; реальный
     * максимум берётся пошкально в scaleMax(). Для обоих берём самый длинный —
     * так запасная нормировка хотя бы не завышает балл.
     */
    public static function scoreMax(array $scores, string $testKey): int {
        if ($testKey === 'lsi') return max(self::LSI_SCALE_MAX);
        if ($testKey === 'bd') return max(self::BD_SCALE_MAX);
        $max = 10;
        foreach ($scores as $s) { if ($s['score'] > $max) $max = (int) ceil($s['score']); }
        return $max <= 10 ? 10 : 100;
    }

    public static function chartTitle(string $testKey): string {
        return self::CHART_TITLES[$testKey] ?? 'Профиль показателей';
    }

    /**
     * Максимум конкретной шкалы, если у теста он пошкальный (Басса-Дарки и ИЖС:
     * у каждой шкалы своё число вопросов). Подпись нормализуем, чтобы перенос
     * слова с дефисом в выгрузке («Интеллектуа-\nлизация») не ломал поиск.
     */
    public static function scaleMax(string $testKey, string $label): ?int {
        $table = match ($testKey) {
            'bd'  => self::BD_SCALE_MAX,
            'lsi' => self::LSI_SCALE_MAX,
            default => null,
        };
        if ($table === null) return null;
        $key = self::normLabel($label);
        foreach ($table as $name => $max) {
            if (self::normLabel($name) === $key) return $max;
        }
        return null;
    }

    /** Подпись шкалы для поиска: убираем переносы-дефисы и схлопываем пробелы. */
    private static function normLabel(string $label): string {
        $s = str_replace(["-\n", "-\r\n"], '', $label);      // перенос слова с дефисом
        $s = preg_replace('/\s+/u', ' ', $s);                 // остальные переносы → пробел
        return trim(mb_strtolower($s ?? $label));
    }

    /**
     * Индексы Басса-Дарки, посчитанные из баллов Excel (Конституция, принцип I:
     * математика, не нейросеть). Индекс пропускается, если какой-то из его шкал
     * нет в данных — ничего не выдумываем (принцип II).
     */
    public static function bdIndices(array $scores): array {
        $byLabel = [];
        foreach ($scores as $s) $byLabel[trim((string) $s['label'])] = (float) $s['score'];
        $out = [];
        foreach (self::BD_INDICES as $name => $ix) {
            $sum = 0.0; $have = 0;
            foreach ($ix['scales'] as $l) {
                if (isset($byLabel[$l])) { $sum += $byLabel[$l]; $have++; }
            }
            if ($have !== count($ix['scales'])) continue;
            $verdict = $sum < $ix['min'] ? 'ниже нормы' : ($sum > $ix['max'] ? 'выше нормы' : 'в норме');
            $out[$name] = ['value' => $sum, 'min' => $ix['min'], 'max' => $ix['max'], 'cap' => $ix['cap'], 'verdict' => $verdict];
        }
        return $out;
    }

    private static function cellStr($v): string {
        if (is_float($v)) {
            return floor($v) === $v ? (string) (int) $v : (string) $v;
        }
        return (string) $v;
    }
}
