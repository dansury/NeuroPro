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

final class Interpret {
    /**
     * @param array  $profile      profile array
     * @param string $physOcrText  recognized physiological table text (may be '')
     * @param array  $version      prompt_versions row to use
     * @return string interpretation text
     */
    public static function run(array $profile, string $physOcrText, array $version): string {
        if (!empty($version['model_id'])) LLM::setModelOverride($version['model_id']);
        if (!empty($version['provider'])) LLM::setProviderOverride($version['provider']);

        $system = (string) $version['body'];
        $user = self::buildUserMessage($profile, $physOcrText);
        return trim(LLM::chatText($system, $user, null, 0.6));
    }

    public static function buildUserMessage(array $profile, string $physOcrText): string {
        $lines = [];
        $lines[] = 'ДАННЫЕ КЛИЕНТА:';
        $lines[] = 'ФИО: ' . $profile['name'];
        $lines[] = 'Возраст: ' . $profile['age'] . ', Пол: ' . $profile['sex'];
        $lines[] = 'Методика: ' . $profile['methodic'];
        $lines[] = 'Дата: ' . $profile['date'];
        $lines[] = '';
        $testKey = (string) ($profile['test_key'] ?? '');
        $lines[] = $testKey === 'bd'
            ? 'КОГНИТИВНЫЕ БАЛЛЫ (из Excel; у каждой шкалы свой максимум — число её вопросов):'
            : 'КОГНИТИВНЫЕ БАЛЛЫ (из Excel, шкала 0–' . ($profile['score_max'] ?? 10) . '):';
        foreach ($profile['scores'] as $s) {
            $line = sprintf('%d. %s — %s', $s['n'], $s['label'], self::num((float) $s['score']));
            $scaleMax = Profile::scaleMax($testKey, (string) $s['label']);
            if ($scaleMax !== null) $line .= ' из ' . $scaleMax;
            $lines[] = $line;
        }
        // Индексы Басса-Дарки считаем сами (математика), чтобы нейросеть их не выводила.
        foreach ($testKey === 'bd' ? Profile::bdIndices($profile['scores']) : [] as $name => $ix) {
            $lines[] = sprintf('%s = %s из %d (норма %s–%s) — %s.',
                $name, self::num($ix['value']), $ix['cap'], self::num($ix['min']), self::num($ix['max']), $ix['verdict']);
        }
        $lines[] = '';
        if (trim($physOcrText) !== '') {
            $lines[] = 'ТАБЛИЦА СМЫСЛО-ЭМОЦИОНАЛЬНОЙ ЗНАЧИМОСТИ (распознано OCR со скриншота — X/Y/Z, Зна, p):';
            $lines[] = $physOcrText;
        } else {
            $lines[] = 'ТАБЛИЦА ФИЗИОЛОГИИ: не предоставлена (OCR пуст). Проанализируй только когнитивную часть и отметь это.';
        }
        $lines[] = '';
        $lines[] = 'Сформируй интерпретацию строго по инструкции из системного промпта.';
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
