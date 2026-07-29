<?php
/**
 * Watch-folder daemon: poll WATCH_DIR for new Эгоскоп .xls/.xlsx exports and
 * auto-ingest each into a profile (status = awaiting_screenshot). The operator
 * then opens the dashboard, where the new profile is waiting for its screenshot.
 *
 * ПОЧЕМУ bin/, а не www/: pull.php зеркалит на прод ровно содержимое www/, то
 * есть всё, что лежит там, публично доступно по HTTP. Демон — локальный
 * консольный процесс с бесконечным циклом: в вебе он бесполезен (запрос просто
 * висел бы до таймаута) и вреден (любой мог бы его дёрнуть). Плюс наблюдаемая
 * папка находится на машине оператора, а не на хостинге.
 *
 * Этот демон пишет в ЛОКАЛЬНУЮ базу (DB_PATH), поэтому годится, когда
 * приложение поднято на той же машине (`php -S 127.0.0.1:8080 -t www`). Если
 * рабочий сервис — на хостинге, оператору нужен www/tools/neuropro-watch.cmd:
 * он отправляет файлы туда по HTTP и не требует PHP на машине оператора.
 *
 * Usage:  php bin/watch.php [интервал=5] [--dir=/путь] [--once]
 *
 * Идемпотентность: обработанные файлы фиксируются в БД по sha1, поэтому файл
 * подхватывается один раз. Частично записанные файлы (копирование/выгрузка ещё
 * идёт) пропускаются, пока их размер и mtime не стабилизируются между опросами.
 */

declare(strict_types=1);

require_once __DIR__ . '/../www/lib/bootstrap.php';

// На Windows консоль по умолчанию в cp866 — переключаем ввод/вывод PHP на UTF-8,
// чтобы кириллица в логах не превращалась в «кракозябры».
if (function_exists('sapi_windows_cp_set')) {
    @sapi_windows_cp_set(65001);
}

/** Печать строки в STDOUT с немедленным сбросом буфера. */
function watch_out(string $line): void {
    fwrite(STDOUT, $line);
    fflush(STDOUT);
}

/** Печать ошибки в STDERR. */
function watch_err(string $line): void {
    fwrite(STDERR, $line);
    fflush(STDERR);
}

/**
 * Открыть URL приложения анализа в браузере оператора. Кроссплатформенно:
 * Windows (`start`), macOS (`open`), *nix (`xdg-open`). Best-effort — любые
 * ошибки заглушаются, чтобы не прерывать наблюдение за папкой.
 */
function watch_open_browser(string $url): void {
    if ($url === '') return;
    $arg = escapeshellarg($url);
    if (stripos(PHP_OS_FAMILY, 'Windows') !== false) {
        // `start` — встроенная команда cmd; первый "" — это заголовок окна.
        $cmd = 'start "" ' . $arg;
        $proc = @popen('cmd /c ' . $cmd, 'r');
        if (is_resource($proc)) { @pclose($proc); }
        return;
    }
    $bin = PHP_OS_FAMILY === 'Darwin' ? 'open' : 'xdg-open';
    @exec($bin . ' ' . $arg . ' > /dev/null 2>&1 &');
}

$cfg = np_boot();

// Аргументы: позиционный интервал (обратная совместимость) + ключи.
$interval = 5;
$dirArg = null;
$once = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--dir=')) { $dirArg = substr($arg, 6); continue; }
    if ($arg === '--once')               { $once = true;             continue; }
    if (str_starts_with($arg, '--interval=')) { $interval = (int) substr($arg, 11); continue; }
    if (ctype_digit($arg))               { $interval = (int) $arg;   continue; }
    watch_err("[watch] ! Неизвестный аргумент: {$arg}\n");
    watch_err("[watch]   Usage: php bin/watch.php [интервал=5] [--dir=/путь] [--once]\n");
    exit(2);
}
$interval = max(2, $interval);
// Нормализуем путь: убираем хвостовые слэши обоих видов (Windows: «C:\incoming\»).
$dir = rtrim($dirArg ?? (string) $cfg['WATCH_DIR'], "/\\");
// URL приложения анализа: открываем в браузере при появлении нового файла.
$appUrl = (string) ($cfg['APP_URL'] ?? '');

if ($dir === '' || !is_dir($dir)) {
    watch_err("[watch] ! Папка наблюдения не найдена: «{$dir}».\n");
    watch_err("[watch]   Задайте её ключом --dir=/путь или переменной WATCH_DIR.\n");
    exit(1);
}

watch_out("[watch] ─────────────────────────────────────────────\n");
watch_out("[watch] Папка отслеживается: {$dir}\n");
watch_out("[watch] Опрос: каждые {$interval}с (.xls, .xlsx)\n");
watch_out("[watch] База профилей: {$cfg['DB_PATH']}\n");
if ($appUrl !== '') {
    watch_out("[watch] При новом файле открою: {$appUrl}\n");
}
// Профили этот демон кладёт в локальную БД. Если APP_URL смотрит на чужой хост,
// оператор их там не увидит — предупреждаем сразу, а не после первой выгрузки.
$appHost = strtolower((string) parse_url($appUrl, PHP_URL_HOST));
if ($appHost !== '' && !in_array($appHost, ['localhost', '127.0.0.1', '::1'], true)) {
    watch_out("[watch] ⚠ APP_URL ведёт на {$appHost}, а профили пишутся в локальную БД —\n");
    watch_out("[watch]   там их не будет. Для работы с хостингом используйте\n");
    watch_out("[watch]   www/tools/neuropro-watch.cmd (отправляет файлы на сервер по HTTP).\n");
}
watch_out("[watch] ─────────────────────────────────────────────\n");
watch_out($once ? "[watch] Режим одного прохода (--once).\n" : "[watch] Остановить: Ctrl+C.\n");

Db::pdo()->exec("CREATE TABLE IF NOT EXISTS watch_seen (hash TEXT PRIMARY KEY, path TEXT, profile_id INTEGER, seen_at TEXT DEFAULT (datetime('now')))");

// Разрешённые расширения экспорта Эгоскопа (регистр игнорируем — важно для NTFS).
$allowedExt = ['xls', 'xlsx'];

// Снимок «размер|mtime» по пути с прошлого опроса. Файл считаем готовым к
// ingest только когда его метрики не изменились между двумя опросами — это
// отсекает ещё не дописанные выгрузки/копии (актуально для локальной Windows).
$pending = [];

while (true) {
    $stable = [];

    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $file = $dir . DIRECTORY_SEPARATOR . $entry;
        if (!is_file($file)) continue;
        $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) continue;

        $size = @filesize($file);
        $mtime = @filemtime($file);
        if ($size === false || $mtime === false) continue;
        $sig = $size . '|' . $mtime;

        // Файл готов, только если его сигнатура совпала с прошлым опросом.
        // В режиме --once второго опроса не будет — берём всё как есть.
        if ($once || ($pending[$file] ?? null) === $sig) {
            $stable[$file] = true;
        } else {
            $pending[$file] = $sig;
        }
    }

    $ingested = 0;
    foreach (array_keys($stable) as $file) {
        unset($pending[$file]);
        $hash = sha1_file($file);
        if ($hash === false) continue;
        if (Db::one('SELECT 1 FROM watch_seen WHERE hash=?', [$hash])) continue;
        try {
            $prof = Profile::fromFile($file);
            $id = Db::insert(
                'INSERT INTO profiles (name, age, sex, test_date, methodic, test_key, scores_json, source_file, status)
                 VALUES (?,?,?,?,?,?,?,?,?)',
                [$prof['name'], $prof['age'], $prof['sex'], $prof['date'], $prof['methodic'], $prof['test_key'],
                 json_encode($prof['scores'], JSON_UNESCAPED_UNICODE), $file, 'awaiting_screenshot']
            );
            Db::q('INSERT INTO watch_seen (hash, path, profile_id) VALUES (?,?,?)', [$hash, $file, $id]);
            $ingested++;
            watch_out("[watch] + Профиль #{$id}: {$prof['name']} / {$prof['methodic']} — ожидает скриншот\n");
        } catch (Throwable $e) {
            Db::q('INSERT OR IGNORE INTO watch_seen (hash, path, profile_id) VALUES (?,?,?)', [$hash, $file, 0]);
            watch_err("[watch] ! Ошибка с {$file}: " . $e->getMessage() . "\n");
        }
    }

    // Появился хотя бы один новый профиль — открываем приложение анализа в
    // браузере оператора (один раз за цикл, чтобы не плодить вкладки).
    if ($ingested > 0 && $appUrl !== '') {
        watch_open_browser($appUrl);
        watch_out("[watch] → Открываю {$appUrl}\n");
    }

    // Подчищаем из pending пути исчезнувших файлов, чтобы карта не росла.
    foreach (array_keys($pending) as $p) {
        if (!is_file($p)) unset($pending[$p]);
    }

    if ($once) {
        watch_out("[watch] Проход завершён: новых профилей — {$ingested}.\n");
        break;
    }

    sleep($interval);
}
