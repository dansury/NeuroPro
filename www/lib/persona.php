<?php
declare(strict_types=1);

/**
 * ЦИФРОВОЙ АВАТАР КЛИЕНТА — как подавать ему материал отчёта.
 *
 * Один и тот же расчёт можно рассказать по-разному: кому-то короче и проще,
 * кому-то подробнее и точнее. Аватар отвечает не на вопрос «что у клиента в
 * профиле» (это делает Metrics), а на вопрос «каким текстом это до него
 * дойдёт»: объём, язык, тон, опора, форма и чувствительность к прямым
 * формулировкам.
 *
 * ЭТО МАТЕМАТИКА, А НЕ НЕЙРОСЕТЬ (Конституция, принцип I). Каждый признак
 * выводится из уже посчитанных величин — соотношения каналов СМК по профилю,
 * разделов отчёта, уровней шкал — по правилам, записанным здесь же, и у
 * каждого признака есть ОСНОВАНИЕ: строка с числами, по которым он выбран.
 * Нейросеть получает готовые указания и не выбирает способ подачи сама.
 *
 * ЧЕГО АВАТАР НЕ ДЕЛАЕТ. Он не попадает в отчёт клиента: это внутренняя
 * техническая информация оператора (требование заказчика). В промпт уходят
 * только указания по подаче, с прямым запретом пересказывать их клиенту.
 *
 * ПРОФИЛЬ ПОСТОЯННО ОБНОВЛЯЕТСЯ. Аватар считается по ВСЕМ живым анализам
 * клиента (одно нормализованное ФИО = один клиент): значение признака берётся
 * из самого свежего теста, а рядом хранится, в скольких анализах оно
 * повторилось — так видно, устойчива черта или это разовая картина. Оператор
 * может поправить любой признак и дописать заметку; правка живёт до тех пор,
 * пока он её не снимет, и пересчёт её не затирает.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/profile.php';
require_once __DIR__ . '/phys.php';
require_once __DIR__ . '/metrics.php';

final class Persona {
    /**
     * Признаки подачи: ключ → заголовок, подсказка оператору и варианты.
     * У варианта два поля: `label` — что видит оператор, `directive` — что
     * уходит в промпт. Правка оператора меняет вариант, а вместе с ним и
     * указание нейросети: список закрытый, поэтому «подкрутить» текст отчёта
     * произвольной фразой через аватар нельзя (для этого есть промпты).
     */
    public const TRAITS = [
        'volume' => [
            'title' => 'Объём отчёта',
            'hint'  => 'сколько текста клиент дочитает',
            'options' => [
                'short' => [
                    'label' => 'короче, тезисно',
                    'directive' => 'ОБЪЁМ: пиши предельно сжато. Один короткий абзац на показатель, '
                        . 'никаких вводных и подводок — клиент читает по диагонали и до конца дойдёт только короткий текст.',
                ],
                'normal' => [
                    'label' => 'обычный',
                    'directive' => 'ОБЪЁМ: обычный — по одному ёмкому абзацу на показатель, без вводных абзацев.',
                ],
                'detailed' => [
                    'label' => 'подробнее, с разбором',
                    'directive' => 'ОБЪЁМ: клиент готов читать разбор — к каждому выводу добавь одно поясняющее '
                        . 'предложение «как это выглядит в жизни». Это НЕ разрешение на повторы и не повод '
                        . 'растягивать: новые предложения должны нести новое, иначе не пиши их.',
                ],
            ],
        ],
        'language' => [
            'title' => 'Язык',
            'hint'  => 'словарь и длина предложений',
            'options' => [
                'plain' => [
                    'label' => 'простой, бытовой',
                    'directive' => 'ЯЗЫК: бытовой, короткими предложениями. Никаких оборотов, которые надо '
                        . 'перечитывать; там, где просится сложное слово, ставь простое.',
                ],
                'business' => [
                    'label' => 'деловой',
                    'directive' => 'ЯЗЫК: спокойный деловой, как в служебной записке — без канцелярита и без «душевности».',
                ],
                'precise' => [
                    'label' => 'точные формулировки',
                    'directive' => 'ЯЗЫК: клиент разбирается сам и замечает приблизительность — формулируй строго '
                        . 'и определённо, без «как бы», «в целом», «скорее». Точность НЕ означает терминов: '
                        . 'служебные обозначения методики по-прежнему запрещены.',
                ],
            ],
        ],
        'tone' => [
            'title' => 'Тон',
            'hint'  => 'насколько прямо можно называть вещи',
            'options' => [
                'soft' => [
                    'label' => 'мягкий, поддерживающий',
                    'directive' => 'ТОН: мягкий и поддерживающий. Неприятные места называй прямо, но без ярлыков '
                        . 'и без слов, которые звучат как диагноз или упрёк.',
                ],
                'even' => [
                    'label' => 'ровный деловой',
                    'directive' => 'ТОН: ровный и деловой — ни утешения, ни давления.',
                ],
                'direct' => [
                    'label' => 'прямой, без смягчений',
                    'directive' => 'ТОН: прямой. Смягчающие обороты («возможно, отчасти, в некоторой степени») '
                        . 'клиент воспринимает как уклончивость — говори по существу с первого предложения.',
                ],
            ],
        ],
        'support' => [
            'title' => 'На что опираться',
            'hint'  => 'чем аргументировать выводы',
            'options' => [
                'facts' => [
                    'label' => 'на факты и логику',
                    'directive' => 'ОПОРА: логика и наблюдаемые факты. Каждый вывод — следствие того, что видно '
                        . 'на картинках; сначала наблюдение, потом вывод.',
                ],
                'feelings' => [
                    'label' => 'на переживания',
                    'directive' => 'ОПОРА: внутреннее состояние. Начинай с того, как это переживается изнутри, '
                        . 'и только потом переходи к выводу.',
                ],
                'action' => [
                    'label' => 'на действия и результат',
                    'directive' => 'ОПОРА: поведение и результат. Каждый вывод показывай через то, что человек '
                        . 'делает и к чему это приводит, а не через переживания.',
                ],
            ],
        ],
        'structure' => [
            'title' => 'Форма подачи',
            'hint'  => 'как разбит текст',
            'options' => [
                'flow' => [
                    'label' => 'связный текст',
                    'directive' => 'ФОРМА: связные абзацы, списки — только там, где их требует структура отчёта.',
                ],
                'blocks' => [
                    'label' => 'короткие блоки',
                    'directive' => 'ФОРМА: короткие блоки. Абзац — не длиннее трёх предложений, мысль должна '
                        . 'схватываться с первой строки абзаца.',
                ],
            ],
        ],
        'sensitivity' => [
            'title' => 'Чувствительность к прямым формулировкам',
            'hint'  => 'насколько легко текст задевает',
            'options' => [
                'usual' => [
                    'label' => 'обычная',
                    'directive' => 'ЧУВСТВИТЕЛЬНОСТЬ: обычная — специальных смягчений не нужно.',
                ],
                'high' => [
                    'label' => 'повышенная',
                    'directive' => 'ЧУВСТВИТЕЛЬНОСТЬ: повышенная. Ни одной оценки личности — только описание '
                        . 'способа реагирования; ни одного «вы не…», «вам не хватает», «проблема в том, что». '
                        . 'Там, где вывод неприятен, рядом обязательно то, чем этот способ клиенту полезен.',
                ],
            ],
        ],
    ];

    /**
     * Доли ведущих каналов, при которых признак считается выраженным. Пороги
     * заданы здесь одним местом: канал должен вести БОЛЬШИНСТВО распознанных
     * шкал (0.5), а для формы подачи достаточно устойчивой доли (0.4) — форма
     * влияет на текст меньше всего, и требовать для неё большинства значило бы
     * почти никогда её не менять.
     */
    public const CHANNEL_MAJOR = 0.5;
    public const CHANNEL_STEADY = 0.4;

    /**
     * Шкалы, высокий уровень которых говорит о склонности к разбору и точности
     * («интеллектуализация» из формулировки заказчика). Совпадение — по корню
     * слова: в выгрузках Эгоскопа одна и та же защита названа то
     * «Интеллектуализация», то «Рационализация (интеллектуализация)».
     */
    public const ANALYTIC_ROOTS = ['интеллектуализ', 'рационализ'];

    /**
     * Шкалы, высокий уровень которых означает: прямая формулировка легко читается
     * как обвинение. Ключ теста → корни подписей шкал.
     */
    public const TENDER_ROOTS = [
        'bd'  => ['обида', 'подозрительн', 'чувство вины'],
        'lsi' => ['отрицание', 'вытеснение', 'проекция'],
    ];

    /** Сколько ведущих тем показывать в «Что для клиента важно». */
    public const VALUES_TOP = 3;

    /* ─────────────────────── Хранение и обновление ─────────────────────── */

    /** Один клиент = одно нормализованное ФИО. */
    public static function clientKey(string $name): string {
        $key = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        return function_exists('mb_strtolower') ? mb_strtolower($key, 'UTF-8') : strtolower($key);
    }

    /**
     * Пересчитать аватар клиента по ВСЕМ его живым анализам и сохранить.
     * Вызывается там, где данные меняются: после правки физиологии и при
     * открытии страницы результата — «профиль постоянно обновлять».
     *
     * @return array см. self::merge()
     */
    public static function sync(string $name): array {
        $key = self::clientKey($name);
        if ($key === '') return self::merge($name, self::blank(), self::stored(''));
        $computed = self::compute($key);
        $row = self::stored($key);
        Db::q(
            'INSERT INTO personas (client_key, name, traits_json, sources_json, updated_at)
             VALUES (?, ?, ?, ?, datetime(\'now\'))
             ON CONFLICT(client_key) DO UPDATE SET
               name = excluded.name, traits_json = excluded.traits_json,
               sources_json = excluded.sources_json, updated_at = excluded.updated_at',
            [$key, $name,
             json_encode($computed['traits'], JSON_UNESCAPED_UNICODE),
             json_encode(['values' => $computed['values'], 'sources' => $computed['sources']], JSON_UNESCAPED_UNICODE)]
        );
        return self::merge($name, $computed, $row);
    }

    /**
     * Аватар клиента как он есть в базе (с правкой оператора). Если его ещё не
     * считали — считаем на лету, но НЕ сохраняем: запись в базу делает sync(),
     * и генерация отчёта не должна менять данные оператора.
     */
    public static function current(string $name): array {
        $key = self::clientKey($name);
        $row = self::stored($key);
        if ($row === null || trim((string) ($row['traits_json'] ?? '')) === '') {
            return self::merge($name, $key === '' ? self::blank() : self::compute($key), $row);
        }
        $traits = json_decode((string) $row['traits_json'], true);
        $extra = json_decode((string) ($row['sources_json'] ?? ''), true);
        return self::merge($name, [
            'traits'  => is_array($traits) ? $traits : [],
            'values'  => is_array($extra['values'] ?? null) ? $extra['values'] : [],
            'sources' => is_array($extra['sources'] ?? null) ? $extra['sources'] : [],
        ], $row);
    }

    /** Правка оператора: выбранные варианты признаков и свободная заметка. */
    public static function saveOverrides(string $name, array $picked, string $notes): void {
        $key = self::clientKey($name);
        if ($key === '') return;
        $over = [];
        foreach (self::TRAITS as $tk => $def) {
            $v = trim((string) ($picked[$tk] ?? ''));
            // Пустая строка — «как посчитано»: правка снимается, а не хранится
            // копией расчёта, иначе она бы навсегда заморозила старое значение.
            if ($v !== '' && isset($def['options'][$v])) $over[$tk] = $v;
        }
        self::sync($name); // строка точно есть и посчитана по свежим данным
        Db::q('UPDATE personas SET overrides_json=?, notes=?, updated_at=datetime(\'now\') WHERE client_key=?',
            [json_encode($over, JSON_UNESCAPED_UNICODE), trim($notes), $key]);
    }

    private static function stored(string $key): ?array {
        if ($key === '') return null;
        return Db::one('SELECT * FROM personas WHERE client_key=?', [$key]);
    }

    /**
     * Расчёт + правка оператора в одном виде, пригодном и для страницы, и для
     * промпта.
     *
     * @return array{name:string,traits:array,values:array,sources:array,notes:string,updated_at:string}
     */
    private static function merge(string $name, array $computed, ?array $row): array {
        $over = json_decode((string) ($row['overrides_json'] ?? ''), true);
        $over = is_array($over) ? $over : [];
        $traits = [];
        foreach (self::TRAITS as $tk => $def) {
            $calc = $computed['traits'][$tk] ?? null;
            $value = (string) ($calc['value'] ?? array_key_first($def['options']));
            $picked = isset($over[$tk], $def['options'][$over[$tk]]) ? (string) $over[$tk] : '';
            $eff = $picked !== '' ? $picked : $value;
            $traits[$tk] = [
                'key' => $tk,
                'title' => $def['title'],
                'hint' => $def['hint'],
                'value' => $eff,
                'label' => (string) $def['options'][$eff]['label'],
                'directive' => (string) $def['options'][$eff]['directive'],
                'computed' => $value,
                'computed_label' => (string) ($def['options'][$value]['label'] ?? ''),
                'override' => $picked,
                'basis' => (string) ($calc['basis'] ?? 'данных для расчёта пока нет'),
                'agree' => (int) ($calc['agree'] ?? 0),
                'total' => (int) ($calc['total'] ?? 0),
            ];
        }
        return [
            'name' => $name,
            'traits' => $traits,
            'values' => $computed['values'] ?? [],
            'sources' => $computed['sources'] ?? [],
            'notes' => trim((string) ($row['notes'] ?? '')),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    private static function blank(): array {
        return ['traits' => [], 'values' => [], 'sources' => []];
    }

    /* ───────────────────────────── Расчёт ──────────────────────────────── */

    /**
     * Аватар по всем живым анализам клиента. Значение признака — из САМОГО
     * СВЕЖЕГО анализа (человек меняется, и старый тест не должен спорить с
     * новым), а `agree`/`total` показывают, в скольких анализах из скольких
     * оно совпало: устойчивая черта и разовая картина выглядят по-разному.
     */
    private static function compute(string $key): array {
        $rows = Db::all(
            "SELECT * FROM profiles
             WHERE deleted_at IS NULL AND status!='awaiting_screenshot'
             ORDER BY datetime(created_at) DESC, id DESC");
        $mine = [];
        foreach ($rows as $r) {
            if (self::clientKey((string) ($r['name'] ?? '')) === $key) $mine[] = $r;
        }
        if (!$mine) return self::blank();

        $perTest = [];   // traits по каждому анализу, свежие первыми
        $sources = [];
        $valueRows = [];
        foreach ($mine as $r) {
            $m = self::metricsOf($r);
            if ($m === null) continue;
            $perTest[] = self::traitsFor($m);
            $sources[] = [
                'id' => (int) $r['id'],
                'methodic' => (string) ($r['methodic'] ?? ''),
                'date' => (string) ($r['test_date'] ?? $r['created_at'] ?? ''),
            ];
            foreach (self::topValues($m) as $v) $valueRows[] = $v;
        }
        if (!$perTest) return self::blank();

        $traits = [];
        $total = count($perTest);
        foreach (self::TRAITS as $tk => $_def) {
            $value = (string) ($perTest[0][$tk]['value'] ?? '');
            if ($value === '') continue;
            $agree = 0;
            foreach ($perTest as $t) {
                if (($t[$tk]['value'] ?? '') === $value) $agree++;
            }
            $traits[$tk] = [
                'value' => $value,
                'basis' => (string) ($perTest[0][$tk]['basis'] ?? ''),
                'agree' => $agree,
                'total' => $total,
            ];
        }
        return ['traits' => $traits, 'values' => self::mergeValues($valueRows), 'sources' => $sources];
    }

    /** Расчёт по строке профиля (или null, если баллов в ней нет). */
    private static function metricsOf(array $row): ?array {
        $scores = json_decode((string) ($row['scores_json'] ?? ''), true);
        if (!is_array($scores) || !$scores) return null;
        $prof = [
            'name' => (string) ($row['name'] ?? ''), 'age' => (string) ($row['age'] ?? ''),
            'sex' => (string) ($row['sex'] ?? ''), 'date' => (string) ($row['test_date'] ?? ''),
            'methodic' => (string) ($row['methodic'] ?? ''), 'test_key' => (string) ($row['test_key'] ?? ''),
            'scores' => $scores,
            'score_max' => Profile::scoreMax($scores, (string) ($row['test_key'] ?? '')),
        ];
        return Metrics::build($prof, Phys::decode($row['phys_json'] ?? null, count($scores)));
    }

    /**
     * ПРИЗНАКИ ПОДАЧИ ПО ОДНОМУ АНАЛИЗУ.
     *
     * Гипотезы, по которым это считается (каждая — из уже посчитанных величин,
     * ни одна не спрашивает нейросеть):
     *
     *  1. Ведущий канал СМК по профилю — как человек вообще перерабатывает
     *     задевающее. X (обдумывание) → он разбирает и замечает неточность:
     *     можно подробнее и строже. Z (твёрдая позиция, действие без паузы) →
     *     длинный текст не дочитывается: короче, проще, блоками. Y (чувства) →
     *     заходит через переживание, а не через логику.
     *  2. Расхождение самоописания и тела (раздел «скрытое напряжение») — тема
     *     не признана словами, но задевает: прямая формулировка бьёт по больному,
     *     значит чувствительность повышенная, а тон мягче.
     *  3. Шкалы самой методики: высокая интеллектуализация/рационализация — язык
     *     точный; высокие обида, подозрительность, чувство вины, отрицание —
     *     чувствительность повышенная.
     *  4. Есть ли выраженные напряжённые формы — там, где напряжение проявлено
     *     и телом, и ответами, уклончивый текст читается как увиливание.
     *
     * @return array<string,array{value:string,basis:string}>
     */
    public static function traitsFor(array $m): array {
        $axes = $m['axes'] ?? [];
        if (!$axes) return [];
        $testKey = (string) ($m['test_key'] ?? '');

        $known = array_values(array_filter($axes, static fn ($a) => ($a['dominant'] ?? '') !== ''));
        $nKnown = count($known);
        $shareOf = static function (string $letter) use ($known, $nKnown): float {
            if ($nKnown === 0) return 0.0;
            $n = count(array_filter($known, static fn ($a) => $a['dominant'] === $letter));
            return $n / $nKnown;
        };
        $x = $shareOf('X');
        $y = $shareOf('Y');
        $z = $shareOf('Z');
        $pct = static fn (float $v): string => Metrics::num(round($v * 100.0, 0));
        $chan = $nKnown === 0
            ? 'соотношение X/Y/Z не распознано ни по одной шкале'
            : sprintf('ведущий канал распознан у %d шкал: обдумывание X — %s %%, чувства Y — %s %%, действие Z — %s %%',
                $nKnown, $pct($x), $pct($y), $pct($z));

        $hidden = count(array_filter($axes, static fn ($a) => ($a['category'] ?? '') === 'hidden'));
        $strained = count(array_filter($axes, static fn ($a) => ($a['category'] ?? '') === 'strained'));
        $analytic = self::highWith($axes, self::ANALYTIC_ROOTS);
        $tender = self::highWith($axes, self::TENDER_ROOTS[$testKey] ?? []);

        $out = [];

        // 1. Объём.
        $out['volume'] = match (true) {
            $x >= self::CHANNEL_MAJOR => ['value' => 'detailed',
                'basis' => $chan . ' — большинство тем клиент обдумывает, разбор он дочитает'],
            $z >= self::CHANNEL_MAJOR => ['value' => 'short',
                'basis' => $chan . ' — реакция идёт действием, а не обдумыванием: длинный текст не дочитывается'],
            default => ['value' => 'normal', 'basis' => $chan . ' — выраженного перекоса нет'],
        };

        // 2. Язык.
        $out['language'] = match (true) {
            $analytic !== '' => ['value' => 'precise',
                'basis' => 'высокий уровень по шкале «' . $analytic . '» — склонность к разбору и точности'],
            $x >= self::CHANNEL_MAJOR => ['value' => 'precise', 'basis' => $chan . ' — приблизительность будет замечена'],
            $z >= self::CHANNEL_MAJOR => ['value' => 'plain', 'basis' => $chan . ' — заходит короткая прямая фраза'],
            default => ['value' => 'business', 'basis' => $chan . ' — выраженного перекоса нет'],
        };

        // 3. Тон. Чувствительность важнее канала: смягчить текст там, где он
        //    задевает, дешевле, чем недосмягчить.
        $out['tone'] = match (true) {
            $tender !== '' => ['value' => 'soft',
                'basis' => 'высокий уровень по шкале «' . $tender . '» — прямая формулировка легко читается как упрёк'],
            $hidden > 0 => ['value' => 'soft',
                'basis' => 'шкал в разделе скрытого напряжения: ' . $hidden
                    . ' — темы задевают телесно, но словами не признаны'],
            $y >= self::CHANNEL_MAJOR => ['value' => 'soft', 'basis' => $chan . ' — темы отзываются прежде всего чувствами'],
            $z >= self::CHANNEL_MAJOR && $strained > 0 => ['value' => 'direct',
                'basis' => $chan . '; выраженных напряжённых форм: ' . $strained
                    . ' — смягчения будут прочитаны как уклончивость'],
            default => ['value' => 'even', 'basis' => $chan . ' — особых причин смягчать или заострять нет'],
        };

        // 4. Опора.
        $top = max($x, $y, $z);
        $out['support'] = match (true) {
            $nKnown === 0 => ['value' => 'facts', 'basis' => $chan . ' — берём нейтральную опору на факты'],
            $top === $x   => ['value' => 'facts', 'basis' => $chan . ' — ведёт обдумывание'],
            $top === $y   => ['value' => 'feelings', 'basis' => $chan . ' — ведут чувства'],
            default       => ['value' => 'action', 'basis' => $chan . ' — ведёт действие'],
        };

        // 5. Форма подачи.
        $out['structure'] = $z >= self::CHANNEL_STEADY
            ? ['value' => 'blocks', 'basis' => $chan . ' — мысль должна схватываться с первой строки абзаца']
            : ['value' => 'flow', 'basis' => $chan . ' — связный текст читается спокойно'];

        // 6. Чувствительность.
        $out['sensitivity'] = match (true) {
            $tender !== '' => ['value' => 'high',
                'basis' => 'высокий уровень по шкале «' . $tender . '»'],
            $hidden > 0 => ['value' => 'high',
                'basis' => 'шкал в разделе скрытого напряжения: ' . $hidden
                    . ' — по этим темам самоописание расходится с телесной реакцией'],
            default => ['value' => 'usual', 'basis' => 'расхождений самоописания и телесной реакции не найдено'],
        };

        foreach ($out as $k => $v) $out[$k]['basis'] = trim($v['basis']);
        return $out;
    }

    /** Подпись первой шкалы высокого уровня, чьё название содержит один из корней. */
    private static function highWith(array $axes, array $roots): string {
        if (!$roots) return '';
        foreach ($axes as $a) {
            if (($a['level'] ?? '') !== 'high') continue;
            $label = function_exists('mb_strtolower')
                ? mb_strtolower((string) $a['label'], 'UTF-8') : strtolower((string) $a['label']);
            foreach ($roots as $root) {
                if (str_contains($label, $root)) return (string) $a['label'];
            }
        }
        return '';
    }

    /**
     * ЧТО ДЛЯ КЛИЕНТА ВАЖНО — ведущие темы профиля. Берём тот же вес, что задаёт
     * размер кружка на матрице (сумма когнитивного и эмоционального ответа):
     * ценности не угадываются по тексту, а читаются из расчёта.
     */
    private static function topValues(array $m): array {
        $axes = array_values(array_filter($m['axes'] ?? [], static fn ($a) => ($a['category'] ?? '') !== 'skip'));
        usort($axes, static fn ($p, $q) => (float) $q['weight'] <=> (float) $p['weight']);
        $out = [];
        foreach (array_slice($axes, 0, self::VALUES_TOP) as $a) {
            $out[] = [
                'label' => (string) $a['label'],
                'weight' => (float) $a['weight'],
                'methodic' => (string) ($m['test_key'] ?? ''),
                'meaning' => (string) ($a['meaning'] ?? ''),
                'fact' => (string) ($a['fact'] ?? ''),
            ];
        }
        return $out;
    }

    /** Ведущие темы всех анализов клиента: по одной на подпись, тяжелейшие первыми. */
    private static function mergeValues(array $rows): array {
        $best = [];
        foreach ($rows as $r) {
            $k = $r['label'];
            if (!isset($best[$k]) || $r['weight'] > $best[$k]['weight']) $best[$k] = $r;
        }
        usort($best, static fn ($p, $q) => (float) $q['weight'] <=> (float) $p['weight']);
        return array_slice(array_values($best), 0, self::VALUES_TOP);
    }

    /* ──────────────────────────── В промпт ─────────────────────────────── */

    /**
     * Блок указаний для нейросети. Это ЕДИНСТВЕННОЕ, что уходит из аватара
     * наружу: сам аватар — внутренняя информация оператора и в отчёт клиента
     * не попадает (требование заказчика), поэтому блок заканчивается прямым
     * запретом его пересказывать.
     *
     * Пустая строка — если аватара нет (первый тест клиента без физиологии):
     * пустой заголовок в промпте только сбивал бы модель.
     */
    public static function promptBlock(array $persona): string {
        $traits = $persona['traits'] ?? [];
        if (!$traits) return '';
        $lines = [];
        $lines[] = 'КАК ПОДАВАТЬ МАТЕРИАЛ ЭТОМУ КЛИЕНТУ (посчитано сервисом по его тестам; '
                 . 'это указания по ФОРМЕ, содержание и разделы они не меняют):';
        foreach ($traits as $t) {
            $lines[] = '- ' . $t['directive'];
        }
        $values = $persona['values'] ?? [];
        if ($values) {
            $lines[] = '- ЧТО ДЛЯ КЛИЕНТА ВАЖНО (ведущие темы его профиля — говори на их языке там, '
                     . 'где это уместно; отдельного раздела про них НЕ делай): '
                     . implode(', ', array_map(static fn ($v) => $v['label'], $values)) . '.';
        }
        if (trim((string) ($persona['notes'] ?? '')) !== '') {
            $lines[] = '- Замечание оператора о подаче: ' . trim((string) $persona['notes']);
        }
        $lines[] = 'Сами эти указания клиенту НЕ пересказывай и не упоминай: ни «вам подойдёт короткий текст», '
                 . 'ни «вы склонны к анализу». Они видны только в том, КАК написан отчёт.';
        $lines[] = '';
        return implode("\n", $lines);
    }
}
