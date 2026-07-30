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
    /** Ключ семейства промптов второго слоя (литературная правка). */
    public const STYLE_KEY = 'style';

    /**
     * Интерпретация в ДВА СЛОЯ.
     *
     * Слой 1 — содержание: модель получает готовый расчёт и раскладывает его по
     * разделам. Слой 2 — язык: отдельный промпт (семейство «style») переписывает
     * готовый текст короче и живее, не трогая факты. Разделение нужно потому,
     * что недорогие модели не тянут обе задачи сразу: когда от них требовали и
     * точность, и литературность, они добирали объём повторами.
     *
     * Второй слой не обязателен: если у семейства «style» нет активной версии,
     * клиент получает текст первого слоя. Сбой второго слоя тоже не теряет
     * работу — возвращается текст первого (о причине пишем в лог).
     *
     * Модель первого слоя обычно берётся из версии промпта, но оператор может
     * выбрать другую прямо на странице результата и переделать отчёт на ней (#3).
     * В этом случае $modelOverride/$providerOverride замещают модель версии —
     * второй слой (style) при этом работает на своей модели, как задан.
     *
     * @param array      $profile profile array
     * @param array|null $phys    структура Phys::decode (или null)
     * @param array      $version prompt_versions row to use
     * @param array|null $style   версия промпта второго слоя (null — не применять)
     * @param string|null $modelOverride короткий id модели поверх версии (или null)
     * @param string|null $providerOverride провайдер выбранной модели (или null)
     * @return array{text:string,draft:string,style_version_id:?int,style_error:?string}
     */
    public static function run(array $profile, ?array $phys, array $version, ?array $style = null,
                              ?string $modelOverride = null, ?string $providerOverride = null): array {
        $model = $modelOverride !== null && trim($modelOverride) !== ''
            ? trim($modelOverride) : (string) ($version['model_id'] ?? '');
        $provider = $modelOverride !== null && trim($modelOverride) !== ''
            ? (string) ($providerOverride ?? '') : (string) ($version['provider'] ?? '');
        if ($model !== '') LLM::setModelOverride($model);
        if ($provider !== '') LLM::setProviderOverride($provider);

        $system = (string) $version['body'];
        $user = self::buildUserMessage($profile, $phys);
        $draft = trim(LLM::chatText($system, $user, null, 0.6));

        $out = ['text' => $draft, 'draft' => $draft, 'style_version_id' => null, 'style_error' => null];
        if ($style === null || trim((string) ($style['body'] ?? '')) === '' || $draft === '') return $out;

        try {
            if (!empty($style['model_id'])) LLM::setModelOverride($style['model_id']);
            LLM::setProviderOverride(!empty($style['provider']) ? (string) $style['provider'] : null);
            // Температура ниже, чем у первого слоя: это правка, а не сочинение.
            $edited = trim(LLM::chatText((string) $style['body'], self::buildStyleMessage($profile, $phys, $draft), null, 0.4));
            // Пустой или подозрительно куцый ответ — не правка, а потеря текста:
            // редактор мог «сократить» отчёт до пары абзацев или вернуть отказ.
            // Лучше отдать исходный текст, чем обрезанный (Конституция, принцип II).
            if ($edited === '' || mb_strlen($edited) < mb_strlen($draft) * 0.4) {
                $out['style_error'] = 'второй слой вернул слишком короткий текст — оставлен исходный';
                return $out;
            }
            $out['text'] = $edited;
            $out['style_version_id'] = (int) $style['id'];
        } catch (Throwable $e) {
            $out['style_error'] = $e->getMessage();
        }
        return $out;
    }

    /**
     * Сообщение второму слою: сам текст плюс факты, которые обязаны дожить до
     * финального отчёта. Список фактов — это не материал для новых абзацев, а
     * ограничение: редактор без него легко «улучшает» спокойный показатель в
     * напряжённый, потому что так звучит выразительнее.
     */
    public static function buildStyleMessage(array $profile, ?array $phys, string $draft): string {
        $m = Metrics::build($profile, $phys);
        $listsSkipped = Metrics::listsSkipped((string) ($m['test_key'] ?? ''));
        $lines = [];
        $lines[] = 'ФАКТЫ, КОТОРЫЕ ОБЯЗАНЫ СОХРАНИТЬСЯ (проверь по ним итог; в текст их не переписывай):';
        foreach ($m['axes'] as $a) {
            if ($a['category'] === 'skip') continue;
            $lines[] = sprintf('- %s: раздел «%s»; %s.', $a['label'], $a['category_title'],
                $a['zna'] === null ? 'о телесной реакции не пишем — физиология не распознана' : $a['phys_label']);
        }
        $skipped = array_map(static fn ($i) => $m['axes'][$i]['label'], $m['groups']['skip'] ?? []);
        if ($skipped) {
            // У методик со списком непроявленных шкал (Басса-Дарки) этот список —
            // часть отчёта: редактор второго слоя не имеет права его вычистить,
            // но и разбирать перечисленное не должен.
            $lines[] = $listsSkipped
                ? '- ОСТАЮТСЯ ПРОСТЫМ СПИСКОМ, БЕЗ РАЗБОРА (раздел «'
                    . Metrics::categoryTitle('skip', (string) ($m['test_key'] ?? '')) . '»): '
                    . implode(', ', $skipped) . '.'
                : '- НЕ ДОЛЖНЫ ПОЯВИТЬСЯ В ТЕКСТЕ: ' . implode(', ', $skipped) . '.';
        }
        $lines[] = '';
        $lines[] = 'ОТЧЁТ ДЛЯ ПРАВКИ:';
        $lines[] = $draft;
        return implode("\n", $lines);
    }

    /** Активная версия промпта второго слоя (или null, если слой отключён). */
    public static function styleVersion(): ?array {
        return Prompts::activeVersion(self::STYLE_KEY);
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

        $testKey = (string) ($m['test_key'] ?? '');
        $listsSkipped = Metrics::listsSkipped($testKey);

        // Что измеряет каждая шкала — канонические формулировки методики
        // (Metrics::SCALE_MEANING). Без них модель пересказывала определения
        // своими словами и меняла смысл шкалы (Конституция, принцип II).
        $meanings = [];
        foreach ($m['axes'] as $a) {
            if (($a['meaning'] ?? '') !== '') $meanings[] = sprintf('- %s — %s.', $a['label'], $a['meaning']);
        }
        if ($meanings) {
            $lines[] = 'ЧТО ИЗМЕРЯЕТ КАЖДАЯ ШКАЛА (канонические описания методики; своих определений не придумывай, '
                     . 'пересказывай эти — своими словами, но не меняя смысла):';
            foreach ($meanings as $line) $lines[] = $line;
            $lines[] = '';
        }

        $lines[] = 'РАСЧЁТ ПО ШКАЛАМ (посчитан сервисом; НЕ пересчитывай, НЕ переклассифицируй):';
        foreach ($m['axes'] as $a) {
            $lines[] = sprintf('%d. %s — %s', $a['n'], $a['label'], $a['fact']);
            $extra = [];
            if ($a['category'] === 'skip') {
                $extra[] = $listsSkipped
                    ? 'РАЗДЕЛ: ' . $a['category_title'] . ' (только в общем списке, без разбора)'
                    : 'РАЗДЕЛ: не упоминать в отчёте';
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

        $lines[] = 'РАЗДЕЛЫ ОТЧЁТА И ИХ СОСТАВ (иных шкал в разделе быть не может, шкала — ровно в одном разделе; '
                 . 'порядок разделов ниже — порядок разделов в отчёте):';
        $anySection = false;
        foreach ($m['groups'] as $cat => $idxs) {
            // Раздел непроявленных шкал печатается списком только там, где этого
            // требует методика (Басса-Дарки); у остальных тестов такие шкалы из
            // отчёта по-прежнему выпадают целиком.
            if (!$idxs || ($cat === 'skip' && !$listsSkipped)) continue;
            $anySection = true;
            $names = array_map(static fn ($i) => $m['axes'][$i]['label'], $idxs);
            $lines[] = sprintf('- %s: %s', Metrics::categoryTitle($cat, $testKey), implode(', ', $names));
            $lines[] = '  (смысл раздела: ' . Metrics::categoryMeaning($cat, $testKey) . ')';
        }
        if (!$anySection) $lines[] = '- ни одна шкала не попала в разделы: напиши только общее спокойное резюме.';
        $skipped = array_map(static fn ($i) => $m['axes'][$i]['label'], $m['groups']['skip'] ?? []);
        if ($skipped && !$listsSkipped) $lines[] = '- НЕ УПОМИНАТЬ ВООБЩЕ: ' . implode(', ', $skipped);
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
        foreach (Metrics::smkMeanings((string) ($m['test_key'] ?? '')) as $letter => $meaning) {
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
                 . 'бери его из списка зон выше.'
                 . (Metrics::listsSkipped((string) ($m['test_key'] ?? ''))
                     ? ' Бледно-серые кружки — те шкалы, что идут в отчёте одним списком без разбора.'
                     : ' Шкалы из списка «НЕ УПОМИНАТЬ ВООБЩЕ» на матрице видны, но в тексте о них всё равно не пиши.');
        $lines[] = '';
        return implode("\n", $lines);
    }

    /**
     * Persist an interpretation against the exact version used.
     *
     * Вместе с текстом сохраняем СЛОЙ МАТЕМАТИКИ — снимок расчёта, по которому
     * писалась эта интерпретация (пороги оператора, уровни, разделы, итоги).
     * Пороги матрицы общие для сервиса и меняются мышью прямо на картинке;
     * без снимка отчёт, собранный назавтра, считался бы по новым порогам и
     * расходился бы с текстом, который читал клиент (#14).
     *
     * @param array|null $metrics результат Metrics::build() на момент интерпретации
     */
    public static function save(int $profileId, int $versionId, ?string $modelId, string $content,
                                ?array $metrics = null, ?int $styleVersionId = null): int {
        return Db::insert(
            'INSERT INTO interpretations (profile_id, prompt_version_id, model_id, content, metrics_json, style_version_id)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$profileId, $versionId, $modelId, $content,
             $metrics === null ? null : json_encode(self::mathLayer($metrics), JSON_UNESCAPED_UNICODE),
             $styleVersionId]
        );
    }

    /**
     * СЛОЙ МАТЕМАТИКИ — то, что уходит в модель и в отчёт после правки порогов
     * оператором. Отдельная функция, а не «весь Metrics::build() как есть»:
     * снимок должен переживать смену версий кода, поэтому в нём только
     * настройки, от которых зависит результат, и посчитанная по ним раскладка.
     *
     * @return array{conf:array,axes:array,totals:array}
     */
    public static function mathLayer(array $m): array {
        return [
            'conf' => [
                'low_pct' => $m['low_pct'] ?? null,
                'high_pct' => $m['high_pct'] ?? null,
                'mid_band_frac' => $m['mid_band_frac'] ?? null,
                'mid_band' => $m['mid_band'] ?? null,
                'size_contrast' => $m['size_contrast'] ?? null,
                'phys_scale' => $m['phys_scale'] ?? null,
            ],
            'axes' => array_map(static fn ($a) => [
                'label' => $a['label'], 'score' => $a['score'], 'pct' => $a['pct'],
                'zna' => $a['zna'], 'sig' => $a['sig'], 'smk' => $a['smk'],
                'level' => $a['level'], 'phys_state' => $a['phys_state'],
                'category' => $a['category'], 'zone' => $a['matrix_zone'],
            ], $m['axes'] ?? []),
            'totals' => array_map(static fn ($t) => $t['text'], $m['totals'] ?? []),
        ];
    }
}
