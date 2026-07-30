<?php
declare(strict_types=1);

/**
 * КОРЗИНА: недавно удалённые отчёты.
 *
 * Удаление в сервисе делает оператор одним кликом посреди работы, и раньше оно
 * было необратимым: снесённая интерпретация — это потраченный запрос к
 * нейросети и правки текста, снесённый анализ — ещё и скриншот с расчётом.
 * Поэтому удаление ТОЛЬКО ПОМЕЧАЕТ строку временем (`deleted_at`): месяц она
 * лежит в «Удалённых», откуда её можно вернуть, и лишь потом исчезает совсем.
 *
 * Правила:
 *  - Удалённый АНАЛИЗ уносит с собой свои интерпретации, но их собственный
 *    `deleted_at` не трогает: анализ вернулся — вернулись все его отчёты в том
 *    же составе, что и были.
 *  - Интерпретации удалённого анализа не показываются в корзине отдельной
 *    строкой: восстанавливать их поодиночке некуда, пока нет самого анализа.
 *  - Окончательное удаление — вручную («удалить навсегда») или автоматически
 *    через KEEP_DAYS дней. Чистка вызывается со страниц, где корзина видна:
 *    отдельного крона у сервиса нет, а лишний DELETE по индексу ничего не стоит.
 */

require_once __DIR__ . '/db.php';

final class Trash {
    /** Сколько дней удалённое лежит в корзине, прежде чем исчезнуть насовсем. */
    public const KEEP_DAYS = 30;

    /* ─────────────── удаление / восстановление ─────────────── */

    public static function deleteProfile(int $id): void {
        Db::q("UPDATE profiles SET deleted_at=datetime('now') WHERE id=? AND deleted_at IS NULL", [$id]);
    }

    public static function restoreProfile(int $id): void {
        Db::q('UPDATE profiles SET deleted_at=NULL WHERE id=?', [$id]);
    }

    public static function deleteInterp(int $id): void {
        Db::q("UPDATE interpretations SET deleted_at=datetime('now') WHERE id=? AND deleted_at IS NULL", [$id]);
    }

    public static function restoreInterp(int $id): void {
        // Интерпретацию нельзя вернуть в удалённый анализ: сначала возвращают его.
        Db::q('UPDATE interpretations SET deleted_at=NULL WHERE id=? AND profile_id IN
               (SELECT id FROM profiles WHERE deleted_at IS NULL)', [$id]);
    }

    /* ─────────────── окончательное удаление ─────────────── */

    /** Анализ вместе со всеми его интерпретациями — назад дороги нет. */
    public static function purgeProfile(int $id): void {
        Db::q('DELETE FROM interpretations WHERE profile_id=?', [$id]);
        Db::q('DELETE FROM profiles WHERE id=?', [$id]);
    }

    public static function purgeInterp(int $id): void {
        Db::q('DELETE FROM interpretations WHERE id=?', [$id]);
    }

    /** Очистить корзину целиком (кнопка «Очистить корзину»). */
    public static function purgeAll(): void {
        Db::q('DELETE FROM interpretations WHERE deleted_at IS NOT NULL
               OR profile_id IN (SELECT id FROM profiles WHERE deleted_at IS NOT NULL)');
        Db::q('DELETE FROM profiles WHERE deleted_at IS NOT NULL');
    }

    /**
     * Автоматическая чистка: всё, что пролежало в корзине дольше KEEP_DAYS.
     * Сравнение строковое — SQLite хранит `datetime('now')` в формате
     * «ГГГГ-ММ-ДД ЧЧ:ММ:СС», который сортируется как время.
     */
    public static function purgeExpired(): void {
        $cut = self::cutoff();
        Db::q('DELETE FROM interpretations WHERE profile_id IN
               (SELECT id FROM profiles WHERE deleted_at IS NOT NULL AND deleted_at < ?)', [$cut]);
        Db::q('DELETE FROM profiles WHERE deleted_at IS NOT NULL AND deleted_at < ?', [$cut]);
        Db::q('DELETE FROM interpretations WHERE deleted_at IS NOT NULL AND deleted_at < ?', [$cut]);
    }

    /** Граница «просрочки» в формате SQLite. */
    public static function cutoff(): string {
        return gmdate('Y-m-d H:i:s', time() - self::KEEP_DAYS * 86400);
    }

    /* ─────────────── содержимое корзины ─────────────── */

    /** Удалённые анализы + сколько интерпретаций уедет/вернётся вместе с каждым. */
    public static function profiles(): array {
        return Db::all("SELECT p.*, (SELECT COUNT(*) FROM interpretations i
                                     WHERE i.profile_id=p.id AND i.deleted_at IS NULL) AS interp_count
                        FROM profiles p WHERE p.deleted_at IS NOT NULL
                        ORDER BY p.deleted_at DESC");
    }

    /**
     * Удалённые интерпретации живых анализов.
     *
     * @param int $profileId 0 — по всем анализам, иначе только по одному
     */
    public static function interps(int $profileId = 0): array {
        $sql = "SELECT i.*, p.name AS profile_name, p.methodic, v.version_no
                FROM interpretations i
                JOIN profiles p ON p.id=i.profile_id
                JOIN prompt_versions v ON v.id=i.prompt_version_id
                WHERE i.deleted_at IS NOT NULL AND p.deleted_at IS NULL";
        $args = [];
        if ($profileId > 0) { $sql .= ' AND i.profile_id=?'; $args[] = $profileId; }
        return Db::all($sql . ' ORDER BY i.deleted_at DESC', $args);
    }

    /**
     * Сколько всего лежит в корзине — для подписи серой ссылки «Удалённые».
     *
     * @return array{profiles:int,interps:int,total:int}
     */
    public static function counts(): array {
        $p = Db::one('SELECT COUNT(*) c FROM profiles WHERE deleted_at IS NOT NULL');
        $i = Db::one('SELECT COUNT(*) c FROM interpretations i
                      JOIN profiles p ON p.id=i.profile_id
                      WHERE i.deleted_at IS NOT NULL AND p.deleted_at IS NULL');
        $pc = (int) ($p['c'] ?? 0);
        $ic = (int) ($i['c'] ?? 0);
        return ['profiles' => $pc, 'interps' => $ic, 'total' => $pc + $ic];
    }

    /**
     * Сколько дней осталось до окончательного удаления (0 — исчезнет вот-вот).
     * Оператор должен видеть срок, а не гадать, «недавно» ли он это удалил.
     */
    public static function daysLeft(?string $deletedAt): int {
        $ts = strtotime((string) $deletedAt . ' UTC');
        if ($ts === false) return self::KEEP_DAYS;
        return max(0, (int) ceil(($ts + self::KEEP_DAYS * 86400 - time()) / 86400));
    }
}
