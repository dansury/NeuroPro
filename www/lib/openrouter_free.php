<?php
/**
 * OpenRouterFree — каталог БЕСПЛАТНЫХ моделей OpenRouter по рейтингу shir-man.
 *
 * Зачем: у OpenRouter десятки моделей с суффиксом «:free», но какие из них
 * реально пригодны для длинного текста — по списку не видно. Сервис shir-man
 * (https://shir-man.com/api/free-llm/top-models) держит этот рейтинг за нас:
 * отдаёт верхушку бесплатных моделей, отсортированную сверху вниз.
 *
 * Как встроено:
 *   1. Оператор жмёт «Обновить бесплатные модели» в /setup.php →
 *      refresh() ходит по HTTP, нормализует ответ в СТРОКИ КАТАЛОГА
 *      (те же ключи, что у AVAILABLE_MODELS: id / label / provider / full_id /
 *      group / price_in / price_out) и кладёт JSON в settings.
 *   2. config.php при каждом запросе подмешивает этот JSON в AVAILABLE_MODELS —
 *      БЕЗ обращения к сети: нормализация уже сделана на записи.
 *   3. Дальше модель ничем не отличается от вшитых: тот же провайдер
 *      openrouter, тот же слаг в запросе, та же проверка каталога и те же
 *      цепочки fallback (LLM_DEFAULT_MODEL / LLM_FALLBACK_MODELS).
 *
 * Формат ответа сервиса нигде не зафиксирован, поэтому разбор намеренно
 * терпимый: годится и голый список слагов, и массив объектов, и объект с
 * ключом models/data/items/result. Единственное, что обязательно, — слаг вида
 * «vendor/model»; всё остальное (имя, место в рейтинге, контекст) украшает
 * подпись, но не требуется.
 */

declare(strict_types=1);

final class OpenRouterFree {
    /** Ключи в settings: нормализованный каталог и отметка последнего обновления. */
    public const KEY_MODELS = 'OPENROUTER_FREE_MODELS';
    public const KEY_SYNCED = 'OPENROUTER_FREE_SYNCED_AT';

    public const DEFAULT_URL = 'https://shir-man.com/api/free-llm/top-models';
    public const GROUP       = 'OpenRouter · бесплатные (shir-man)';
    /** Префикс короткого id, чтобы бесплатная модель никогда не перебила вшитую. */
    public const ID_PREFIX   = 'free-';
    /** Сколько строк рейтинга берём: список в выпадающем меню должен оставаться обозримым. */
    public const MAX_MODELS  = 40;

    /** URL рейтинга: настройка оператора (OPENROUTER_FREE_URL) или значение по умолчанию. */
    public static function url(array $cfg): string {
        $u = trim((string) ($cfg['OPENROUTER_FREE_URL'] ?? ''));
        return $u !== '' ? $u : self::DEFAULT_URL;
    }

    /**
     * Сходить за рейтингом и сохранить нормализованный каталог в settings.
     * Возвращает строки каталога. Бросает RuntimeException, если сеть/формат
     * подвели, — оператор увидит причину прямо в /setup.php.
     */
    public static function refresh(array $cfg, SettingsStore $store): array {
        $rows = self::normalize(self::httpGet(self::url($cfg), $cfg));
        if (!$rows) {
            throw new RuntimeException('в ответе рейтинга нет ни одного слага вида «vendor/model»');
        }
        $store->setSetting(self::KEY_MODELS, (string) json_encode($rows, JSON_UNESCAPED_UNICODE));
        $store->setSetting(self::KEY_SYNCED, gmdate('Y-m-d\TH:i:s\Z'));
        return $rows;
    }

    /** Сохранённый каталог (строки AVAILABLE_MODELS). Пусто — рейтинг ещё не забирали. */
    public static function stored(array $cfg): array {
        return self::decode((string) ($cfg[self::KEY_MODELS] ?? ''));
    }

    /** Разбор сохранённого JSON в строки каталога. Публично: тем же пользуется config.php. */
    public static function decode(string $json): array {
        if (trim($json) === '') return [];
        $rows = json_decode($json, true);
        if (!is_array($rows)) return [];
        $out = [];
        foreach ($rows as $r) {
            if (is_array($r) && !empty($r['id']) && !empty($r['full_id'])) $out[] = $r;
        }
        return $out;
    }

    /** Забыть сохранённый каталог (бесплатные модели исчезают из списков). */
    public static function forget(SettingsStore $store): void {
        $store->setSetting(self::KEY_MODELS, '');
        $store->setSetting(self::KEY_SYNCED, '');
    }

    /* ─────────────────────────── внутреннее ─────────────────────────── */

    /** GET с таймаутом из конфига. Возвращает разобранный JSON. */
    private static function httpGet(string $url, array $cfg): array {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('некорректный адрес рейтинга: ' . $url);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => max(10, (int) ($cfg['LLM_TIMEOUT_SEC'] ?? 30)),
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERAGENT      => 'NeuroPro/1.0 (+openrouter free models)',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($body === false)  throw new RuntimeException('сеть: ' . ($err ?: 'запрос не выполнен'));
        if ($code >= 400)     throw new RuntimeException('HTTP ' . $code . ': ' . mb_substr((string) $body, 0, 200));
        $j = json_decode((string) $body, true);
        if (!is_array($j))    throw new RuntimeException('ответ не похож на JSON: ' . mb_substr((string) $body, 0, 200));
        return $j;
    }

    /**
     * Ответ сервиса → строки каталога. Терпит любую обёртку вокруг списка и
     * любые имена полей: обязателен только слаг «vendor/model».
     */
    public static function normalize(array $json): array {
        $rows  = [];
        $seen  = [];
        $rank  = 0;
        foreach (self::entries($json) as $entry) {
            $slug = self::slug($entry);
            if ($slug === null || isset($seen[$slug])) continue;
            $seen[$slug] = true;
            $rank++;
            if ($rank > self::MAX_MODELS) break;
            $rows[] = [
                'id'        => self::shortId($slug),
                'label'     => self::label($entry, $slug, $rank),
                'provider'  => 'openrouter',
                'full_id'   => $slug,
                'group'     => self::GROUP,
                // Бесплатные — цена нулевая; поле остаётся, чтобы строка была
                // такой же формы, как вшитые в config.php.
                'price_in'  => 0.0,
                'price_out' => 0.0,
                'free'      => true,
                'rank'      => $rank,
            ];
        }
        return $rows;
    }

    /** Достать из ответа плоский список записей (строк или объектов). */
    private static function entries(array $json): array {
        // Голый список — самый простой случай.
        if (self::looksLikeList($json)) return array_values($json);
        // Обёртка: models / data / items / result / …; ищем первый список,
        // в котором вообще находятся слаги.
        foreach ($json as $v) {
            if (!is_array($v)) continue;
            $list = self::looksLikeList($v) ? array_values($v) : self::entries($v);
            foreach ($list as $e) {
                if (self::slug($e) !== null) return $list;
            }
        }
        return [];
    }

    /** Массив-список (а не ассоциативный объект)? */
    private static function looksLikeList(array $a): bool {
        return $a !== [] && array_keys($a) === range(0, count($a) - 1);
    }

    /** Слаг «vendor/model» из записи любого вида; null — записи без слага. */
    private static function slug($entry): ?string {
        if (is_string($entry)) return self::validSlug($entry);
        if (!is_array($entry)) return null;
        foreach (['id', 'model', 'model_id', 'slug', 'canonical_slug', 'permaslug', 'openrouter_id', 'name'] as $k) {
            if (!isset($entry[$k]) || !is_string($entry[$k])) continue;
            $slug = self::validSlug($entry[$k]);
            if ($slug !== null) return $slug;
        }
        return null;
    }

    /** Проверка формы слага: «vendor/model» (у бесплатных обычно с «:free»). */
    private static function validSlug(string $raw): ?string {
        $raw = trim($raw);
        return preg_match('~^[A-Za-z0-9][A-Za-z0-9._-]*/[A-Za-z0-9][A-Za-z0-9._:-]*$~', $raw) ? $raw : null;
    }

    /** Подпись в выпадающем списке: место в рейтинге + человеческое имя. */
    private static function label($entry, string $slug, int $rank): string {
        $name = '';
        if (is_array($entry)) {
            foreach (['name', 'label', 'title', 'display_name', 'model_name'] as $k) {
                $v = isset($entry[$k]) && is_string($entry[$k]) ? trim($entry[$k]) : '';
                // Имя, совпавшее со слагом, ничего не добавляет — берём слаг.
                if ($v !== '' && self::validSlug($v) === null) { $name = $v; break; }
            }
        }
        if ($name === '') $name = preg_replace('~:free$~', '', $slug);
        $name = mb_substr(preg_replace('~\s+~u', ' ', $name), 0, 60);
        return '#' . $rank . ' ' . $name . ' (бесплатно)';
    }

    /** Короткий id для UI и LLM_DEFAULT_MODEL: «free-vendor-model». */
    private static function shortId(string $slug): string {
        $id = strtolower(preg_replace('~[^A-Za-z0-9]+~', '-', preg_replace('~:free$~', '', $slug)));
        return self::ID_PREFIX . trim((string) $id, '-');
    }
}
