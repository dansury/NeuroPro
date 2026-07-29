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
require_once __DIR__ . '/report.php';
require_once __DIR__ . '/interpret.php';
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

    np_seed_prompts($cfg);
    return $cfg;
}

/** Комментарии-маркеры автосидируемых версий (по ним же проверяется идемпотентность). */
const NP_PROMPT_V1_COMMENT = 'Импортировано из исходного промпта';
const NP_PROMPT_V2_COMMENT = 'v2: адаптация под недорогие модели (DeepSeek и др.) — вход описан как распознанный текст скриншота';

/** Метаданные семейств промптов: вшитый исходник v1, txt-исходник и имя. */
function np_prompt_families(): array {
    return [
        'smu' => ['v1' => 'smu_v1.php', 'src' => 'SMU PROMPT.txt', 'name' => 'Интерпретация СМУ (Структура мотивации участия)'],
        'lsi' => ['v1' => 'lsi_v1.php', 'src' => 'LSI PROMPT.txt', 'name' => 'Интерпретация ИЖС/LSI (Индекс жизненного стиля)'],
        'bd'  => ['v1' => 'bd_v1.php',  'src' => 'BD PROMPT.txt',  'name' => 'Интерпретация Басса-Дарки (агрессивность и враждебность)'],
    ];
}

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
    np_heal_seeded_models($model, $provider);
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
    $rows = Db::all(
        'SELECT id, model_id FROM prompt_versions WHERE comment IN (?, ?) AND model_id IN (?, ?)',
        [NP_PROMPT_V1_COMMENT, NP_PROMPT_V2_COMMENT, $legacy[0], $legacy[1]]
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
 * v2 становится активной, только если оператор ещё не трогал семейство
 * (активна нетронутая автосидированная v1) — ручной выбор версии не перебиваем.
 */
function np_seed_prompts_v2(string $model = 'yandexgpt', string $provider = 'yandex'): void {
    $v2 = ['smu' => 'smu_v2.php', 'lsi' => 'lsi_v2.php', 'bd' => 'bd_v2.php'];
    foreach ($v2 as $key => $file) {
        $fam = Prompts::family($key);
        if (!$fam) continue;
        $promptId = (int) $fam['id'];
        if (Db::one('SELECT id FROM prompt_versions WHERE prompt_id = ? AND comment = ?', [$promptId, NP_PROMPT_V2_COMMENT])) continue;
        $path = __DIR__ . '/prompts/' . $file;
        if (!is_file($path)) continue;
        $body = trim((string) require $path);
        if ($body === '') continue;
        $versionId = Prompts::addVersion($promptId, $body, $model, $provider, NP_PROMPT_V2_COMMENT);
        $active = $fam['active_version_id'] ? Prompts::version((int) $fam['active_version_id']) : null;
        if ($active === null || ((int) $active['version_no'] === 1 && ($active['comment'] ?? '') === NP_PROMPT_V1_COMMENT)) {
            Prompts::setActive($promptId, $versionId);
        }
    }
}
