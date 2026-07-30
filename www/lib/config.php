<?php
/**
 * 4neuropro — configuration (extracted from resume-index).
 * Resolution order per key (highest priority first):
 *   1. `settings` DB row (editable via setup.php — admin only)
 *   2. process-ENV variable
 *   3. hardcoded fallback below
 * No secrets in code — supply keys via ENV or setup.php.
 */

// ── .env ──────────────────────────────────────────────────────────────────
// Файл .env кладётся НАД веб-корнем (рядом с каталогом data/): локально это
// корень репозитория (веб-корень — www/), на хостинге — каталог над корнем
// сайта. Так секреты не попадают ни в git, ни в зону раздачи веб-сервера.
// Порядок поиска: ENV NP_ENV_FILE → <над веб-корнем>/.env → <веб-корень>/.env.
// Реальные переменные окружения процесса имеют приоритет над файлом.
if (!function_exists('cfg_load_env_file')) {
    function cfg_load_env_file(): void {
        static $loaded = false;
        if ($loaded) return;
        $loaded = true;
        $webroot = dirname(__DIR__); // lib лежит в веб-корне
        $candidates = array_filter([
            getenv('NP_ENV_FILE') ?: null,
            dirname($webroot) . '/.env',
            $webroot . '/.env',
        ]);
        foreach ($candidates as $file) {
            if (!is_file($file) || !is_readable($file)) continue;
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k);
                // Хвостовой комментарий после значения (KEY=val  # прим.) отрезаем.
                $v = trim((string) preg_replace('/\s+#.*$/', '', $v));
                $v = trim($v, "\"'");
                if ($k === '' || getenv($k) !== false) continue; // ENV процесса важнее
                putenv($k . '=' . $v);
                $_ENV[$k] = $v;
            }
            return; // первый найденный файл выигрывает
        }
    }
}
cfg_load_env_file();

if (!function_exists('cfg_env')) {
    function cfg_env(string $key, ?string $default = null): ?string {
        $v = getenv($key);
        if ($v === false || $v === '') return $default;
        return $v;
    }
}

// Каталог данных (SQLite, логи, загрузки, watch-папка). Приоритет:
//   1. ENV NP_DATA_DIR
//   2. каталог data НАД веб-корнем — не отдаётся веб-сервером
//      (локально это data/ в корне репозитория, на хостинге — рядом с корнем сайта)
//   3. data внутри веб-корня — крайний случай, когда родительский каталог
//      недоступен на запись
if (!function_exists('cfg_data_root')) {
    function cfg_data_root(): string {
        static $dir = null;
        if ($dir !== null) return $dir;
        $env = cfg_env('NP_DATA_DIR');
        if ($env !== null) return $dir = rtrim($env, '/');
        $webroot = dirname(__DIR__); // lib лежит в веб-корне
        $above   = dirname($webroot) . '/data';
        if (is_dir($above) || (is_writable(dirname($above)) && @mkdir($above, 0775, true))) {
            return $dir = $above;
        }
        return $dir = $webroot . '/data';
    }
}

// Operator-editable keys: only these are overlaid from the `settings` table.
if (!function_exists('cfg_settings_whitelist')) {
    function cfg_settings_whitelist(): array {
        return [
            'LLM_PROVIDER', 'LLM_DEFAULT_MODEL', 'LLM_PROVIDER_PRIORITY',
            'LLM_FALLBACK_MODELS',
            'OPENROUTER_API_KEY', 'LLM_VISION_MODEL', 'LLM_FALLBACK_MODEL',
            'LLM_OCR_MODELS', 'SCREENSHOT_MODEL', 'YANDEX_FALLBACK_MODEL',
            'YANDEX_API_KEY', 'YANDEX_FOLDER_ID', 'YANDEX_LLM_URL',
            'YANDEX_OCR_URL', 'YANDEX_OCR_MODEL', 'YANDEX_OCR_ENABLED',
            'ADMIN_EMAIL', 'ERROR_EMAIL',
            'SMTP_HOST', 'SMTP_PORT', 'SMTP_USER', 'SMTP_PASS', 'SMTP_FROM', 'SMTP_FROM_NAME',
            'ADMIN_PASSWORD',
            // Матрица: пороги пунктира и контраст размеров кружков. Оператор
            // двигает пунктир прямо на матрице — значение уходит сюда же.
            'MATRIX_LOW_PCT', 'MATRIX_HIGH_PCT', 'MATRIX_MID_BAND_PCT', 'MATRIX_SIZE_POWER',
        ];
    }
}

$config = [
    /* ── LLM provider switch ── */
    // Яндекс — провайдер по умолчанию: оплата в рублях, данные не покидают РФ,
    // тем же ключом работает Vision OCR скриншота значимости.
    'LLM_PROVIDER'          => cfg_env('LLM_PROVIDER', 'yandex'),           // 'yandex' | 'openrouter'
    'LLM_DEFAULT_MODEL'     => cfg_env('LLM_DEFAULT_MODEL', 'yandexgpt'),
    // Comma-separated provider fallback order for LLM::dispatch(). The CHOSEN
    // model is tried on every provider in this order FIRST (Yandex, then
    // OpenRouter), and only then dispatch moves on to LLM_FALLBACK_MODELS.
    'LLM_PROVIDER_PRIORITY' => cfg_env('LLM_PROVIDER_PRIORITY', 'yandex,openrouter'),
    // Другие модели (короткие id из AVAILABLE_MODELS), которые пробуются только
    // после того, как выбранная модель не отработала ни у одного провайдера.
    'LLM_FALLBACK_MODELS'   => cfg_env('LLM_FALLBACK_MODELS', 'yandexgpt-lite,deepseek-v3'),

    /* ── OpenRouter ── (supply key via ENV or setup.php) */
    'OPENROUTER_API_KEY'    => cfg_env('OPENROUTER_API_KEY', ''),
    'OPENROUTER_URL'        => 'https://openrouter.ai/api/v1/chat/completions',
    'LLM_VISION_MODEL'      => cfg_env('LLM_VISION_MODEL', 'google/gemini-2.0-flash-001'),
    'LLM_FALLBACK_MODEL'    => cfg_env('LLM_FALLBACK_MODEL', 'openrouter/auto'),
    // Страховочная модель Яндекса: yandexgpt доступен в любом каталоге, тогда как
    // открытые модели (deepseek-*, qwen3-*) в каталоге могут быть не подключены —
    // такой слаг возвращает HTTP 400 «Failed to get model».
    'YANDEX_FALLBACK_MODEL' => cfg_env('YANDEX_FALLBACK_MODEL', 'yandexgpt'),
    // OpenRouter model ids tried in order during PDF OCR; first non-empty wins.
    'LLM_OCR_MODELS'        => array_values(array_filter(array_map('trim', explode(',', (string) cfg_env(
        'LLM_OCR_MODELS',
        'google/gemini-2.5-flash,google/gemini-2.0-flash-001'
    ))))),
    // ОТДЕЛЬНАЯ мультимодальная модель для распознавания скриншота «значимости»
    // (LLM::recognizeSignificance). Смотрит на графики, а не на голый OCR-текст.
    // Только OpenRouter (у Яндекса нет vision-чата). Пусто → используются LLM_OCR_MODELS.
    'SCREENSHOT_MODEL'      => cfg_env('SCREENSHOT_MODEL', 'google/gemini-2.5-flash'),

    /* ── Yandex Cloud Foundation Models (OpenAI-compatible mode) ── */
    'YANDEX_API_KEY'        => cfg_env('YANDEX_API_KEY', ''),
    'YANDEX_FOLDER_ID'      => cfg_env('YANDEX_FOLDER_ID', ''),
    'YANDEX_LLM_URL'        => cfg_env('YANDEX_LLM_URL', 'https://llm.api.cloud.yandex.net/v1/chat/completions'),
    // Yandex Vision OCR (https://yandex.cloud/docs/vision/concepts/ocr).
    'YANDEX_OCR_URL'        => cfg_env('YANDEX_OCR_URL', 'https://ocr.api.cloud.yandex.net/ocr/v1/recognizeText'),
    'YANDEX_OCR_MODEL'      => cfg_env('YANDEX_OCR_MODEL', 'page'),
    // '1' → Yandex Vision OCR participates in the PDF-OCR chain. Needs key + folder.
    'YANDEX_OCR_ENABLED'    => cfg_env('YANDEX_OCR_ENABLED', '1'),

    /* ── Матрица «когниция × эмоция» (www/lib/matrix.php) ──────────────────
       Пороги пунктира и контраст размеров кружков. Их двигает оператор — прямо
       на матрице мышью или числом в /setup.php — и от них зависит не только
       картинка, но и раздел отчёта, в который попадёт шкала (Metrics::level).
       Полоса медианы задаётся в % от предела шкалы физиологии: «Знач.» у разных
       методик разного порядка, абсолютное число здесь смысла не имеет. */
    'MATRIX_LOW_PCT'        => cfg_env('MATRIX_LOW_PCT', '40'),
    'MATRIX_HIGH_PCT'       => cfg_env('MATRIX_HIGH_PCT', '60'),
    'MATRIX_MID_BAND_PCT'   => cfg_env('MATRIX_MID_BAND_PCT', '5'),
    // Контраст размеров кружков: НАСКОЛЬКО они отличаются друг от друга, а не
    // насколько крупные (0 — все одинаковые, 1 — естественный разброс профиля,
    // больше — разница подчёркнута). Ключ настройки оставлен прежним, чтобы
    // значение, уже сохранённое оператором, не потерялось при обновлении.
    'MATRIX_SIZE_POWER'     => cfg_env('MATRIX_SIZE_POWER', '1'),

    'LLM_TIMEOUT_SEC'       => (int) cfg_env('LLM_TIMEOUT_SEC', '120'),
    'LLM_MAX_RETRIES'       => (int) cfg_env('LLM_MAX_RETRIES', '2'),

    /* ── Admin / setup gate ── */
    'ADMIN_PASSWORD'        => cfg_env('ADMIN_PASSWORD', ''),

    /* ── Mail (defaults target Yandex SMTP) ── */
    'ADMIN_EMAIL'           => cfg_env('ADMIN_EMAIL', ''),
    // Error-notification mailbox (throttled; see Mailer::sendErrorNotification).
    'ERROR_EMAIL'           => cfg_env('ERROR_EMAIL', ''),
    'SMTP_HOST'             => cfg_env('SMTP_HOST', 'smtp.yandex.ru'),
    'SMTP_PORT'             => (int) cfg_env('SMTP_PORT', '465'),
    'SMTP_USER'             => cfg_env('SMTP_USER', ''),
    'SMTP_PASS'             => cfg_env('SMTP_PASS', ''),
    'SMTP_FROM'             => cfg_env('SMTP_FROM', cfg_env('SMTP_USER', '') ?: ''),
    'SMTP_FROM_NAME'        => cfg_env('SMTP_FROM_NAME', '4neuropro'),

    /* ── Storage ── */
    'DB_PATH'               => cfg_env('DB_PATH', cfg_data_root() . '/app.db'),
    'LOG_DIR'               => cfg_env('LOG_DIR', cfg_data_root() . '/logs'),
    'PROMPT_VERSION'        => 'v1.0',

    /* ── NeuroPro service ── */
    // Folder watched for new Эгоскоп .xls exports (auto-ingest → await screenshot).
    'WATCH_DIR'             => cfg_env('WATCH_DIR', cfg_data_root() . '/incoming'),
    // Public URL of the analysis app. The watch-folder daemon opens it in the
    // operator's browser when a new file is ingested. Empty disables auto-open.
    'APP_URL'               => cfg_env('APP_URL', 'http://neuropro.skywood.club/app/'),
    'UPLOAD_DIR'            => cfg_env('UPLOAD_DIR', cfg_data_root() . '/uploads'),
    'BRAND_NAME'            => cfg_env('BRAND_NAME', 'Центр корпоративной психологии «НейроПро»'),
    'BRAND_PHONE'          => cfg_env('BRAND_PHONE', '8-917-859-60-79'),
    'BRAND_LOGO'           => cfg_env('BRAND_LOGO', dirname(__DIR__) . '/assets/logo.png'),
    'YANDEX_OCR_IMAGE_MODEL' => cfg_env('YANDEX_OCR_IMAGE_MODEL', 'page'),

    /* ── Available models (chat + OCR) ──────────────────────────────────────
       Ключи строки:
         id        — короткий id, которым модель выбирается в UI и в
                     LLM_DEFAULT_MODEL / LLM_FALLBACK_MODELS. Уникален.
         full_id   — слаг у провайдера. Yandex: то, что подставляется в
                     gpt://<folder>/<full_id>/latest. OpenRouter: «vendor/model».
         family    — связывает ОДНУ логическую модель у разных провайдеров:
                     если она не ответила у Яндекса, LLM::dispatch() пробует её
                     же у OpenRouter. На одного провайдера — не больше одной
                     строки семейства (см. LLM::familyRows()).
         group     — секция выпадающего списка (<optgroup>) в setup.php и /app/.
         price_in / price_out — ₽ за 1 000 000 токенов, ориентировочно (справочно
                     для оператора; в расчётах не участвует). 0 — цена неизвестна.
         ocr_only  — не чат-модель, в списки интерпретации не попадает.

       Каталог намеренно широкий. Реальная доступность зависит от провайдера:
       у Яндекса открытые модели должны быть подключены в каталоге (иначе
       HTTP 400 «Failed to get model»), у OpenRouter — от ключа и региона.
       Недоступная модель не ломает пайплайн: dispatch() переходит к следующему
       кандидату. Проверить, что реально доступно, — кнопки «Проверить каталог»
       в setup.php. ────────────────────────────────────────────────────────── */
    'AVAILABLE_MODELS'      => [
        // ── Yandex AI Studio — собственные модели ──
        ['id' => 'yandexgpt',      'label' => 'YandexGPT 5 Pro',  'provider' => 'yandex', 'full_id' => 'yandexgpt',      'group' => 'Yandex AI Studio', 'price_in' => 1200.0, 'price_out' => 1200.0],
        ['id' => 'yandexgpt-lite', 'label' => 'YandexGPT 5 Lite', 'provider' => 'yandex', 'full_id' => 'yandexgpt-lite', 'group' => 'Yandex AI Studio', 'price_in' => 200.0,  'price_out' => 200.0],
        ['id' => 'yandexgpt-32k',  'label' => 'YandexGPT Pro 32k','provider' => 'yandex', 'full_id' => 'yandexgpt-32k',  'group' => 'Yandex AI Studio', 'price_in' => 1200.0, 'price_out' => 1200.0],
        // ── Yandex AI Studio — открытые модели (нужно подключение в каталоге) ──
        ['id' => 'deepseek-r1',            'label' => 'DeepSeek R1',            'provider' => 'yandex', 'full_id' => 'deepseek-r1',            'family' => 'deepseek-r1',      'group' => 'Yandex AI Studio · открытые модели', 'price_in' => 1200.0, 'price_out' => 1200.0],
        ['id' => 'deepseek-v3',            'label' => 'DeepSeek V3',            'provider' => 'yandex', 'full_id' => 'deepseek-v3',            'family' => 'deepseek-v3',      'group' => 'Yandex AI Studio · открытые модели', 'price_in' => 500.0,  'price_out' => 500.0],
        ['id' => 'llama-3.3-70b-instruct', 'label' => 'Llama 3.3 70B Instruct', 'provider' => 'yandex', 'full_id' => 'llama-3.3-70b-instruct', 'family' => 'llama-3.3-70b',    'group' => 'Yandex AI Studio · открытые модели', 'price_in' => 500.0,  'price_out' => 500.0],
        ['id' => 'llama-3.1-8b-instruct',  'label' => 'Llama 3.1 8B Instruct',  'provider' => 'yandex', 'full_id' => 'llama-3.1-8b-instruct',  'family' => 'llama-3.1-8b',     'group' => 'Yandex AI Studio · открытые модели', 'price_in' => 150.0,  'price_out' => 150.0],
        ['id' => 'qwen3-235b-a22b',        'label' => 'Qwen3 235B A22B',        'provider' => 'yandex', 'full_id' => 'qwen3-235b-a22b-fp8',    'family' => 'qwen3-235b-a22b',  'group' => 'Yandex AI Studio · открытые модели', 'price_in' => 800.0,  'price_out' => 800.0],
        ['id' => 'qwen3-30b-a3b',          'label' => 'Qwen3 30B A3B',          'provider' => 'yandex', 'full_id' => 'qwen3-30b-a3b-fp8',      'family' => 'qwen3-30b-a3b',    'group' => 'Yandex AI Studio · открытые модели', 'price_in' => 350.0,  'price_out' => 350.0],
        ['id' => 'gemma-3-27b-it',         'label' => 'Gemma 3 27B IT',         'provider' => 'yandex', 'full_id' => 'gemma-3-27b-it',         'family' => 'gemma-3-27b',      'group' => 'Yandex AI Studio · открытые модели', 'price_in' => 450.0,  'price_out' => 450.0],
        ['id' => 'gemma-3-12b-it',         'label' => 'Gemma 3 12B IT',         'provider' => 'yandex', 'full_id' => 'gemma-3-12b-it',         'family' => 'gemma-3-12b',      'group' => 'Yandex AI Studio · открытые модели', 'price_in' => 250.0,  'price_out' => 250.0],
        ['id' => 'gpt-oss-120b',           'label' => 'GPT-OSS 120B',           'provider' => 'yandex', 'full_id' => 'gpt-oss-120b',           'family' => 'gpt-oss-120b',     'group' => 'Yandex AI Studio · открытые модели', 'price_in' => 600.0,  'price_out' => 600.0],
        ['id' => 'gpt-oss-20b',            'label' => 'GPT-OSS 20B',            'provider' => 'yandex', 'full_id' => 'gpt-oss-20b',            'family' => 'gpt-oss-20b',      'group' => 'Yandex AI Studio · открытые модели', 'price_in' => 250.0,  'price_out' => 250.0],
        // ── Yandex Vision OCR (распознавание, не чат-модель) ──
        ['id' => 'yandex-vision-ocr', 'label' => 'Yandex Vision OCR (PDF/скриншот)', 'provider' => 'yandex', 'full_id' => 'yandex-ocr-page', 'group' => 'Yandex Vision', 'price_in' => 0.0, 'price_out' => 0.0, 'ocr_only' => true],

        // ── OpenRouter · OpenAI ──
        ['id' => 'gpt-5',        'label' => 'GPT-5',        'provider' => 'openrouter', 'full_id' => 'openai/gpt-5',        'group' => 'OpenRouter · OpenAI', 'price_in' => 112.0, 'price_out' => 900.0],
        ['id' => 'gpt-5-mini',   'label' => 'GPT-5 mini',   'provider' => 'openrouter', 'full_id' => 'openai/gpt-5-mini',   'group' => 'OpenRouter · OpenAI', 'price_in' => 22.0,  'price_out' => 180.0],
        ['id' => 'gpt-5-nano',   'label' => 'GPT-5 nano',   'provider' => 'openrouter', 'full_id' => 'openai/gpt-5-nano',   'group' => 'OpenRouter · OpenAI', 'price_in' => 4.5,   'price_out' => 36.0],
        ['id' => 'gpt-5.1',      'label' => 'GPT-5.1',      'provider' => 'openrouter', 'full_id' => 'openai/gpt-5.1',      'group' => 'OpenRouter · OpenAI', 'price_in' => 112.0, 'price_out' => 900.0],
        ['id' => 'gpt-4.1',      'label' => 'GPT-4.1',      'provider' => 'openrouter', 'full_id' => 'openai/gpt-4.1',      'group' => 'OpenRouter · OpenAI', 'price_in' => 180.0, 'price_out' => 720.0],
        ['id' => 'gpt-4.1-mini', 'label' => 'GPT-4.1 mini', 'provider' => 'openrouter', 'full_id' => 'openai/gpt-4.1-mini', 'group' => 'OpenRouter · OpenAI', 'price_in' => 36.0,  'price_out' => 144.0],
        ['id' => 'gpt-4.1-nano', 'label' => 'GPT-4.1 nano', 'provider' => 'openrouter', 'full_id' => 'openai/gpt-4.1-nano', 'group' => 'OpenRouter · OpenAI', 'price_in' => 9.0,   'price_out' => 36.0],
        ['id' => 'gpt-4o',       'label' => 'GPT-4o',       'provider' => 'openrouter', 'full_id' => 'openai/gpt-4o',       'group' => 'OpenRouter · OpenAI', 'price_in' => 225.0, 'price_out' => 900.0],
        ['id' => 'gpt-4o-mini',  'label' => 'GPT-4o mini',  'provider' => 'openrouter', 'full_id' => 'openai/gpt-4o-mini',  'group' => 'OpenRouter · OpenAI', 'price_in' => 13.5,  'price_out' => 54.0],
        ['id' => 'o3',           'label' => 'o3 (reasoning)','provider' => 'openrouter','full_id' => 'openai/o3',           'group' => 'OpenRouter · OpenAI', 'price_in' => 180.0, 'price_out' => 720.0],
        ['id' => 'o4-mini',      'label' => 'o4-mini (reasoning)', 'provider' => 'openrouter', 'full_id' => 'openai/o4-mini', 'group' => 'OpenRouter · OpenAI', 'price_in' => 99.0, 'price_out' => 396.0],
        ['id' => 'openrouter-gpt-oss-120b', 'label' => 'GPT-OSS 120B', 'provider' => 'openrouter', 'full_id' => 'openai/gpt-oss-120b', 'family' => 'gpt-oss-120b', 'group' => 'OpenRouter · OpenAI', 'price_in' => 8.0,  'price_out' => 40.0],
        ['id' => 'openrouter-gpt-oss-20b',  'label' => 'GPT-OSS 20B',  'provider' => 'openrouter', 'full_id' => 'openai/gpt-oss-20b',  'family' => 'gpt-oss-20b',  'group' => 'OpenRouter · OpenAI', 'price_in' => 4.5,  'price_out' => 18.0],

        // ── OpenRouter · Anthropic ──
        ['id' => 'claude-opus-4.5',   'label' => 'Claude Opus 4.5',   'provider' => 'openrouter', 'full_id' => 'anthropic/claude-opus-4.5',   'group' => 'OpenRouter · Anthropic', 'price_in' => 450.0,  'price_out' => 2250.0],
        ['id' => 'claude-sonnet-4.5', 'label' => 'Claude Sonnet 4.5', 'provider' => 'openrouter', 'full_id' => 'anthropic/claude-sonnet-4.5', 'group' => 'OpenRouter · Anthropic', 'price_in' => 270.0,  'price_out' => 1350.0],
        ['id' => 'claude-haiku-4.5',  'label' => 'Claude Haiku 4.5',  'provider' => 'openrouter', 'full_id' => 'anthropic/claude-haiku-4.5',  'group' => 'OpenRouter · Anthropic', 'price_in' => 90.0,   'price_out' => 450.0],
        ['id' => 'claude-opus-4.1',   'label' => 'Claude Opus 4.1',   'provider' => 'openrouter', 'full_id' => 'anthropic/claude-opus-4.1',   'group' => 'OpenRouter · Anthropic', 'price_in' => 1350.0, 'price_out' => 6750.0],
        ['id' => 'claude-sonnet-4',   'label' => 'Claude Sonnet 4',   'provider' => 'openrouter', 'full_id' => 'anthropic/claude-sonnet-4',   'group' => 'OpenRouter · Anthropic', 'price_in' => 270.0,  'price_out' => 1350.0],
        ['id' => 'claude-3.7-sonnet', 'label' => 'Claude 3.7 Sonnet', 'provider' => 'openrouter', 'full_id' => 'anthropic/claude-3.7-sonnet', 'group' => 'OpenRouter · Anthropic', 'price_in' => 270.0,  'price_out' => 1350.0],
        ['id' => 'claude-3.5-haiku',  'label' => 'Claude 3.5 Haiku',  'provider' => 'openrouter', 'full_id' => 'anthropic/claude-3.5-haiku',  'group' => 'OpenRouter · Anthropic', 'price_in' => 72.0,   'price_out' => 360.0],

        // ── OpenRouter · Google ──
        ['id' => 'gemini-2.5-pro',        'label' => 'Gemini 2.5 Pro',        'provider' => 'openrouter', 'full_id' => 'google/gemini-2.5-pro',         'group' => 'OpenRouter · Google', 'price_in' => 112.0, 'price_out' => 900.0],
        ['id' => 'gemini-2.5-flash',      'label' => 'Gemini 2.5 Flash',      'provider' => 'openrouter', 'full_id' => 'google/gemini-2.5-flash',       'group' => 'OpenRouter · Google', 'price_in' => 27.0,  'price_out' => 225.0],
        ['id' => 'gemini-2.5-flash-lite', 'label' => 'Gemini 2.5 Flash Lite', 'provider' => 'openrouter', 'full_id' => 'google/gemini-2.5-flash-lite',  'group' => 'OpenRouter · Google', 'price_in' => 9.0,   'price_out' => 36.0],
        ['id' => 'gemini-2.0-flash',      'label' => 'Gemini 2.0 Flash',      'provider' => 'openrouter', 'full_id' => 'google/gemini-2.0-flash-001',   'group' => 'OpenRouter · Google', 'price_in' => 9.0,   'price_out' => 36.0],
        ['id' => 'gemini-2.0-flash-lite', 'label' => 'Gemini 2.0 Flash Lite', 'provider' => 'openrouter', 'full_id' => 'google/gemini-2.0-flash-lite-001', 'group' => 'OpenRouter · Google', 'price_in' => 7.0, 'price_out' => 27.0],
        ['id' => 'openrouter-gemma-3-27b-it', 'label' => 'Gemma 3 27B IT', 'provider' => 'openrouter', 'full_id' => 'google/gemma-3-27b-it', 'family' => 'gemma-3-27b', 'group' => 'OpenRouter · Google', 'price_in' => 9.0,  'price_out' => 18.0],
        ['id' => 'openrouter-gemma-3-12b-it', 'label' => 'Gemma 3 12B IT', 'provider' => 'openrouter', 'full_id' => 'google/gemma-3-12b-it', 'family' => 'gemma-3-12b', 'group' => 'OpenRouter · Google', 'price_in' => 4.5,  'price_out' => 9.0],

        // ── OpenRouter · Meta ──
        ['id' => 'openrouter-llama-3.3-70b', 'label' => 'Llama 3.3 70B Instruct', 'provider' => 'openrouter', 'full_id' => 'meta-llama/llama-3.3-70b-instruct', 'family' => 'llama-3.3-70b', 'group' => 'OpenRouter · Meta', 'price_in' => 6.0,  'price_out' => 22.0],
        ['id' => 'openrouter-llama-3.1-8b',  'label' => 'Llama 3.1 8B Instruct',  'provider' => 'openrouter', 'full_id' => 'meta-llama/llama-3.1-8b-instruct',  'family' => 'llama-3.1-8b',  'group' => 'OpenRouter · Meta', 'price_in' => 2.0,  'price_out' => 3.0],
        ['id' => 'llama-4-maverick',         'label' => 'Llama 4 Maverick',       'provider' => 'openrouter', 'full_id' => 'meta-llama/llama-4-maverick',       'group' => 'OpenRouter · Meta', 'price_in' => 13.5, 'price_out' => 54.0],
        ['id' => 'llama-4-scout',            'label' => 'Llama 4 Scout',          'provider' => 'openrouter', 'full_id' => 'meta-llama/llama-4-scout',          'group' => 'OpenRouter · Meta', 'price_in' => 7.0,  'price_out' => 27.0],

        // ── OpenRouter · DeepSeek ──
        ['id' => 'openrouter-deepseek-r1', 'label' => 'DeepSeek R1',        'provider' => 'openrouter', 'full_id' => 'deepseek/deepseek-r1',        'family' => 'deepseek-r1', 'group' => 'OpenRouter · DeepSeek', 'price_in' => 40.0, 'price_out' => 195.0],
        ['id' => 'openrouter-deepseek-v3', 'label' => 'DeepSeek V3 (chat)', 'provider' => 'openrouter', 'full_id' => 'deepseek/deepseek-chat',      'family' => 'deepseek-v3', 'group' => 'OpenRouter · DeepSeek', 'price_in' => 22.0, 'price_out' => 77.0],
        ['id' => 'deepseek-r1-0528',       'label' => 'DeepSeek R1 0528',   'provider' => 'openrouter', 'full_id' => 'deepseek/deepseek-r1-0528',   'group' => 'OpenRouter · DeepSeek', 'price_in' => 45.0, 'price_out' => 200.0],
        ['id' => 'deepseek-v3-0324',       'label' => 'DeepSeek V3 0324',   'provider' => 'openrouter', 'full_id' => 'deepseek/deepseek-chat-v3-0324', 'group' => 'OpenRouter · DeepSeek', 'price_in' => 25.0, 'price_out' => 80.0],
        ['id' => 'deepseek-r1-distill-llama-70b', 'label' => 'DeepSeek R1 Distill Llama 70B', 'provider' => 'openrouter', 'full_id' => 'deepseek/deepseek-r1-distill-llama-70b', 'group' => 'OpenRouter · DeepSeek', 'price_in' => 9.0, 'price_out' => 36.0],

        // ── OpenRouter · Qwen ──
        ['id' => 'openrouter-qwen3-235b-a22b', 'label' => 'Qwen3 235B A22B', 'provider' => 'openrouter', 'full_id' => 'qwen/qwen3-235b-a22b', 'family' => 'qwen3-235b-a22b', 'group' => 'OpenRouter · Qwen', 'price_in' => 13.0, 'price_out' => 50.0],
        ['id' => 'openrouter-qwen3-30b-a3b',   'label' => 'Qwen3 30B A3B',   'provider' => 'openrouter', 'full_id' => 'qwen/qwen3-30b-a3b',   'family' => 'qwen3-30b-a3b',   'group' => 'OpenRouter · Qwen', 'price_in' => 7.0,  'price_out' => 27.0],
        ['id' => 'qwen3-max',                  'label' => 'Qwen3 Max',       'provider' => 'openrouter', 'full_id' => 'qwen/qwen3-max',       'group' => 'OpenRouter · Qwen', 'price_in' => 110.0, 'price_out' => 550.0],
        ['id' => 'qwen3-coder',                'label' => 'Qwen3 Coder',     'provider' => 'openrouter', 'full_id' => 'qwen/qwen3-coder',     'group' => 'OpenRouter · Qwen', 'price_in' => 27.0, 'price_out' => 110.0],
        ['id' => 'qwen-2.5-72b-instruct',      'label' => 'Qwen 2.5 72B Instruct', 'provider' => 'openrouter', 'full_id' => 'qwen/qwen-2.5-72b-instruct', 'group' => 'OpenRouter · Qwen', 'price_in' => 11.0, 'price_out' => 36.0],

        // ── OpenRouter · Mistral ──
        ['id' => 'mistral-large',      'label' => 'Mistral Large 2411',      'provider' => 'openrouter', 'full_id' => 'mistralai/mistral-large-2411',              'group' => 'OpenRouter · Mistral', 'price_in' => 180.0, 'price_out' => 540.0],
        ['id' => 'mistral-medium-3',   'label' => 'Mistral Medium 3',        'provider' => 'openrouter', 'full_id' => 'mistralai/mistral-medium-3',                'group' => 'OpenRouter · Mistral', 'price_in' => 36.0,  'price_out' => 180.0],
        ['id' => 'mistral-small-3.2',  'label' => 'Mistral Small 3.2 24B',   'provider' => 'openrouter', 'full_id' => 'mistralai/mistral-small-3.2-24b-instruct',  'group' => 'OpenRouter · Mistral', 'price_in' => 5.0,   'price_out' => 27.0],
        ['id' => 'mistral-nemo',       'label' => 'Mistral Nemo 12B',        'provider' => 'openrouter', 'full_id' => 'mistralai/mistral-nemo',                    'group' => 'OpenRouter · Mistral', 'price_in' => 2.0,   'price_out' => 5.0],
        ['id' => 'magistral-medium',   'label' => 'Magistral Medium (reasoning)', 'provider' => 'openrouter', 'full_id' => 'mistralai/magistral-medium-2506',      'group' => 'OpenRouter · Mistral', 'price_in' => 180.0, 'price_out' => 450.0],
        ['id' => 'pixtral-large',      'label' => 'Pixtral Large 2411 (vision)',  'provider' => 'openrouter', 'full_id' => 'mistralai/pixtral-large-2411',         'group' => 'OpenRouter · Mistral', 'price_in' => 180.0, 'price_out' => 540.0],

        // ── OpenRouter · xAI ──
        ['id' => 'grok-4',      'label' => 'Grok 4',      'provider' => 'openrouter', 'full_id' => 'x-ai/grok-4',      'group' => 'OpenRouter · xAI', 'price_in' => 270.0, 'price_out' => 1350.0],
        ['id' => 'grok-4-fast', 'label' => 'Grok 4 Fast', 'provider' => 'openrouter', 'full_id' => 'x-ai/grok-4-fast', 'group' => 'OpenRouter · xAI', 'price_in' => 18.0,  'price_out' => 45.0],
        ['id' => 'grok-3',      'label' => 'Grok 3',      'provider' => 'openrouter', 'full_id' => 'x-ai/grok-3',      'group' => 'OpenRouter · xAI', 'price_in' => 270.0, 'price_out' => 1350.0],
        ['id' => 'grok-3-mini', 'label' => 'Grok 3 Mini', 'provider' => 'openrouter', 'full_id' => 'x-ai/grok-3-mini', 'group' => 'OpenRouter · xAI', 'price_in' => 27.0,  'price_out' => 45.0],

        // ── OpenRouter · прочие вендоры ──
        ['id' => 'glm-4.6',          'label' => 'GLM-4.6 (Z.ai)',            'provider' => 'openrouter', 'full_id' => 'z-ai/glm-4.6',          'group' => 'OpenRouter · прочие', 'price_in' => 36.0,  'price_out' => 160.0],
        ['id' => 'glm-4.5',          'label' => 'GLM-4.5 (Z.ai)',            'provider' => 'openrouter', 'full_id' => 'z-ai/glm-4.5',          'group' => 'OpenRouter · прочие', 'price_in' => 36.0,  'price_out' => 160.0],
        ['id' => 'kimi-k2',          'label' => 'Kimi K2 (Moonshot)',        'provider' => 'openrouter', 'full_id' => 'moonshotai/kimi-k2',    'group' => 'OpenRouter · прочие', 'price_in' => 50.0,  'price_out' => 220.0],
        ['id' => 'nemotron-super-49b','label' => 'Llama 3.3 Nemotron Super 49B', 'provider' => 'openrouter', 'full_id' => 'nvidia/llama-3.3-nemotron-super-49b-v1', 'group' => 'OpenRouter · прочие', 'price_in' => 12.0, 'price_out' => 36.0],
        ['id' => 'phi-4',            'label' => 'Phi-4 (Microsoft)',         'provider' => 'openrouter', 'full_id' => 'microsoft/phi-4',       'group' => 'OpenRouter · прочие', 'price_in' => 6.0,   'price_out' => 12.0],
        ['id' => 'command-r-plus',   'label' => 'Command R+ (Cohere)',       'provider' => 'openrouter', 'full_id' => 'cohere/command-r-plus-08-2024', 'group' => 'OpenRouter · прочие', 'price_in' => 225.0, 'price_out' => 900.0],
        ['id' => 'nova-pro',         'label' => 'Amazon Nova Pro',           'provider' => 'openrouter', 'full_id' => 'amazon/nova-pro-v1',    'group' => 'OpenRouter · прочие', 'price_in' => 72.0,  'price_out' => 290.0],
        ['id' => 'nova-lite',        'label' => 'Amazon Nova Lite',          'provider' => 'openrouter', 'full_id' => 'amazon/nova-lite-v1',   'group' => 'OpenRouter · прочие', 'price_in' => 5.5,   'price_out' => 22.0],
        ['id' => 'sonar-pro',        'label' => 'Perplexity Sonar Pro',      'provider' => 'openrouter', 'full_id' => 'perplexity/sonar-pro',  'group' => 'OpenRouter · прочие', 'price_in' => 270.0, 'price_out' => 1350.0],
        ['id' => 'sonar',            'label' => 'Perplexity Sonar',          'provider' => 'openrouter', 'full_id' => 'perplexity/sonar',      'group' => 'OpenRouter · прочие', 'price_in' => 90.0,  'price_out' => 90.0],

        // ── OpenRouter · авто-роутер (сам выбирает модель под запрос) ──
        ['id' => 'openrouter-auto', 'label' => 'Auto (OpenRouter сам выберет модель)', 'provider' => 'openrouter', 'full_id' => 'openrouter/auto', 'group' => 'OpenRouter · авто', 'price_in' => 0.0, 'price_out' => 0.0],
    ],
];

/**
 * Overlay operator-editable settings from the `settings` table so setup.php can
 * change API keys / provider / models / mail without code edits. Best-effort:
 * never fails config loading if the DB is missing or locked. Whitelist-gated.
 */
(static function (array &$config): void {
    $dbPath = $config['DB_PATH'];
    if (!is_string($dbPath) || !file_exists($dbPath)) return;
    $overlayKeys = cfg_settings_whitelist();
    $intKeys = ['SMTP_PORT'];
    $csvKeys = ['LLM_OCR_MODELS']; // stored comma-separated, consumed as array
    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        $stmt = $pdo->query("SELECT key, value FROM settings");
        if ($stmt === false) return;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $k = (string) ($row['key'] ?? '');
            $v = (string) ($row['value'] ?? '');
            if ($v === '' || !in_array($k, $overlayKeys, true)) continue;
            if (in_array($k, $csvKeys, true)) {
                $config[$k] = array_values(array_filter(array_map('trim', explode(',', $v))));
            } elseif (in_array($k, $intKeys, true)) {
                $config[$k] = (int) $v;
            } else {
                $config[$k] = $v;
            }
        }
    } catch (Throwable $e) {
        // DB unavailable / table missing → keep env + hardcoded values.
    }
})($config);

return $config;
