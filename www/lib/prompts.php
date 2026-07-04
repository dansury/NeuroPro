<?php
/**
 * Prompt families and their version history.
 *
 * Rules (from the spec):
 *  - Editing a prompt creates a NEW version (old versions are kept).
 *  - Each version carries its own model/provider choice and an optional comment.
 *  - One version per family is "active" — used in automatic mode.
 *  - Interpretations are stored against the exact version that produced them.
 *  - A version may be deleted ONLY if no interpretations reference it.
 *  - Interpretations may be deleted (UI confirms first).
 */

require_once __DIR__ . '/db.php';

final class Prompts {
    /** Ensure a prompt family exists for each known test, seeded from a file. */
    public static function seed(string $testKey, string $name, string $body, string $defaultModel = 'deepseek-r1', string $provider = 'yandex'): int {
        $existing = Db::one('SELECT id FROM prompts WHERE test_key = ?', [$testKey]);
        if ($existing) return (int) $existing['id'];
        $promptId = Db::insert('INSERT INTO prompts (test_key, name) VALUES (?, ?)', [$testKey, $name]);
        $versionId = self::addVersion($promptId, $body, $defaultModel, $provider, 'Импортировано из исходного промпта');
        self::setActive($promptId, $versionId);
        return $promptId;
    }

    public static function family(string $testKey): ?array {
        return Db::one('SELECT * FROM prompts WHERE test_key = ?', [$testKey]);
    }

    public static function familyById(int $id): ?array {
        return Db::one('SELECT * FROM prompts WHERE id = ?', [$id]);
    }

    public static function allFamilies(): array {
        return Db::all('SELECT * FROM prompts ORDER BY test_key');
    }

    public static function versions(int $promptId): array {
        return Db::all('SELECT v.*, (SELECT COUNT(*) FROM interpretations i WHERE i.prompt_version_id = v.id) AS interp_count
                        FROM prompt_versions v WHERE v.prompt_id = ? ORDER BY v.version_no DESC', [$promptId]);
    }

    public static function version(int $versionId): ?array {
        return Db::one('SELECT * FROM prompt_versions WHERE id = ?', [$versionId]);
    }

    /** Create a new version for a family. */
    public static function addVersion(int $promptId, string $body, ?string $modelId, ?string $provider, ?string $comment): int {
        $row = Db::one('SELECT COALESCE(MAX(version_no), 0) AS m FROM prompt_versions WHERE prompt_id = ?', [$promptId]);
        $next = ((int) ($row['m'] ?? 0)) + 1;
        return Db::insert(
            'INSERT INTO prompt_versions (prompt_id, version_no, body, model_id, provider, comment) VALUES (?, ?, ?, ?, ?, ?)',
            [$promptId, $next, $body, $modelId, $provider, $comment]
        );
    }

    public static function setActive(int $promptId, int $versionId): void {
        Db::q('UPDATE prompts SET active_version_id = ? WHERE id = ?', [$versionId, $promptId]);
    }

    public static function activeVersion(string $testKey): ?array {
        $fam = self::family($testKey);
        if (!$fam || !$fam['active_version_id']) return null;
        return self::version((int) $fam['active_version_id']);
    }

    public static function interpCount(int $versionId): int {
        $r = Db::one('SELECT COUNT(*) AS c FROM interpretations WHERE prompt_version_id = ?', [$versionId]);
        return (int) ($r['c'] ?? 0);
    }

    /** Delete a version — refused if any interpretation uses it or it's active. */
    public static function deleteVersion(int $versionId): array {
        $v = self::version($versionId);
        if (!$v) return ['ok' => false, 'error' => 'Версия не найдена'];
        if (self::interpCount($versionId) > 0) {
            return ['ok' => false, 'error' => 'Нельзя удалить: по этой версии есть интерпретации'];
        }
        $fam = self::familyById((int) $v['prompt_id']);
        if ($fam && (int) $fam['active_version_id'] === $versionId) {
            return ['ok' => false, 'error' => 'Нельзя удалить активную версию — сначала выберите другую'];
        }
        Db::q('DELETE FROM prompt_versions WHERE id = ?', [$versionId]);
        return ['ok' => true];
    }

    public static function setComment(int $versionId, string $comment): void {
        Db::q('UPDATE prompt_versions SET comment = ? WHERE id = ?', [$comment, $versionId]);
    }
}
