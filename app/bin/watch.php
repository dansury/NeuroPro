<?php
/**
 * Watch-folder daemon: poll WATCH_DIR for new Эгоскоп .xls/.xlsx exports and
 * auto-ingest each into a profile (status = awaiting_screenshot). The operator
 * then opens the dashboard, where the new profile is waiting for its screenshot.
 *
 * Usage:  php app/bin/watch.php [intervalSeconds=5]
 *
 * Processed files are recorded in the DB (by sha1) so a file is ingested once.
 */

require_once __DIR__ . '/../lib/bootstrap.php';

$cfg = np_boot();
$interval = max(2, (int) ($argv[1] ?? 5));
$dir = $cfg['WATCH_DIR'];
fwrite(STDOUT, "[watch] Наблюдаю за: $dir (каждые {$interval}с)\n");

Db::pdo()->exec("CREATE TABLE IF NOT EXISTS watch_seen (hash TEXT PRIMARY KEY, path TEXT, profile_id INTEGER, seen_at TEXT DEFAULT (datetime('now')))");

while (true) {
    foreach (glob(rtrim($dir, '/') . '/*.{xls,xlsx,XLS,XLSX}', GLOB_BRACE) ?: [] as $file) {
        $hash = sha1_file($file);
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
            fwrite(STDOUT, "[watch] + Профиль #$id: {$prof['name']} / {$prof['methodic']} — ожидает скриншот\n");
        } catch (Throwable $e) {
            Db::q('INSERT OR IGNORE INTO watch_seen (hash, path, profile_id) VALUES (?,?,?)', [$hash, $file, 0]);
            fwrite(STDERR, "[watch] ! Ошибка с $file: " . $e->getMessage() . "\n");
        }
    }
    sleep($interval);
}
