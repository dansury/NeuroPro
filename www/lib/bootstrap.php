<?php
/**
 * Shared bootstrap: load config, init DB + LLM, seed prompt families from the
 * source prompt files on first run. Included by every entry point.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/llm.php';
require_once __DIR__ . '/prompts.php';
require_once __DIR__ . '/profile.php';
require_once __DIR__ . '/phys.php';
require_once __DIR__ . '/metrics.php';
require_once __DIR__ . '/report.php';
require_once __DIR__ . '/interpret.php';
require_once __DIR__ . '/trash.php';
require_once __DIR__ . '/settings_store.php';

function np_boot(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $cfg = require __DIR__ . '/config.php';

    foreach (['LOG_DIR', 'WATCH_DIR', 'UPLOAD_DIR'] as $k) {
        if (!empty($cfg[$k]) && !is_dir($cfg[$k])) @mkdir($cfg[$k], 0775, true);
    }

    Db::init($cfg['DB_PATH']);
    $store = new SettingsStore($cfg['DB_PATH']);
    LLM::init($cfg, $store);
    // Пороги матрицы и контраст размеров кружков — настройка оператора: расчёт
    // берёт их отсюда, поэтому configure() зовётся до первого Metrics::build().
    Metrics::configure($cfg);

    np_seed_prompts($cfg);
    return $cfg;
}

/** Комментарии-маркеры автосидируемых версий (по ним же проверяется идемпотентность). */
const NP_PROMPT_V1_COMMENT = 'Импортировано из исходного промпта';
const NP_PROMPT_V2_COMMENT = 'v2: адаптация под недорогие модели (DeepSeek и др.) — вход описан как распознанный текст скриншота';
const NP_PROMPT_V3_COMMENT = 'v3: вся математика в коде (Metrics) — модель получает готовый расчёт и только пишет текст';
const NP_PROMPT_V4_COMMENT = 'v4: расчёт, итоги методики и матрица показателей приходят готовыми; в отчёте клиента таблиц нет';
const NP_PROMPT_V5_COMMENT = 'v5: глубина отчёта у недорогих моделей — скелет разбора шкалы, объём, СМК всегда, конкретные рекомендации';
const NP_PROMPT_V6_COMMENT = 'v6: содержательность вместо многословности — запрет повторов, разделы по методике, язык правит второй слой';
const NP_PROMPT_V7_COMMENT = 'v7 (Басса-Дарки): описания видов агрессии и четыре группы по матрице — непроявленные перечисляются списком';
const NP_PROMPT_V8_COMMENT = 'v8 (Басса-Дарки): язык клиента вместо терминов (СМК, каналы, датчики) и запрет повторов — как у СМУ и ИЖС';
const NP_PROMPT_V9_COMMENT = 'v9 (Басса-Дарки): «Скрытое напряжение» — сейчас условий для проявления нет, но готовность осталась и может вернуться';
const NP_PROMPT_STYLE_COMMENT = 'Второй слой: литературная правка готового отчёта (язык, краткость; факты не трогает)';
// Поколение «Sonnet» — не следующий номер версии, а отдельная линия промптов:
// та же математика и те же разделы, но отчёт вдвое короче. Заказчик отличает их
// по метке, поэтому в комментарии стоит слово «Sonnet», а не «v10».
const NP_PROMPT_SONNET_COMMENT = 'Sonnet: отчёт вдвое короче — один абзац на шкалу, три рекомендации, сжатые итоги и резюме';
const NP_PROMPT_STYLE_SONNET_COMMENT = 'Sonnet (второй слой): правка на сжатие — режет воду, факты, шкалы и разделы сохраняет';
// Поколение «Первый шаг» — то же поколение «Sonnet» плюс последний раздел
// отчёта: одно конкретное действие на шкале, выбранной расчётом.
const NP_PROMPT_ACTION_COMMENT = 'Первый шаг: отчёт заканчивается одним конкретным действием на шкале, выбранной расчётом';
// «Первый шаг v2» — разбор реального отчёта показал: там, где хватало одного
// чёткого предложения, модель писала три; отчёт заканчивался резюме ДО
// действия, а не действием; общий показатель методики стоял в конце разбора,
// а не в начале. v2 правит объём и порядок текста, математику не трогает.
const NP_PROMPT_ACTION_V2_COMMENT = 'Первый шаг v2: короче на шкалу, общий показатель методики — в начале, без «Короткого резюме», без придуманной периодичности';

/** Комментарии всех автосидируемых версий — по ним отличаем служебные от ручных. */
function np_seeded_comments(): array {
    return [NP_PROMPT_V1_COMMENT, NP_PROMPT_V2_COMMENT, NP_PROMPT_V3_COMMENT, NP_PROMPT_V4_COMMENT,
            NP_PROMPT_V5_COMMENT, NP_PROMPT_V6_COMMENT, NP_PROMPT_V7_COMMENT, NP_PROMPT_V8_COMMENT,
            NP_PROMPT_V9_COMMENT, NP_PROMPT_STYLE_COMMENT, NP_PROMPT_SONNET_COMMENT,
            NP_PROMPT_STYLE_SONNET_COMMENT, NP_PROMPT_ACTION_COMMENT, NP_PROMPT_ACTION_V2_COMMENT];
}

/** Метаданные семейств промптов: вшитый исходник v1, txt-исходник и имя. */
function np_prompt_families(): array {
    return [
        'smu' => ['v1' => 'smu_v1.php', 'src' => 'SMU PROMPT.txt', 'name' => 'Интерпретация СМУ (Структура мотивации участия)'],
        'lsi' => ['v1' => 'lsi_v1.php', 'src' => 'LSI PROMPT.txt', 'name' => 'Интерпретация ИЖС/LSI (Индекс жизненного стиля)'],
        'bd'  => ['v1' => 'bd_v1.php',  'src' => 'BD PROMPT.txt',  'name' => 'Интерпретация Басса-Дарки (агрессивность и враждебность)'],
    ];
}

/** Имя семейства второго слоя — оно НЕ методика, поэтому живёт отдельно. */
const NP_STYLE_PROMPT_NAME = 'Литературная правка отчёта (второй слой, любая методика)';

/**
 * Текст исходного (v1) промпта семейства. Приоритет:
 *   1. вшитый файл lib/prompts/<key>_v1.php — лежит ВНУТРИ веб-корня, поэтому
 *      деплоится через pull.php и доступен на проде;
 *   2. Sources/<FILE>.txt — только для локальной разработки (в git, но каталог
 *      Sources/ на прод не зеркалится);
 *   3. заглушка — если исходника нет нигде (промпт правится вручную в UI).
 */
function np_prompt_v1_body(string $key): string {
    $fam = np_prompt_families()[$key] ?? null;
    if ($fam === null) return '';
    $bundled = __DIR__ . '/prompts/' . $fam['v1'];
    if (is_file($bundled)) {
        $body = trim((string) require $bundled);
        if ($body !== '') return $body;
    }
    $srcPath = dirname(__DIR__, 2) . '/Sources/' . $fam['src'];
    if (is_file($srcPath)) {
        $body = trim((string) file_get_contents($srcPath));
        if ($body !== '') return $body;
    }
    return 'Промпт для ' . $fam['name'];
}

/** Seed СМУ + LSI + Басса-Дарки prompt families from the bundled source prompt files. */
function np_seed_prompts(array $cfg = []): void {
    $model = (string) ($cfg['LLM_DEFAULT_MODEL'] ?? 'yandexgpt');
    $provider = (string) ($cfg['LLM_PROVIDER'] ?? 'yandex');
    foreach (np_prompt_families() as $key => $fam) {
        if (Prompts::family($key)) continue;
        Prompts::seed($key, $fam['name'], np_prompt_v1_body($key), $model, $provider);
    }
    np_heal_stub_prompts();
    np_seed_prompts_v2($model, $provider);
    np_seed_prompts_v3($model, $provider);
    np_seed_prompts_v4($model, $provider);
    np_seed_prompts_v5($model, $provider);
    np_seed_prompts_v6($model, $provider);
    np_seed_prompts_v7($model, $provider);
    np_seed_prompts_v8($model, $provider);
    np_seed_prompts_v9($model, $provider);
    np_seed_style_prompt($model, $provider);
    np_seed_prompts_sonnet($model, $provider);
    np_seed_style_prompt_sonnet($model, $provider);
    np_seed_prompts_action($model, $provider);
    np_seed_prompts_action_v2($model, $provider);
    np_heal_seeded_models($model, $provider);
}

/**
 * Досидирует поколение «Sonnet» — ОДНОВРЕМЕННО У ВСЕХ ТРЁХ методик. Заказчик
 * сравнил готовый отчёт (~1100 слов) с тем, что реально дочитывают, и попросил
 * вдвое короче. Математика, разделы и запреты прежних версий не меняются:
 * у шкалы вместо двух абзацев один, «Как читать картинки» — одно предложение,
 * рекомендаций три вместо пяти, итоги и резюме ужаты, а длинный образец
 * плотности заменён коротким — длинный пример сам учил модель писать длинно.
 *
 * Поколение помечено не номером, а словом «Sonnet»: это не «следующая версия
 * после v9», а отдельная линия, и оператор выбирает её в списке по метке.
 * Активной она становится по общему правилу — только если сейчас активна
 * нетронутая автосидированная версия (у СМУ и ИЖС это v6, у Басса-Дарки v9).
 */
function np_seed_prompts_sonnet(string $model = 'yandexgpt', string $provider = 'yandex'): void {
    np_seed_prompt_generation(
        ['smu' => 'smu_sonnet.php', 'lsi' => 'lsi_sonnet.php', 'bd' => 'bd_sonnet.php'],
        NP_PROMPT_SONNET_COMMENT,
        [NP_PROMPT_V1_COMMENT, NP_PROMPT_V2_COMMENT, NP_PROMPT_V3_COMMENT, NP_PROMPT_V4_COMMENT,
         NP_PROMPT_V5_COMMENT, NP_PROMPT_V6_COMMENT, NP_PROMPT_V7_COMMENT, NP_PROMPT_V8_COMMENT,
         NP_PROMPT_V9_COMMENT],
        $model, $provider
    );
}

/**
 * Досидирует поколение «Первый шаг» — ОДНОВРЕМЕННО У ВСЕХ ТРЁХ методик. Отчёт
 * заканчивался тремя рекомендациями-направлениями, и заказчик попросил, чтобы
 * в конце стояло одно конкретное действие: с чего начать сегодня. Все прежние
 * требования поколения «Sonnet» сохранены, добавлен только последний раздел
 * «С чего начать» (и потолок объёма поднят ровно на него).
 *
 * ШКАЛУ ДЛЯ ЭТОГО ДЕЙСТВИЯ ВЫБИРАЕТ РАСЧЁТ, а не модель: Metrics::firstStep()
 * берёт самый достоверный телесный отклик и самую тяжёлую тему профиля, а
 * Interpret::buildUserMessage() передаёт готовый выбор блоком «С ЧЕГО НАЧАТЬ»
 * (Конституция, принцип I).
 */
function np_seed_prompts_action(string $model = 'yandexgpt', string $provider = 'yandex'): void {
    np_seed_prompt_generation(
        ['smu' => 'smu_action.php', 'lsi' => 'lsi_action.php', 'bd' => 'bd_action.php'],
        NP_PROMPT_ACTION_COMMENT,
        [NP_PROMPT_V1_COMMENT, NP_PROMPT_V2_COMMENT, NP_PROMPT_V3_COMMENT, NP_PROMPT_V4_COMMENT,
         NP_PROMPT_V5_COMMENT, NP_PROMPT_V6_COMMENT, NP_PROMPT_V7_COMMENT, NP_PROMPT_V8_COMMENT,
         NP_PROMPT_V9_COMMENT, NP_PROMPT_SONNET_COMMENT],
        $model, $provider
    );
}

/**
 * Досидирует поколение «Первый шаг v2» — ОДНОВРЕМЕННО У ВСЕХ ТРЁХ методик.
 * Заказчик разобрал реальный отчёт («Sonnet», умная модель) и указал на три
 * проблемы разом: там, где хватало одного чёткого предложения на ~30 слов,
 * модель писала два-три «на всякий случай»; отчёт заканчивался «Коротким
 * резюме», а НЕ действием (заказчик прямо просил: никаких резюме, только
 * финальное действие); общий показатель методики («Результат или процесс» у
 * СМУ, «Общая картина защит» у ИЖС, «Агрессивность и враждебность» у
 * Басса-Дарки) стоял в конце разбора шкал, а не открывал его. v2 правит
 * именно это — математика и состав разделов не меняются (Конституция,
 * принцип I). Активной становится, только если сейчас активна нетронутая
 * версия «Первый шаг» (v1) — ручной выбор оператора не трогаем.
 */
function np_seed_prompts_action_v2(string $model = 'yandexgpt', string $provider = 'yandex'): void {
    np_seed_prompt_generation(
        ['smu' => 'smu_action_v2.php', 'lsi' => 'lsi_action_v2.php', 'bd' => 'bd_action_v2.php'],
        NP_PROMPT_ACTION_V2_COMMENT,
        [NP_PROMPT_V1_COMMENT, NP_PROMPT_V2_COMMENT, NP_PROMPT_V3_COMMENT, NP_PROMPT_V4_COMMENT,
         NP_PROMPT_V5_COMMENT, NP_PROMPT_V6_COMMENT, NP_PROMPT_V7_COMMENT, NP_PROMPT_V8_COMMENT,
         NP_PROMPT_V9_COMMENT, NP_PROMPT_SONNET_COMMENT, NP_PROMPT_ACTION_COMMENT],
        $model, $provider
    );
}

/**
 * Версия «Sonnet» ВТОРОГО СЛОЯ. Пока первый слой писал длинно, второй правил
 * язык; теперь, когда первый слой сам держит объём, второй дожимает — режет
 * оставшиеся пересказы и связки. Нижняя граница сжатия названа в самом промпте:
 * ниже двух третей исходного опускаться нельзя, иначе Interpret::run() отбросит
 * правку как потерю текста.
 *
 * Общий сидинг сюда не подходит: у семейства «style» отсутствие активной версии
 * означает «слой выключен оператором», а не «надо включить». Поэтому версия
 * добавляется всегда (оператор увидит её в списке), а активной становится
 * только вместо нетронутой v1.
 */
function np_seed_style_prompt_sonnet(string $model = 'yandexgpt', string $provider = 'yandex'): void {
    $fam = Prompts::family(Interpret::STYLE_KEY);
    if (!$fam) return;
    $promptId = (int) $fam['id'];
    if (Db::one('SELECT id FROM prompt_versions WHERE prompt_id = ? AND comment = ?',
                [$promptId, NP_PROMPT_STYLE_SONNET_COMMENT])) return;
    $path = __DIR__ . '/prompts/style_sonnet.php';
    if (!is_file($path)) return;
    $body = trim((string) require $path);
    if ($body === '') return;
    $versionId = Prompts::addVersion($promptId, $body, $model, $provider, NP_PROMPT_STYLE_SONNET_COMMENT);
    $active = $fam['active_version_id'] ? Prompts::version((int) $fam['active_version_id']) : null;
    if ($active !== null && (string) ($active['comment'] ?? '') === NP_PROMPT_STYLE_COMMENT) {
        Prompts::setActive($promptId, $versionId);
    }
}

/**
 * Досидирует версии v6 — промпты «на содержательность». v5 добивался глубины
 * требованием объёма («не меньше 700 слов»), и недорогая модель набирала его
 * повторами: несколько шкал подряд получали дословно одинаковый абзац про
 * телесную реакцию. v6 требование объёма снимает, запрещает повторы и опирается
 * на разделы, названные в терминах методики (Metrics::CATEGORY_BY_TEST), а язык
 * отдаёт второму слою.
 */
function np_seed_prompts_v6(string $model = 'yandexgpt', string $provider = 'yandex'): void {
    np_seed_prompt_generation(
        ['smu' => 'smu_v6.php', 'lsi' => 'lsi_v6.php', 'bd' => 'bd_v6.php'],
        NP_PROMPT_V6_COMMENT,
        [NP_PROMPT_V1_COMMENT, NP_PROMPT_V2_COMMENT, NP_PROMPT_V3_COMMENT, NP_PROMPT_V4_COMMENT, NP_PROMPT_V5_COMMENT],
        $model, $provider
    );
}

/**
 * Досидирует версию v7 — ТОЛЬКО для Басса-Дарки. Заказчик просил, чтобы отчёт
 * называл каждый вид агрессии своим описанием и раскладывал все восемь шкал на
 * четыре группы по положению на матрице (проявленные; проявленные без телесного
 * напряжения; скрытое напряжение; не проявляются — списком). Сами группы и
 * описания считает код (Metrics::CATEGORY_BY_TEST['bd'], SCALE_MEANING), промпт
 * лишь задаёт, как об этом писать. У СМУ и ИЖС поколения v7 нет — их активной
 * версией остаётся v6.
 */
function np_seed_prompts_v7(string $model = 'yandexgpt', string $provider = 'yandex'): void {
    np_seed_prompt_generation(
        ['bd' => 'bd_v7.php'],
        NP_PROMPT_V7_COMMENT,
        [NP_PROMPT_V1_COMMENT, NP_PROMPT_V2_COMMENT, NP_PROMPT_V3_COMMENT, NP_PROMPT_V4_COMMENT,
         NP_PROMPT_V5_COMMENT, NP_PROMPT_V6_COMMENT],
        $model, $provider
    );
}

/**
 * Досидирует версию v8 — снова ТОЛЬКО для Басса-Дарки. v7 дала правильную
 * структуру отчёта, но требовала «назвать ведущий канал СМК», и модель выносила
 * служебные обозначения («СМК», «канал Z», «ЭЭГ», «нажатие стилусом») прямо в
 * текст клиенту, а обязательный абзац про канал у каждой шкалы превращался в
 * повторяющуюся заготовку. v8 оставляет требование объяснить УСТРОЙСТВО реакции,
 * но только словами клиента, и переносит из v6-поколения СМУ/ИЖС запрет на
 * повтор формулировок — вместе с запретом повторять схему абзаца. Расчёт и
 * группы не меняются: это чисто языковая версия.
 */
function np_seed_prompts_v8(string $model = 'yandexgpt', string $provider = 'yandex'): void {
    np_seed_prompt_generation(
        ['bd' => 'bd_v8.php'],
        NP_PROMPT_V8_COMMENT,
        [NP_PROMPT_V1_COMMENT, NP_PROMPT_V2_COMMENT, NP_PROMPT_V3_COMMENT, NP_PROMPT_V4_COMMENT,
         NP_PROMPT_V5_COMMENT, NP_PROMPT_V6_COMMENT, NP_PROMPT_V7_COMMENT],
        $model, $provider
    );
}

/**
 * Досидирует версию v9 — снова ТОЛЬКО для Басса-Дарки. Раздел «Скрытое
 * напряжение» (в ответах форма ниже порога, тело откликается) v8 объясняла лишь
 * через внутреннюю склонность, и клиент читал его как приговор наклонности.
 * v9 добавляет временной вывод: сейчас условий для проявления этих видов
 * агрессии нет, но телесная готовность осталась — если жизненная ситуация
 * расшатается, есть риск снова к ним прибегнуть. Оба вывода обязательны и
 * проверяются чек-листом промпта. Тот же смысл раздела приходит и в расчёте
 * (Metrics::CATEGORY_BY_TEST['bd']['hidden']) — математика не меняется.
 */
function np_seed_prompts_v9(string $model = 'yandexgpt', string $provider = 'yandex'): void {
    np_seed_prompt_generation(
        ['bd' => 'bd_v9.php'],
        NP_PROMPT_V9_COMMENT,
        [NP_PROMPT_V1_COMMENT, NP_PROMPT_V2_COMMENT, NP_PROMPT_V3_COMMENT, NP_PROMPT_V4_COMMENT,
         NP_PROMPT_V5_COMMENT, NP_PROMPT_V6_COMMENT, NP_PROMPT_V7_COMMENT, NP_PROMPT_V8_COMMENT],
        $model, $provider
    );
}

/**
 * Семейство промптов ВТОРОГО СЛОЯ — литературной правки готового отчёта.
 * Это не методика: у него нет своих тестов и профилей, оно применяется к
 * результату любого первого слоя. Заводится один раз; дальше оператор правит и
 * версионирует его на странице «Промпты», как остальные. Если снять активную
 * версию, второй слой просто перестаёт применяться.
 */
function np_seed_style_prompt(string $model = 'yandexgpt', string $provider = 'yandex'): void {
    $path = __DIR__ . '/prompts/style_v1.php';
    if (!is_file($path)) return;
    $body = trim((string) require $path);
    if ($body === '') return;
    // Семейство заводится ОДИН раз. Если оно уже есть — не трогаем ничего:
    // отсутствие активной версии здесь означает «слой выключен оператором», а
    // не «надо досидировать» (Prompts::deleteVersion умеет снимать активную).
    if (Prompts::family(Interpret::STYLE_KEY)) return;
    Prompts::seed(Interpret::STYLE_KEY, NP_STYLE_PROMPT_NAME, $body, $model, $provider, NP_PROMPT_STYLE_COMMENT);
}

/**
 * Досидирует версии v3 — промпты под расчёт в коде (www/lib/metrics.php):
 * уровни, достоверность и разделы приходят готовыми, модель только пишет текст.
 */
function np_seed_prompts_v3(string $model = 'yandexgpt', string $provider = 'yandex'): void {
    np_seed_prompt_generation(
        ['smu' => 'smu_v3.php', 'lsi' => 'lsi_v3.php', 'bd' => 'bd_v3.php'],
        NP_PROMPT_V3_COMMENT,
        [NP_PROMPT_V1_COMMENT, NP_PROMPT_V2_COMMENT],
        $model, $provider
    );
}

/**
 * Досидирует версии v4 — промпты под отчёт БЕЗ таблиц: под диаграммой печатается
 * матрица (www/lib/matrix.php), а модель получает готовыми ещё и итоги методики
 * (суммы СМУ, напряжённость защит ИЖС, индексы Басса-Дарки) и зоны матрицы.
 */
function np_seed_prompts_v4(string $model = 'yandexgpt', string $provider = 'yandex'): void {
    np_seed_prompt_generation(
        ['smu' => 'smu_v4.php', 'lsi' => 'lsi_v4.php', 'bd' => 'bd_v4.php'],
        NP_PROMPT_V4_COMMENT,
        [NP_PROMPT_V1_COMMENT, NP_PROMPT_V2_COMMENT, NP_PROMPT_V3_COMMENT],
        $model, $provider
    );
}

/**
 * Досидирует версии v5 — промпты «на глубину». Расчёт тот же, что у v4, но
 * текст отчёта у недорогих моделей (gemini-flash и подобных) получался пустым:
 * одно-два предложения на шкалу, общие рекомендации, соотношение СМК не
 * использовалось. v5 задаёт скелет разбора каждой шкалы, требует объём и
 * обязательно использует СМК — независимо от достоверности отклонения.
 */
function np_seed_prompts_v5(string $model = 'yandexgpt', string $provider = 'yandex'): void {
    np_seed_prompt_generation(
        ['smu' => 'smu_v5.php', 'lsi' => 'lsi_v5.php', 'bd' => 'bd_v5.php'],
        NP_PROMPT_V5_COMMENT,
        [NP_PROMPT_V1_COMMENT, NP_PROMPT_V2_COMMENT, NP_PROMPT_V3_COMMENT, NP_PROMPT_V4_COMMENT],
        $model, $provider
    );
}

/**
 * Общий сидинг поколения промптов: заводит новую версию из вшитого файла и
 * делает её активной, только если активна нетронутая автосидированная версия из
 * $replaceable. Ручной выбор оператора и версии с интерпретациями не трогаем —
 * история интерпретаций привязана к конкретной версии (Конституция, принцип V).
 *
 * @param array $files       test_key => имя файла в lib/prompts/
 * @param string $comment    маркер поколения (он же признак идемпотентности)
 * @param array $replaceable маркеры версий, которые можно снять с активной
 */
function np_seed_prompt_generation(array $files, string $comment, array $replaceable, string $model, string $provider): void {
    foreach ($files as $key => $file) {
        $fam = Prompts::family($key);
        if (!$fam) continue;
        $promptId = (int) $fam['id'];
        if (Db::one('SELECT id FROM prompt_versions WHERE prompt_id = ? AND comment = ?', [$promptId, $comment])) continue;
        $path = __DIR__ . '/prompts/' . $file;
        if (!is_file($path)) continue;
        $body = trim((string) require $path);
        if ($body === '') continue;
        $versionId = Prompts::addVersion($promptId, $body, $model, $provider, $comment);
        $active = $fam['active_version_id'] ? Prompts::version((int) $fam['active_version_id']) : null;
        if ($active === null || in_array((string) ($active['comment'] ?? ''), $replaceable, true)) {
            Prompts::setActive($promptId, $versionId);
        }
    }
}

/**
 * Переводит АВТОСИДИРОВАННЫЕ версии на модель/провайдера из конфига. Раньше
 * модель была зашита в код (`deepseek-r1` / `deepseek-v3`), и если открытые
 * модели в каталоге Яндекса не подключены, каждый прогон начинался с
 * `YANDEX HTTP 400: Failed to get model`. Правим только служебные версии
 * (по маркеру-комментарию) и только пока по ним нет интерпретаций — ручной
 * выбор оператора и история не трогаются.
 */
function np_heal_seeded_models(string $model, string $provider): void {
    $legacy = ['deepseek-r1', 'deepseek-v3'];
    if (in_array($model, $legacy, true)) return;   // оператор сам выбрал такую модель
    $seeded = np_seeded_comments();
    $rows = Db::all(
        'SELECT id, model_id FROM prompt_versions WHERE comment IN (' . implode(',', array_fill(0, count($seeded), '?'))
        . ') AND model_id IN (?, ?)',
        [...$seeded, $legacy[0], $legacy[1]]
    );
    foreach ($rows as $row) {
        if (Prompts::interpCount((int) $row['id']) > 0) continue;
        Db::q('UPDATE prompt_versions SET model_id = ?, provider = ? WHERE id = ?', [$model, $provider, (int) $row['id']]);
    }
}

/**
 * Лечит семейства, засеянные РАНЬШЕ заглушкой «Промпт для …»: так бывало на
 * проде, когда исходный текст ещё не был вшит в www/, а каталог Sources/ туда
 * не деплоится. Если импортированная v1-версия — заглушка/пустая и по ней нет
 * интерпретаций, подставляем реальный вшитый текст. Правим ровно ту служебную
 * версию, что создаётся автосидингом (по маркеру-комментарию); ручные версии и
 * версии с интерпретациями не трогаем — история не теряется.
 */
function np_heal_stub_prompts(): void {
    foreach (np_prompt_families() as $key => $fam) {
        $family = Prompts::family($key);
        if (!$family) continue;
        $v1 = Db::one(
            'SELECT * FROM prompt_versions WHERE prompt_id = ? AND comment = ? ORDER BY version_no ASC LIMIT 1',
            [(int) $family['id'], NP_PROMPT_V1_COMMENT]
        );
        if (!$v1) continue;
        $body = trim((string) $v1['body']);
        if ($body !== '' && !str_starts_with($body, 'Промпт для ')) continue; // уже реальный текст
        if (Prompts::interpCount((int) $v1['id']) > 0) continue;               // по заглушке уже считали — не трогаем
        $real = np_prompt_v1_body($key);
        if ($real === '' || str_starts_with($real, 'Промпт для ')) continue;   // лечить нечем
        Db::q('UPDATE prompt_versions SET body = ? WHERE id = ?', [$real, (int) $v1['id']]);
    }
}

/**
 * Досидирует версии v2 — промпты, адаптированные под недорогие модели.
 * Тексты лежат в lib/prompts/*.php (внутри веб-корня, деплоятся через pull.php).
 */
function np_seed_prompts_v2(string $model = 'yandexgpt', string $provider = 'yandex'): void {
    np_seed_prompt_generation(
        ['smu' => 'smu_v2.php', 'lsi' => 'lsi_v2.php', 'bd' => 'bd_v2.php'],
        NP_PROMPT_V2_COMMENT,
        [NP_PROMPT_V1_COMMENT],
        $model, $provider
    );
}
