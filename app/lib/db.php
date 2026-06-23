<?php
/**
 * SQLite storage for NeuroPro: profiles, prompt families + versions, and saved
 * interpretations. Shares the same database file as the operator settings store
 * (config.php overlay), so one file holds everything.
 */

final class Db {
    private static ?PDO $pdo = null;

    public static function init(string $path): PDO {
        if (self::$pdo !== null) return self::$pdo;
        $dir = dirname($path);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        self::$pdo = $pdo;
        self::migrate($pdo);
        return $pdo;
    }

    public static function pdo(): PDO {
        if (self::$pdo === null) throw new RuntimeException('Db not initialized');
        return self::$pdo;
    }

    private static function migrate(PDO $pdo): void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT
        )");

        // A prompt family per test type (e.g. one for СМУ, one for LSI).
        $pdo->exec("CREATE TABLE IF NOT EXISTS prompts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            test_key TEXT NOT NULL,
            name TEXT NOT NULL,
            active_version_id INTEGER,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )");

        // Each edit is a new immutable version with its own model + comment.
        $pdo->exec("CREATE TABLE IF NOT EXISTS prompt_versions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            prompt_id INTEGER NOT NULL,
            version_no INTEGER NOT NULL,
            body TEXT NOT NULL,
            model_id TEXT,
            provider TEXT,
            comment TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            FOREIGN KEY (prompt_id) REFERENCES prompts(id) ON DELETE CASCADE
        )");

        // Imported test profiles (cognitive scores + later physiological data).
        $pdo->exec("CREATE TABLE IF NOT EXISTS profiles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            age TEXT,
            sex TEXT,
            test_date TEXT,
            methodic TEXT,
            test_key TEXT,
            scores_json TEXT,
            phys_json TEXT,
            phys_ocr_text TEXT,
            source_file TEXT,
            status TEXT NOT NULL DEFAULT 'awaiting_screenshot',
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )");

        // Saved interpretations, each tied to the exact prompt version used.
        $pdo->exec("CREATE TABLE IF NOT EXISTS interpretations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            profile_id INTEGER NOT NULL,
            prompt_version_id INTEGER NOT NULL,
            model_id TEXT,
            content TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            FOREIGN KEY (profile_id) REFERENCES profiles(id) ON DELETE CASCADE,
            FOREIGN KEY (prompt_version_id) REFERENCES prompt_versions(id)
        )");

        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_versions_prompt ON prompt_versions(prompt_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_interp_version ON interpretations(prompt_version_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_interp_profile ON interpretations(profile_id)");
    }

    /* convenience helpers */
    public static function q(string $sql, array $args = []): PDOStatement {
        $st = self::pdo()->prepare($sql);
        $st->execute($args);
        return $st;
    }
    public static function one(string $sql, array $args = []): ?array {
        $r = self::q($sql, $args)->fetch();
        return $r === false ? null : $r;
    }
    public static function all(string $sql, array $args = []): array {
        return self::q($sql, $args)->fetchAll();
    }
    public static function insert(string $sql, array $args = []): int {
        self::q($sql, $args);
        return (int) self::pdo()->lastInsertId();
    }
}
