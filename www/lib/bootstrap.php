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

    np_seed_prompts();
    return $cfg;
}

/** Комментарии-маркеры автосидируемых версий (по ним же проверяется идемпотентность). */
const NP_PROMPT_V1_COMMENT = 'Импортировано из исходного промпта';
const NP_PROMPT_V2_COMMENT = 'v2: адаптация под недорогие модели (DeepSeek и др.) — вход описан как распознанный текст скриншота';

/** Seed СМУ + LSI + Басса-Дарки prompt families from the bundled source prompt files. */
function np_seed_prompts(): void {
    // Локально Sources лежит в корне репозитория (два уровня над lib: www/lib); на
    // хостинге каталога нет — тогда сидируется заглушка, промпт правится в UI.
    $sources = dirname(__DIR__, 2) . '/Sources';
    $seed = [
        'smu' => ['SMU PROMPT.txt', 'Интерпретация СМУ (Структура мотивации участия)'],
        'lsi' => ['LSI PROMPT.txt', 'Интерпретация ИЖС/LSI (Индекс жизненного стиля)'],
        'bd'  => ['BD PROMPT.txt', 'Интерпретация Басса-Дарки (агрессивность и враждебность)'],
    ];
    foreach ($seed as $key => [$file, $name]) {
        if (Prompts::family($key)) continue;
        $path = $sources . '/' . $file;
        $body = is_file($path) ? (string) file_get_contents($path) : ('Промпт для ' . $name);
        Prompts::seed($key, $name, trim($body), 'deepseek-r1', 'yandex');
    }
    np_seed_prompts_v2();
}

/**
 * Досидирует версии v2 — промпты, адаптированные под недорогие модели.
 * Тексты лежат в lib/prompts/*.php (внутри веб-корня, деплоятся через pull.php).
 * v2 становится активной, только если оператор ещё не трогал семейство
 * (активна нетронутая автосидированная v1) — ручной выбор версии не перебиваем.
 */
function np_seed_prompts_v2(): void {
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
        $versionId = Prompts::addVersion($promptId, $body, 'deepseek-v3', 'yandex', NP_PROMPT_V2_COMMENT);
        $active = $fam['active_version_id'] ? Prompts::version((int) $fam['active_version_id']) : null;
        if ($active === null || ((int) $active['version_no'] === 1 && ($active['comment'] ?? '') === NP_PROMPT_V1_COMMENT)) {
            Prompts::setActive($promptId, $versionId);
        }
    }
}
