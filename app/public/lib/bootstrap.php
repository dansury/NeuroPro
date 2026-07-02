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

/** Seed СМУ + LSI prompt families from the bundled source prompt files. */
function np_seed_prompts(): void {
    // Локально Sources лежит в корне репозитория (три уровня над lib); на
    // хостинге каталога нет — тогда сидируется заглушка, промпт правится в UI.
    $sources = dirname(__DIR__, 3) . '/Sources';
    $seed = [
        'smu' => ['SMU PROMPT.txt', 'Интерпретация СМУ (Структура мотивации участия)'],
        'lsi' => ['LSI PROMPT.txt', 'Интерпретация ИЖС/LSI (Индекс жизненного стиля)'],
    ];
    foreach ($seed as $key => [$file, $name]) {
        if (Prompts::family($key)) continue;
        $path = $sources . '/' . $file;
        $body = is_file($path) ? (string) file_get_contents($path) : ('Промпт для ' . $name);
        Prompts::seed($key, $name, trim($body), 'deepseek-r1', 'yandex');
    }
}
