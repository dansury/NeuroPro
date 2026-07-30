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
        // Схема должна совпадать с SettingsStore: кто из них инициализирует БД
        // первым, тот и создаёт таблицу, а `CREATE TABLE IF NOT EXISTS` у второго
        // молча ничего не делает. Без updated_at сохранение настроек в setup.php
        // падало с «table settings has no column named updated_at».
        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
            key         TEXT PRIMARY KEY,
            value       TEXT NOT NULL,
            updated_at  TEXT NOT NULL
        )");
        // Догоняем БД, созданные старой схемой (без updated_at).
        $cols = $pdo->query("PRAGMA table_info(settings)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('updated_at', $cols, true)) {
            $pdo->exec("ALTER TABLE settings ADD COLUMN updated_at TEXT NOT NULL DEFAULT ''");
        }

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

        // Правка отчёта оператором (двойной клик по абзацу) — отмечаем время, чтобы
        // в списке было видно, что текст уже не в том виде, в котором его выдала
        // нейросеть. Догоняем БД, созданные до появления правки.
        $icols = $pdo->query("PRAGMA table_info(interpretations)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if ($icols && !in_array('edited_at', $icols, true)) {
            $pdo->exec("ALTER TABLE interpretations ADD COLUMN edited_at TEXT");
        }
        // Снимок слоя математики: пороги оператора и посчитанная по ним раскладка
        // на момент интерпретации. Пороги матрицы общие для сервиса и меняются
        // мышью прямо на картинке, поэтому отчёт, собранный позже, считался бы
        // уже по другим правилам и расходился бы с текстом (Interpret::mathLayer).
        if ($icols && !in_array('metrics_json', $icols, true)) {
            $pdo->exec("ALTER TABLE interpretations ADD COLUMN metrics_json TEXT");
        }
        // Версия промпта ВТОРОГО слоя (литературная правка), если она применялась:
        // история должна помнить обе версии, которыми сделан текст.
        if ($icols && !in_array('style_version_id', $icols, true)) {
            $pdo->exec("ALTER TABLE interpretations ADD COLUMN style_version_id INTEGER");
        }

        // Сам скриншот «смысло-эмоциональной значимости» (data URI): оператору
        // нужно видеть исходную картинку рядом с распознанным расчётом, чтобы
        // сверить цифры с положением графиков. Догоняем БД, созданные раньше.
        $pcols = $pdo->query("PRAGMA table_info(profiles)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if ($pcols && !in_array('phys_image', $pcols, true)) {
            $pdo->exec("ALTER TABLE profiles ADD COLUMN phys_image TEXT");
        }

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
