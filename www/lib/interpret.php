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
     * ЛЮБОЙ ТЕСТ — одинаково. Итоги методики (индексы Басса-Дарки, суммы
     * мотивации СМУ, напряжённость защит ИЖС) сначала считались по-разному и
     * доходили до модели только у Басса-Дарки: суммы СМУ печатал отчёт, а модель
     * их не видела и придумывала свои. Теперь и итоги, и зоны матрицы приходят
     * готовым списком для всех тестов — модели остаётся только текст.
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
            // Соотношение СМК даём ВСЕГДА, где оно распознано: это состав реакции
            // (какой канал ведущий), а не её сила, поэтому оно осмысленно и при
            // низкой достоверности, и ниже медианы (требование заказчика).
            // Чтобы модель не превращала «преобладает Y» в телесное напряжение
            // там, где его нет, рядом идёт практический смысл параметра, а сила
            // отклика по-прежнему берётся только из строки-факта.
            if ($a['smk_label'] !== '') {
                $meaning = (string) ($a['smk_meaning'] ?? '');
                $extra[] = 'СМК ' . $a['smk'] . ' (' . $a['smk_label']
                         . ($meaning !== '' ? '; ' . $meaning : '') . ')';
            }
            $extra[] = 'ЗОНА МАТРИЦЫ: ' . (Metrics::MATRIX_ZONES[$a['matrix_zone']] ?? '—');
            $lines[] = '   ' . implode('; ', $extra);
        }
        $lines[] = '';

        if (!$m['has_phys']) {
            $lines[] = 'ФИЗИОЛОГИЯ: не предоставлена или не распознана. Про телесные реакции не пиши вообще; '
                     . 'первой строкой отчёта отметь, что интерпретация построена только на ответах теста.';
            $lines[] = '';
        }

        if ($m['totals']) {
            $lines[] = 'ИТОГИ МЕТОДИКИ (посчитаны сервисом; НЕ пересчитывай и не выводи своих сумм):';
            foreach ($m['totals'] as $t) $lines[] = $t['text'] . '.';
            $lines[] = '';
        }

        $lines[] = self::matrixBlock($m);

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
                 . 'Ни таблиц, ни перечней чисел не выводи: над твоим текстом отчёт печатает диаграмму '
                 . 'и матрицу показателей, все числа клиент видит там.';
        return implode("\n", $lines);
    }

    /**
     * Описание матрицы, которую отчёт печатает под диаграммой. Модель не рисует
     * её и не считает координаты — она только должна понимать, что клиент видит,
     * чтобы текст не противоречил картинке и мог на неё опереться.
     */
    private static function matrixBlock(array $m): string {
        $unit = $m['unit'] ?? [];
        $lines = [];
        $lines[] = 'МАТРИЦА ПОКАЗАТЕЛЕЙ (её печатает отчёт под диаграммой, вместо таблиц):';
        $lines[] = '- ось X — когнитивный показатель, ответы теста (' . ($unit['title'] ?? '% от максимума шкалы') . ');'
                 . ' пунктир на ' . Metrics::num((float) ($m['low_pct'] ?? Metrics::LOW_PCT)) . ' % и '
                 . Metrics::num((float) ($m['high_pct'] ?? Metrics::HIGH_PCT))
                 . ' % отделяет низкие показатели от средних и высоких;';
        if (!empty($m['has_phys'])) {
            $lines[] = '- ось Y — эмоциональный показатель, «Знач.» на шкале ±' . Metrics::num((float) ($m['phys_scale'] ?? 0))
                     . '; медиана 0 — по центру, выше неё телесный отклик есть, ниже — его нет;';
        } else {
            $lines[] = '- вертикальной оси нет: физиология не распознана, показатели отложены только по когнитивной оси;';
        }
        $lines[] = '- цвет секторов кружка — какой параметр преобладает: Y (эмоциональный отклик) оранжевый, X (психическое '
                 . 'напряжение) синий, Z (мышечная реакция) зелёный; бледно-серые кружки — низкие показатели без отклика;';
        $lines[] = '- размер кружка — выраженность суммы когнитивного и эмоционального ответа;';
        $lines[] = '- жирная обводка кружка и жирная подпись — достоверное отклонение (p<0.05).';
        $lines[] = 'ЧТО ЗНАЧАТ ПАРАМЕТРЫ СМК (соотношение X/Y/Z есть у шкалы независимо от достоверности и от того, '
                 . 'выше или ниже медианы «Знач.»; оно говорит, КАКОЙ канал в реакции ведущий, но НЕ говорит, сильна ли реакция):';
        foreach (Metrics::SMK_MEANING as $letter => $meaning) {
            $lines[] = '  · ' . $letter . ' — ' . $meaning . '.';
        }
        $byZone = [];
        foreach ($m['axes'] as $a) $byZone[$a['matrix_zone']][] = $a['label'];
        $lines[] = 'ГДЕ НА МАТРИЦЕ ЛЕЖИТ КАЖДАЯ ШКАЛА:';
        foreach (Metrics::MATRIX_ZONES as $zone => $label) {
            if (empty($byZone[$zone])) continue;
            $lines[] = '  · ' . $label . ': ' . implode(', ', $byZone[$zone]) . '.';
        }
        $lines[] = 'Ссылаться на матрицу можно («на матрице под диаграммой …»), но выдумывать положение кружков нельзя: '
                 . 'бери его из списка зон выше. Шкалы из списка «НЕ УПОМИНАТЬ ВООБЩЕ» на матрице видны, но в тексте '
                 . 'о них всё равно не пиши.';
        $lines[] = '';
        return implode("\n", $lines);
    }

    /** Persist an interpretation against the exact version used. */
    public static function save(int $profileId, int $versionId, ?string $modelId, string $content): int {
        return Db::insert(
            'INSERT INTO interpretations (profile_id, prompt_version_id, model_id, content) VALUES (?, ?, ?, ?)',
            [$profileId, $versionId, $modelId, $content]
        );
    }
}
