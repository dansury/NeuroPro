<?php
/**
 * Runs an interpretation: picks the prompt version, assembles the user message
 * from the cognitive profile + OCR'd physiological table, calls the configured
 * LLM (per-version model/provider), and persists the result.
 */

require_once __DIR__ . '/llm.php';
require_once __DIR__ . '/prompts.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/profile.php';
require_once __DIR__ . '/metrics.php';

final class Interpret {
    /**
     * @param array      $profile profile array
     * @param array|null $phys    структура Phys::decode (или null)
     * @param array      $version prompt_versions row to use
     * @return string interpretation text
     */
    public static function run(array $profile, ?array $phys, array $version): string {
        if (!empty($version['model_id'])) LLM::setModelOverride($version['model_id']);
        if (!empty($version['provider'])) LLM::setProviderOverride($version['provider']);

        $system = (string) $version['body'];
        $user = self::buildUserMessage($profile, $phys);
        return trim(LLM::chatText($system, $user, null, 0.6));
    }

    /**
     * Сообщение для нейросети — ГОТОВЫЙ расчёт, а не сырые данные.
     *
     * Раньше сюда уходил распознанный текст скриншота, и модель сама считала
     * доли от максимума, определяла положение относительно медианы и
     * раскладывала шкалы по разделам — с ошибками (уровни занижались, шкала с
     * отрицательным «Знач.» описывалась как телесное напряжение, одна шкала
     * попадала в два раздела). Теперь всё это считает Metrics, а модель получает
     * уровень, достоверность, категорию и опорный факт по каждой шкале.
     *
     * @param array|null $phys структура Phys::decode
     */
    public static function buildUserMessage(array $profile, ?array $phys = null): string {
        $m = Metrics::build($profile, $phys);
        $lines = [];
        $lines[] = 'ДАННЫЕ КЛИЕНТА:';
        $lines[] = 'ФИО: ' . $profile['name'];
        $lines[] = 'Возраст: ' . $profile['age'] . ', Пол: ' . $profile['sex'];
        $lines[] = 'Методика: ' . $profile['methodic'];
        $lines[] = 'Дата: ' . $profile['date'];
        $lines[] = '';

        $lines[] = 'РАСЧЁТ ПО ШКАЛАМ (посчитан сервисом; НЕ пересчитывай, НЕ переклассифицируй):';
        foreach ($m['axes'] as $a) {
            $lines[] = sprintf('%d. %s — %s', $a['n'], $a['label'], $a['fact']);
            $extra = [];
            if ($a['category'] === 'skip') {
                $extra[] = 'РАЗДЕЛ: не упоминать в отчёте';
            } else {
                $extra[] = 'РАЗДЕЛ: ' . $a['category_title'];
            }
            // Доминирующий параметр даём только там, где тело реально откликнулось:
            // «преобладает Y» у шкалы с Знач. ниже медианы толкает модель писать
            // про эмоциональный отклик там, где его достоверно нет.
            if ($a['smk_label'] !== '' && $a['phys_state'] === 'above') $extra[] = $a['smk_label'];
            $lines[] = '   ' . implode('; ', $extra);
        }
        $lines[] = '';

        if (!$m['has_phys']) {
            $lines[] = 'ФИЗИОЛОГИЯ: не предоставлена или не распознана. Про телесные реакции не пиши вообще; '
                     . 'первой строкой отчёта отметь, что интерпретация построена только на ответах теста.';
            $lines[] = '';
        }

        if ($m['indices']) {
            $lines[] = 'ИНДЕКСЫ (посчитаны сервисом; НЕ пересчитывай):';
            foreach ($m['indices'] as $name => $ix) {
                $lines[] = sprintf('%s = %s из %d (норма %s–%s) — %s.',
                    $name, self::num($ix['value']), $ix['cap'], self::num($ix['min']), self::num($ix['max']), $ix['verdict']);
            }
            $lines[] = '';
        }

        $lines[] = 'РАЗДЕЛЫ ОТЧЁТА И ИХ СОСТАВ (иных шкал в разделе быть не может, шкала — ровно в одном разделе):';
        $anySection = false;
        foreach ($m['groups'] as $cat => $idxs) {
            if (!$idxs || $cat === 'skip') continue;
            $anySection = true;
            $names = array_map(static fn ($i) => $m['axes'][$i]['label'], $idxs);
            $lines[] = sprintf('- %s: %s', Metrics::CATEGORIES[$cat]['title'], implode(', ', $names));
            $lines[] = '  (смысл раздела: ' . Metrics::CATEGORIES[$cat]['meaning'] . ')';
        }
        if (!$anySection) $lines[] = '- ни одна шкала не попала в разделы: напиши только общее спокойное резюме.';
        $skipped = array_map(static fn ($i) => $m['axes'][$i]['label'], $m['groups']['skip'] ?? []);
        if ($skipped) $lines[] = '- НЕ УПОМИНАТЬ ВООБЩЕ: ' . implode(', ', $skipped);
        $lines[] = '';

        $lines[] = 'Сформируй интерпретацию строго по инструкции из системного промпта. '
                 . 'Таблицу не выводи — её отчёт печатает сам.';
        return implode("\n", $lines);
    }

    /** Compact number: 9.0 → "9", 3.5 → "3.5". */
    private static function num(float $v): string {
        return rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
    }

    /** Persist an interpretation against the exact version used. */
    public static function save(int $profileId, int $versionId, ?string $modelId, string $content): int {
        return Db::insert(
            'INSERT INTO interpretations (profile_id, prompt_version_id, model_id, content) VALUES (?, ?, ?, ?)',
            [$profileId, $versionId, $modelId, $content]
        );
    }
}
