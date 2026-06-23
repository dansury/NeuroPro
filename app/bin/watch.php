<?php
/**
 * Watch-folder daemon: poll WATCH_DIR for new Эгоскоп .xls/.xlsx exports and
 * auto-ingest each into a profile (status = awaiting_screenshot). The operator
 * then opens the dashboard, where the new profile is waiting for its screenshot.
 *
 * Кроссплатформенно: работает локально на Windows (см. watch.bat) и на *nix.
 *
 * Usage:  php app/bin/watch.php [intervalSeconds=5]
 *
 * Идемпотентность: обработанные файлы фиксируются в БД по sha1, поэтому файл
 * подхватывается один раз. Частично записанные файлы (копирование/выгрузка ещё
 * идёт) пропускаются, пока их размер и mtime не стабилизируются между опросами.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';

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
$interval = max(2, (int) ($argv[1] ?? 5));
// Нормализуем путь: убираем хвостовые слэши обоих видов (Windows: «C:\incoming\»).
$dir = rtrim((string) $cfg['WATCH_DIR'], "/\\");
// URL приложения анализа: открываем в браузере при появлении нового файла.
$appUrl = (string) ($cfg['APP_URL'] ?? '');

if ($dir === '' || !is_dir($dir)) {
    watch_err("[watch] ! Папка наблюдения не найдена: «{$dir}». Проверьте WATCH_DIR.\n");
    exit(1);
}

watch_out("[watch] Наблюдаю за: {$dir} (каждые {$interval}с)\n");
if ($appUrl !== '') {
    watch_out("[watch] При новом файле открою: {$appUrl}\n");
}

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
        if (($pending[$file] ?? null) === $sig) {
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

    sleep($interval);
}
