<?php
/**
 * ModelCatalog — ЖИВОЙ каталог моделей: список тянется прямо у провайдера и
 * кэшируется в settings.
 *
 * Зачем: AVAILABLE_MODELS в config.php — вшитый список, он стареет вместе с
 * релизом. Провайдеры же отдают свой каталог по HTTP: OpenRouter — GET
 * /api/v1/models, Яндекс — GET /v1/models в OpenAI-совместимом режиме. Здесь
 * это забирается, нормализуется в СТРОКИ КАТАЛОГА (те же ключи, что у
 * AVAILABLE_MODELS) и кладётся JSON в settings.
 *
 * Как встроено:
 *   1. /setup.php при загрузке зовёт maybeRefresh(): кэш старше TTL (по
 *      умолчанию 15 минут) — сходить по сети, иначе ничего не делать. Ошибка
 *      сети не роняет страницу: остаётся прежний кэш, причина показывается
 *      оператору. Кнопка «Обновить каталог моделей» ходит принудительно.
 *   2. config.php при КАЖДОМ запросе подмешивает кэш в AVAILABLE_MODELS —
 *      без сети: нормализация уже сделана на записи.
 *   3. Вшитая строка остаётся источником id, группы и цены в ₽; живая строка
 *      либо помечает её как реально доступную (`live`), либо добавляется как
 *      новая модель. Так сохранённый LLM_DEFAULT_MODEL не ломается.
 *
 * Ещё здесь живёт разбор ВЕРСИИ модели из слага (lineage/newerSiblings):
 * на нём построен запасной вариант «более новая версия той же модели»
 * (LLM_FALLBACK_MODE=auto, см. LLM::candidateChain).
 */

declare(strict_types=1);

final class ModelCatalog {
    /** Ключи в settings: нормализованный кэш, отметка обновления, последняя ошибка. */
    public const KEY_MODELS = 'MODEL_CATALOG_MODELS';
    public const KEY_SYNCED = 'MODEL_CATALOG_SYNCED_AT';
    public const KEY_ERROR  = 'MODEL_CATALOG_ERROR';

    /** Сколько минут кэш считается свежим (настройка MODEL_CATALOG_TTL_MIN). */
    public const TTL_MIN = 15;
    /** Потолок размера кэша: у OpenRouter каталог растёт, список должен остаться обозримым. */
    public const MAX_MODELS = 500;

    /* ─────────────────────────── чтение кэша ─────────────────────────── */

    /** Разбор сохранённого JSON в строки каталога. Тем же пользуется config.php. */
    public static function decode(string $json): array {
        if (trim($json) === '') return [];
        $rows = json_decode($json, true);
        if (!is_array($rows)) return [];
        $out = [];
        foreach ($rows as $r) {
            if (is_array($r) && !empty($r['id']) && !empty($r['full_id']) && !empty($r['provider'])) $out[] = $r;
        }
        return $out;
    }

    /**
     * Вшитый каталог + живой. Живая строка с известным слагом только помечает
     * вшитую как доступную (`live`), новая — дописывается в конец. Ни id, ни
     * группа, ни цена вшитой строки не меняются: на них завязаны настройки.
     */
    public static function merge(array $builtin, array $live): array {
        $bySlug = [];
        foreach ($builtin as $i => $r) {
            $bySlug[self::slugKey((string) ($r['provider'] ?? ''), (string) ($r['full_id'] ?? ''))] = $i;
        }
        $ids = [];
        foreach ($builtin as $r) $ids[(string) ($r['id'] ?? '')] = true;
        foreach ($live as $r) {
            $key = self::slugKey((string) $r['provider'], (string) $r['full_id']);
            if (isset($bySlug[$key])) {
                $builtin[$bySlug[$key]]['live'] = true;
                continue;
            }
            if (isset($ids[(string) $r['id']])) continue;   // столкновение коротких id
            $ids[(string) $r['id']] = true;
            $bySlug[$key] = count($builtin);
            $builtin[] = $r;
        }
        return $builtin;
    }

    /* ─────────────────────────── обновление ─────────────────────────── */

    /**
     * Обновить кэш, если он пуст или старше TTL. Возвращает отчёт refresh()
     * либо null, если ходить было незачем. Сетевые ошибки НЕ бросаются:
     * причина уходит в settings (KEY_ERROR) и показывается в /setup.php.
     */
    public static function maybeRefresh(array $cfg, SettingsStore $store, bool $force = false): ?array {
        if (!$force && !self::isStale($cfg)) return null;
        try {
            return self::refresh($cfg, $store);
        } catch (Throwable $e) {
            $store->setSetting(self::KEY_ERROR, $e->getMessage());
            // Чтобы неудача не превращалась в запрос на каждой перезагрузке,
            // отметку времени всё равно двигаем — следующая попытка через TTL.
            $store->setSetting(self::KEY_SYNCED, gmdate('Y-m-d\TH:i:s\Z'));
            return null;
        }
    }

    /** Кэш пуст или устарел? */
    public static function isStale(array $cfg): bool {
        if (!self::decode((string) ($cfg[self::KEY_MODELS] ?? ''))) return true;
        $at = strtotime((string) ($cfg[self::KEY_SYNCED] ?? '')) ?: 0;
        return (time() - $at) >= self::ttlSec($cfg);
    }

    public static function ttlSec(array $cfg): int {
        $min = (int) ($cfg['MODEL_CATALOG_TTL_MIN'] ?? self::TTL_MIN);
        return max(60, $min * 60);
    }

    /**
     * Сходить к провайдерам и сохранить нормализованный каталог.
     * Возвращает ['rows'=>N, 'openrouter'=>N|строка ошибки, 'yandex'=>…].
     * Бросает RuntimeException, только если НИ ОДИН провайдер не ответил, —
     * тогда прежний кэш остаётся нетронутым.
     */
    public static function refresh(array $cfg, SettingsStore $store): array {
        $rows = [];
        $report = ['openrouter' => null, 'yandex' => null];
        foreach (['openrouter' => 'fetchOpenRouter', 'yandex' => 'fetchYandex'] as $prov => $fn) {
            try {
                $got = self::$fn($cfg);
                $report[$prov] = count($got);
                $rows = array_merge($rows, $got);
            } catch (Throwable $e) {
                $report[$prov] = $e->getMessage();
            }
        }
        if (!$rows) {
            throw new RuntimeException(
                'OpenRouter: ' . self::asText($report['openrouter']) . '; Yandex: ' . self::asText($report['yandex'])
            );
        }
        if (count($rows) > self::MAX_MODELS) $rows = array_slice($rows, 0, self::MAX_MODELS);
        $store->setSetting(self::KEY_MODELS, (string) json_encode($rows, JSON_UNESCAPED_UNICODE));
        $store->setSetting(self::KEY_SYNCED, gmdate('Y-m-d\TH:i:s\Z'));
        // Частичная неудача (один провайдер молчит) — тоже причина для заметки.
        $partial = array_filter($report, static fn ($v) => is_string($v));
        $store->setSetting(self::KEY_ERROR, $partial
            ? implode('; ', array_map(static fn ($k, $v) => $k . ': ' . $v, array_keys($partial), $partial))
            : '');
        $report['rows'] = count($rows);
        return $report;
    }

    /** Забыть живой каталог: в списках остаются только вшитые модели. */
    public static function forget(SettingsStore $store): void {
        $store->setSetting(self::KEY_MODELS, '');
        $store->setSetting(self::KEY_SYNCED, '');
        $store->setSetting(self::KEY_ERROR, '');
    }

    /* ───────────────────────── версии моделей ───────────────────────── */

    /**
     * Слаг → линейка и версия: «openai/gpt-4.1-mini» → ['gpt-*-mini', [4,1]].
     * Версия — токен вида 4.1 / v3 / 2411; вендор в линейку не входит, чтобы
     * одна модель у разных провайдеров сходилась. Версии в слаге нет — null.
     */
    public static function lineage(string $slug): ?array {
        $s = strtolower(trim($slug));
        $s = (string) preg_replace('~:.*$~', '', $s);          // ':free', ':nitro' — не версия
        $name = str_contains($s, '/') ? substr($s, strrpos($s, '/') + 1) : $s;
        $tokens = preg_split('~[-_]~', $name) ?: [];
        $idx = null;
        foreach ($tokens as $i => $t) {                        // 1) первый токен с точкой: 4.1, 2.5
            if (preg_match('~^v?\d+\.\d+(\.\d+)*$~', $t)) { $idx = $i; break; }
        }
        if ($idx === null) {
            foreach ($tokens as $i => $t) {                    // 2) первый «v3»
                if (preg_match('~^v\d+$~', $t)) { $idx = $i; break; }
            }
        }
        if ($idx === null) {
            foreach ($tokens as $i => $t) {                    // 3) последнее голое число (в т.ч. дата 2411)
                if (preg_match('~^\d+$~', $t)) $idx = $i;
            }
        }
        if ($idx === null) return null;
        $ver = array_map('intval', explode('.', ltrim($tokens[$idx], 'v')));
        $tokens[$idx] = '*';
        return ['key' => implode('-', $tokens), 'version' => $ver];
    }

    /** Сравнение версий: 5.1 > 5 > 4.1. Отсутствующая часть считается за -1. */
    public static function versionCmp(array $a, array $b): int {
        $n = max(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $x = $a[$i] ?? -1; $y = $b[$i] ?? -1;
            if ($x !== $y) return $x <=> $y;
        }
        return 0;
    }

    /**
     * Более новые версии ТОЙ ЖЕ модели в каталоге — от самой новой к менее
     * новой. Это и есть запасной вариант по умолчанию: линейка совпадает
     * (gpt-*-mini), версия строго больше. Совпадения нет (нерегулярный слаг,
     * новее ничего нет) — пустой массив, цепочка идёт дальше своим порядком.
     */
    public static function newerSiblings(array $row, array $models): array {
        $base = self::lineage((string) ($row['full_id'] ?? ''));
        if ($base === null) return [];
        $out = [];
        foreach ($models as $m) {
            if (!empty($m['ocr_only'])) continue;
            $slug = (string) ($m['full_id'] ?? '');
            if ($slug === (string) ($row['full_id'] ?? '')) continue;
            $l = self::lineage($slug);
            if ($l === null || $l['key'] !== $base['key']) continue;
            if (self::versionCmp($l['version'], $base['version']) <= 0) continue;
            $out[] = ['row' => $m, 'version' => $l['version']];
        }
        usort($out, static fn ($a, $b) => self::versionCmp($b['version'], $a['version']));
        return array_map(static fn ($e) => $e['row'], $out);
    }

    /* ─────────────────────────── провайдеры ─────────────────────────── */

    /** OpenRouter: GET /api/v1/models. Ключ не обязателен, но отправляем, если есть. */
    private static function fetchOpenRouter(array $cfg): array {
        $url = (string) preg_replace('~/chat/completions$~', '/models', (string) ($cfg['OPENROUTER_URL'] ?? ''));
        if ($url === '') throw new RuntimeException('не задан OPENROUTER_URL');
        $headers = ['Accept: application/json'];
        if (!empty($cfg['OPENROUTER_API_KEY'])) $headers[] = 'Authorization: Bearer ' . $cfg['OPENROUTER_API_KEY'];
        $j = self::httpGetJson($url, $headers, $cfg);
        $rows = [];
        foreach ($j['data'] ?? [] as $m) {
            $slug = isset($m['id']) && is_string($m['id']) ? trim($m['id']) : '';
            if ($slug === '' || !str_contains($slug, '/')) continue;
            $pin  = self::usdPerMillion($m['pricing']['prompt'] ?? null);
            $pout = self::usdPerMillion($m['pricing']['completion'] ?? null);
            $rows[] = [
                'id'            => 'or-' . self::shortId($slug),
                'label'         => self::niceLabel($m['name'] ?? '', $slug),
                'provider'      => 'openrouter',
                'full_id'       => $slug,
                'group'         => 'OpenRouter · ' . self::vendorTitle($slug) . ' (каталог)',
                // Цена приходит в долларах за токен: ₽-оценки, как у вшитых
                // строк, здесь нет — не выдумываем курс.
                'price_in'      => 0.0,
                'price_out'     => 0.0,
                'price_usd_in'  => $pin,
                'price_usd_out' => $pout,
                'context'       => (int) ($m['context_length'] ?? 0),
                'live'          => true,
            ];
            if ($pin === 0.0 && $pout === 0.0) $rows[count($rows) - 1]['free'] = true;
        }
        if (!$rows) throw new RuntimeException('в ответе нет моделей');
        usort($rows, static fn ($a, $b) => [$a['group'], $a['full_id']] <=> [$b['group'], $b['full_id']]);
        return $rows;
    }

    /**
     * Яндекс: GET /v1/models в OpenAI-совместимом режиме. Каталог зависит от
     * ключа и подключённых в облаке моделей. Ответ бывает как OpenAI-формы
     * ({data:[{id}]}), так и {models:[{modelUri|uri|name}]} — берём обе.
     */
    private static function fetchYandex(array $cfg): array {
        $key    = (string) ($cfg['YANDEX_API_KEY'] ?? '');
        $folder = (string) ($cfg['YANDEX_FOLDER_ID'] ?? '');
        if ($key === '' || $folder === '') throw new RuntimeException('нет ключа или folder id');
        $url = (string) preg_replace('~/chat/completions$~', '/models', (string) ($cfg['YANDEX_LLM_URL'] ?? ''));
        if ($url === '') throw new RuntimeException('не задан YANDEX_LLM_URL');
        $j = self::httpGetJson($url, ['Accept: application/json', 'Authorization: Api-Key ' . $key], $cfg);
        $list = [];
        foreach (['data', 'models', 'items'] as $k) {
            if (isset($j[$k]) && is_array($j[$k])) { $list = $j[$k]; break; }
        }
        $rows = [];
        foreach ($list as $m) {
            $raw = '';
            if (is_string($m)) $raw = $m;
            elseif (is_array($m)) {
                foreach (['id', 'modelUri', 'uri', 'name'] as $k) {
                    if (isset($m[$k]) && is_string($m[$k])) { $raw = $m[$k]; break; }
                }
            }
            $slug = self::yandexSlug($raw, $folder);
            if ($slug === null) continue;
            $rows[] = [
                'id'       => 'ya-' . self::shortId($slug),
                'label'    => $slug,
                'provider' => 'yandex',
                'full_id'  => $slug,
                'group'    => 'Yandex AI Studio (каталог)',
                'price_in' => 0.0, 'price_out' => 0.0,
                'live'     => true,
            ];
        }
        if (!$rows) throw new RuntimeException('в ответе нет моделей');
        return $rows;
    }

    /** «gpt://<folder>/yandexgpt/latest» → «yandexgpt». Чужая папка — не наша модель. */
    private static function yandexSlug(string $raw, string $folder): ?string {
        $raw = trim($raw);
        if ($raw === '') return null;
        if (str_starts_with($raw, 'gpt://') || str_starts_with($raw, 'emb://')) {
            $parts = explode('/', substr($raw, 6));
            if (count($parts) < 2) return null;
            if ($parts[0] !== $folder && $parts[0] !== '') return null;
            $slug = $parts[1];
        } else {
            $slug = $raw;
        }
        $slug = (string) preg_replace('~/(latest|rc|deprecated)$~', '', $slug);
        return preg_match('~^[A-Za-z0-9][A-Za-z0-9._-]*$~', $slug) ? $slug : null;
    }

    /* ──────────────────────────── мелочи ──────────────────────────── */

    private static function httpGetJson(string $url, array $headers, array $cfg): array {
        if (!filter_var($url, FILTER_VALIDATE_URL)) throw new RuntimeException('некорректный адрес: ' . $url);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => max(10, min(30, (int) ($cfg['LLM_TIMEOUT_SEC'] ?? 30))),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => 'NeuroPro/1.0 (+model catalog)',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($body === false) throw new RuntimeException('сеть: ' . ($err ?: 'запрос не выполнен'));
        if ($code >= 400)    throw new RuntimeException('HTTP ' . $code . ': ' . mb_substr((string) $body, 0, 160));
        $j = json_decode((string) $body, true);
        if (!is_array($j))   throw new RuntimeException('ответ не похож на JSON');
        return $j;
    }

    /** Цена OpenRouter приходит в долларах за ТОКЕН; показываем за 1M. */
    private static function usdPerMillion($raw): float {
        if (!is_string($raw) && !is_float($raw) && !is_int($raw)) return 0.0;
        $v = (float) $raw;
        return $v > 0 ? round($v * 1000000, 4) : 0.0;
    }

    private static function slugKey(string $provider, string $slug): string {
        return $provider . '|' . $slug;
    }

    /** Короткий id для UI и настроек: «openai-gpt-5-1». */
    private static function shortId(string $slug): string {
        $id = strtolower((string) preg_replace('~[^A-Za-z0-9]+~', '-', $slug));
        return trim($id, '-');
    }

    private static function niceLabel($name, string $slug): string {
        $name = is_string($name) ? trim($name) : '';
        if ($name === '') $name = $slug;
        return mb_substr((string) preg_replace('~\s+~u', ' ', $name), 0, 70);
    }

    /** «openai/gpt-5» → «OpenAI»: заголовок группы в выпадающем списке. */
    private static function vendorTitle(string $slug): string {
        $vendor = strtolower(substr($slug, 0, (int) strpos($slug, '/')));
        $known = [
            'openai' => 'OpenAI', 'anthropic' => 'Anthropic', 'google' => 'Google',
            'meta-llama' => 'Meta', 'deepseek' => 'DeepSeek', 'qwen' => 'Qwen',
            'mistralai' => 'Mistral', 'x-ai' => 'xAI', 'z-ai' => 'Z.ai',
            'moonshotai' => 'Moonshot', 'nvidia' => 'NVIDIA', 'microsoft' => 'Microsoft',
            'cohere' => 'Cohere', 'amazon' => 'Amazon', 'perplexity' => 'Perplexity',
            'openrouter' => 'авто',
        ];
        return $known[$vendor] ?? ($vendor !== '' ? ucfirst($vendor) : 'прочие');
    }

    private static function asText($v): string {
        return is_string($v) ? $v : (is_int($v) ? $v . ' моделей' : 'нет данных');
    }
}
