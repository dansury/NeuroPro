<?php
/**
 * Детерминированный расчёт ВСЕХ показателей отчёта и их соотношений.
 *
 * Конституция, принцип I: математика — код, нейросеть — только текст. До этого
 * файла уровни выраженности, положение относительно медианы и категории шкал
 * считала модель — и путала их (напр. шкала с Знач. = −12 при p<0.05, то есть
 * достоверно НИЖЕ медианы, описывалась как «тело реагирует на тему»). Теперь
 * модель получает готовый расчёт и только пишет по нему текст.
 *
 * Что считается здесь:
 *  - уровень выраженности — в процентах от максимума ИМЕННО ЭТОЙ шкалы
 *    (у Басса-Дарки максимумы разные: 5…13, сырые баллы несравнимы);
 *  - положение физиологии относительно медианы — строго по знаку «Знач.»
 *    (модуль не значит ничего, кроме силы отклонения);
 *  - достоверность — по p из столбца «Достоверность»;
 *  - категория шкалы — по таблице «уровень × физиология × достоверность»;
 *  - индексы методики — суммой шкал с нормами.
 */

declare(strict_types=1);

require_once __DIR__ . '/profile.php';
require_once __DIR__ . '/phys.php';

final class Metrics {
    /** Границы уровня выраженности в % от максимума своей шкалы. */
    public const LOW_PCT = 40.0;
    public const HIGH_PCT = 60.0;

    /**
     * Полуширина «полосы медианы» — доля предела шкалы физиологии. |Знач.| в этих
     * пределах = отклонения по сути нет, независимо от p. Задаётся долей, а не
     * абсолютом, потому что «Знач.» у разных методик разного порядка: у
     * Басса-Дарки это целые −12…+6, у СМУ — дроби вида −1.73.
     */
    public const MID_BAND_FRAC = 0.05;
    public const MID_BAND_MIN = 0.5;

    /**
     * Лестница пределов шкалы физиологии. Предел выбирается как первая ступень,
     * не меньшая максимума |Знач.| в профиле: шкала диаграммы не пляшет от
     * отчёта к отчёту из-за одного значения (было: предел = сам максимум,
     * поэтому −12 всегда упиралось в центр, а −3 в другом отчёте — тоже).
     */
    public const PHYS_SCALE_LADDER = [1.0, 2.0, 3.0, 5.0, 10.0, 15.0, 20.0, 30.0, 50.0, 100.0];

    /** Категории шкал: ключ → [заголовок раздела, короткая метка, смысл для промпта]. */
    public const CATEGORIES = [
        'hidden' => [
            'title' => 'Скрытое напряжение',
            'short' => 'скрытое напряжение',
            'meaning' => 'в ответах тема почти не признаётся, а тело откликается достоверно сильнее медианы: расхождение между самоописанием и телесной реакцией (вытеснение или социально желательные ответы)',
        ],
        'strained' => [
            'title' => 'Выраженные напряжённые формы',
            'short' => 'напряжённая форма',
            'meaning' => 'и ответы, и тело согласованно показывают напряжение: осознаваемый и энергозатратный способ реагирования, требует внимания в первую очередь',
        ],
        'habitual' => [
            'title' => 'Привычные формы',
            'short' => 'привычная форма',
            'meaning' => 'форма выражена в ответах, но телесного отклика на неё нет (Знач. ниже медианы): способ реагирования «встроен» и не стоит человеку внутреннего напряжения — именно поэтому его труднее всего заметить самому',
        ],
        'calm' => [
            'title' => 'Эмоционально нейтральные зоны',
            'short' => 'эмоционально не задевает',
            'meaning' => 'тема присутствует в ответах умеренно, а телесно достоверно НЕ отзывается: эмоционально не задевает',
        ],
        'variable' => [
            'title' => 'Реакции средней выраженности',
            'short' => 'вариативно, зависит от контекста',
            'meaning' => 'физиология недостоверна или у самой медианы: реакция вариативна и зависит от ситуации',
        ],
        'skip' => [
            'title' => '',
            'short' => '—',
            'meaning' => 'низкий балл без достоверного напряжения — в отчёте не упоминается',
        ],
    ];

    /**
     * Полный расчёт по профилю и разобранной физиологии.
     *
     * @param array      $profile профиль (см. Profile::fromSheets)
     * @param array|null $phys    структура Phys::decode (aligned/p/sig/smk) или null
     * @return array ['axes'=>[…], 'groups'=>[cat=>[axisIdx,…]], 'indices'=>[…],
     *                'phys_scale'=>float, 'has_phys'=>bool, 'warnings'=>[string,…]]
     */
    public static function build(array $profile, ?array $phys = null): array {
        $testKey = (string) ($profile['test_key'] ?? '');
        $scores = $profile['scores'] ?? [];
        $axes = [];
        $warnings = [];

        // Предел шкалы физиологии считаем ДО осей: от него зависит «полоса медианы».
        $znas = [];
        foreach ($scores as $i => $s) $znas[$i] = self::floatOrNull($phys['aligned'][$i] ?? null);
        $physScale = self::physScale($znas);
        $midBand = max(self::MID_BAND_MIN, self::MID_BAND_FRAC * $physScale);

        foreach ($scores as $i => $s) {
            $label = trim((string) $s['label']);
            $score = (float) $s['score'];
            $scaleMax = self::scaleMax($testKey, $label, $profile);
            $pct = $scaleMax > 0 ? $score / $scaleMax * 100.0 : 0.0;

            $zna = $znas[$i];
            $p   = self::floatOrNull($phys['p'][$i] ?? null);
            $sig = !empty($phys['sig'][$i]);
            $smk = (string) ($phys['smk'][$i] ?? '');

            $level = self::level($pct);
            $physState = self::physState($zna, $midBand);
            $category = self::category($level, $physState, $sig);

            // Сверка столбца «Балл» со скриншота с баллом из Excel: расходятся —
            // значит скриншот от другого теста или OCR ошибся. Excel — источник
            // истины, значение не подменяем, но оператора предупреждаем.
            $ball = self::floatOrNull($phys['ball'][$i] ?? null);
            if ($ball !== null && abs($ball - $score) > 0.01) {
                $warnings[] = sprintf('%s: балл на скриншоте (%s) не совпадает с баллом из Excel (%s) — проверьте, что скриншот от этого же теста.',
                    $label, self::num($ball), self::num($score));
            }

            $axes[] = [
                'n' => (int) ($s['n'] ?? $i + 1),
                'label' => $label,
                'short' => Profile::shortLabel($label),
                'score' => $score,
                'scale_max' => $scaleMax,
                'pct' => round($pct, 1),
                'level' => $level,
                'level_label' => self::LEVEL_LABELS[$level],
                'zna' => $zna,
                'p' => $p,
                'sig' => $sig,
                'p_label' => self::pLabel($p, $sig),
                'phys_state' => $physState,
                'phys_label' => self::physLabel($physState, $sig),
                'phys_dir' => self::physDir($physState),
                'smk' => $smk,
                'smk_label' => self::smkLabel($smk),
                'category' => $category,
                'category_title' => self::CATEGORIES[$category]['title'],
                'category_short' => self::CATEGORIES[$category]['short'],
            ];
        }

        // Соотношение шкал МЕЖДУ СОБОЙ: у Басса-Дарки почти все шкалы могут
        // оказаться «высокими» по доле от максимума, и тогда абсолютный уровень
        // перестаёт различать профиль. Ранг по доле показывает, что в этом
        // профиле выражено сильнее, а что — фоново.
        self::rank($axes, 'pct', 'rank', 'rank_label');
        self::rank($axes, 'zna', 'phys_rank', null);

        // Опорный факт по каждой оси — одна строка, которой текст не должен
        // противоречить.
        foreach ($axes as $i => $axis) {
            $axes[$i]['fact'] = self::fact($axis);
        }

        $groups = array_fill_keys(array_keys(self::CATEGORIES), []);
        foreach ($axes as $i => $axis) $groups[$axis['category']][] = $i;

        return [
            'test_key' => $testKey,
            'axes' => $axes,
            'groups' => $groups,
            'indices' => $testKey === 'bd' ? Profile::bdIndices($scores) : [],
            'phys_scale' => $physScale,
            'has_phys' => Phys::hasData($phys),
            'warnings' => $warnings,
        ];
    }

    public const LEVEL_LABELS = ['low' => 'низкий', 'mid' => 'средний', 'high' => 'высокий'];

    /** Уровень выраженности по доле от максимума своей шкалы. */
    public static function level(float $pct): string {
        if ($pct < self::LOW_PCT) return 'low';
        return $pct < self::HIGH_PCT ? 'mid' : 'high';
    }

    /**
     * Положение относительно медианы — ТОЛЬКО по знаку «Знач.».
     * Большое отрицательное число — не напряжение, а достоверно низкое напряжение.
     */
    public static function physState(?float $zna, float $midBand = self::MID_BAND_MIN): ?string {
        if ($zna === null) return null;
        if (abs($zna) <= $midBand) return 'median';
        return $zna > 0 ? 'above' : 'below';
    }

    /**
     * Расставляет ранги по числовому полю (1 = наибольшее значение) и, для
     * когнитивной доли, словесную метку положения шкалы в профиле.
     */
    private static function rank(array &$axes, string $field, string $rankKey, ?string $labelKey): void {
        $vals = [];
        foreach ($axes as $i => $a) {
            if ($a[$field] !== null) $vals[$i] = (float) $a[$field];
        }
        arsort($vals);
        $total = count($vals);
        $place = 0;
        foreach ($vals as $i => $_) {
            $place++;
            $axes[$i][$rankKey] = $place;
            if ($labelKey !== null) {
                // Терции профиля: ведущие / серединные / фоновые шкалы.
                $third = $total > 0 ? ($place - 1) / $total : 0.0;
                $axes[$i][$labelKey] = $third < 1 / 3 ? 'ведущая' : ($third < 2 / 3 ? 'серединная' : 'фоновая');
            }
        }
        foreach ($axes as $i => $a) {
            if (!isset($a[$rankKey])) {
                $axes[$i][$rankKey] = null;
                if ($labelKey !== null) $axes[$i][$labelKey] = '—';
            }
        }
    }

    /**
     * Категория шкалы — единственное место, где решается, в какой раздел отчёта
     * попадёт шкала. Раньше эту таблицу «исполняла» модель по тексту промпта и
     * ошибалась: шкала попадала в два раздела сразу или в раздел, условиям
     * которого не удовлетворяла.
     */
    public static function category(string $level, ?string $physState, bool $sig): string {
        // Достоверное напряжение (Знач. > 0, p < 0.05).
        if ($physState === 'above' && $sig) {
            return $level === 'high' ? 'strained' : 'hidden';
        }
        // Достоверно НИЖЕ медианы: тело на тему не откликается.
        if ($physState === 'below' && $sig) {
            return match ($level) {
                'high' => 'habitual',
                'mid'  => 'calm',
                default => 'skip',
            };
        }
        // Ниже медианы без достоверности: для выраженной формы это всё равно
        // «привычная» — телесной цены нет; для слабой — не о чем говорить.
        if ($physState === 'below') {
            return $level === 'high' ? 'habitual' : 'skip';
        }
        // Около медианы, физиология не распознана или недостоверное напряжение.
        return $level === 'low' ? 'skip' : 'variable';
    }

    /**
     * Предел шкалы физиологии: ближайшая ступень лестницы, не меньшая максимума
     * |Знач.|. Стабильная величина вместо «максимума этого профиля» — иначе
     * самое большое значение всегда упирается в край, и два отчёта с разными
     * данными выглядят одинаково.
     *
     * @param array $znas значения «Знач.» (null допустим)
     */
    public static function physScale(array $znas): float {
        $m = 0.0;
        foreach ($znas as $v) {
            if ($v !== null && abs((float) $v) > $m) $m = abs((float) $v);
        }
        foreach (self::PHYS_SCALE_LADDER as $step) {
            if ($m <= $step) return $step;
        }
        return (float) end(self::PHYS_SCALE_LADDER);
    }

    /** Максимум конкретной шкалы: пошкальный (Басса-Дарки) или общий для теста. */
    public static function scaleMax(string $testKey, string $label, array $profile): int {
        $own = Profile::scaleMax($testKey, $label);
        if ($own !== null) return $own;
        return (int) ($profile['score_max'] ?? 10);
    }

    /** Максимумы всех осей — для нормировки диаграммы. */
    public static function scaleMaxes(array $profile): array {
        $testKey = (string) ($profile['test_key'] ?? '');
        $out = [];
        foreach ($profile['scores'] ?? [] as $s) {
            $out[] = self::scaleMax($testKey, trim((string) $s['label']), $profile);
        }
        return $out;
    }

    private static function pLabel(?float $p, bool $sig): string {
        if ($p === null) return $sig ? 'p<0.05' : '—';
        return $p < Phys::SIG ? ($p < 0.01 ? 'p<0.01' : 'p<0.05') : 'p>0.05';
    }

    /** Только направление, без достоверности — для узкой колонки таблицы. */
    private static function physDir(?string $state): string {
        return match ($state) {
            'above'  => 'выше медианы',
            'below'  => 'ниже медианы',
            'median' => 'у медианы',
            default  => '—',
        };
    }

    private static function physLabel(?string $state, bool $sig): string {
        return match ($state) {
            'above'  => $sig ? 'достоверно выше медианы' : 'выше медианы (недостоверно)',
            'below'  => $sig ? 'достоверно ниже медианы' : 'ниже медианы (недостоверно)',
            'median' => 'у медианы',
            default  => '—',
        };
    }

    /** Доминирующий параметр из столбца СМК: «Z>X>Y» → «преобладает Z». */
    private static function smkLabel(string $smk): string {
        if ($smk === '') return '';
        $strong = str_contains($smk, '*');
        $letter = strtoupper(substr(ltrim($smk, '*'), 0, 1));
        if (!in_array($letter, ['X', 'Y', 'Z'], true)) return '';
        return ($strong ? 'значительно преобладает ' : 'преобладает ') . $letter;
    }

    /**
     * Опорный факт: одна фраза, которую текст отчёта обязан соблюдать. Явно
     * называет и уровень, и направление физиологии — чтобы модель не могла
     * пересказать отрицательное «Знач.» как телесный отклик.
     */
    private static function fact(array $a): string {
        $lvl = sprintf('%s уровень (%s из %d, %s%%, %s по профилю — %d-я из шкал)',
            $a['level_label'], self::num($a['score']), $a['scale_max'], self::num($a['pct']),
            $a['rank_label'], (int) $a['rank']);
        if ($a['zna'] === null) {
            return $lvl . '; физиология не распознана — о телесной реакции не пишем';
        }
        $phys = match ($a['phys_state']) {
            'above' => $a['sig']
                ? 'тело откликается на тему достоверно сильнее обычного'
                : 'телесный отклик выше медианы, но нестабильный',
            'below' => $a['sig']
                ? 'тело на тему достоверно НЕ откликается — эмоционально не задевает'
                : 'телесного отклика на тему нет',
            default => 'телесный отклик у медианы — ни напряжения, ни явного спокойствия',
        };
        return $lvl . '; Знач. ' . self::num($a['zna']) . ' (' . $a['phys_label'] . ') — ' . $phys;
    }

    private static function floatOrNull($v): ?float {
        return ($v === null || $v === '') ? null : (float) $v;
    }

    /** Компактное число: 9.0 → «9», −3.5 → «-3.5». */
    public static function num(float $v): string {
        return rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
    }
}
