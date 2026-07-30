<?php
/**
 * Operator settings — LLM provider / model / OCR + SMTP.
 * (Self-contained extraction of resume-index/setup.php; payments/push removed.)
 *
 * Saved values land in the `settings` table and are overlaid by config.php on
 * the next request, so provider / models / API keys / mail can be changed
 * without editing code. Access is gated by ADMIN_PASSWORD (config / ENV).
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
header('X-Robots-Tag: noindex, nofollow', true);

require_once __DIR__ . '/lib/settings_store.php';
require_once __DIR__ . '/lib/mailer.php';
require_once __DIR__ . '/lib/llm.php';
require_once __DIR__ . '/lib/metrics.php';   // значения порогов матрицы по умолчанию

$cfg   = require __DIR__ . '/lib/config.php';
$store = new SettingsStore($cfg['DB_PATH']);

session_start();
header('Cache-Control: no-store');
$ADMIN_PW = (string) ($cfg['ADMIN_PASSWORD'] ?? '');
$login_error = null;

$setup_error = null;
// Первый запуск: пароль администратора не задан НИГДЕ (БД settings / ENV / .env).
$is_first_run = ($ADMIN_PW === '');

if (($_POST['action'] ?? '') === 'login') {
    $pw = (string) ($_POST['password'] ?? '');
    if ($ADMIN_PW !== '' && hash_equals($ADMIN_PW, $pw)) {
        $_SESSION['admin_authed'] = true;
        header('Location: setup.php');
        exit;
    }
    $login_error = 'Неверный пароль';
}

// Первичная установка пароля. Пока ADMIN_PASSWORD пуст, оператор задаёт его
// прямо здесь — значение уходит в таблицу settings (каталог data/ НАД
// веб-корнем, переживает деплой). Не нужно ни править перезаписываемый
// config.php, ни загружать .env на хостинг. Кто первым открыл — тот и задал,
// поэтому на проде выполните первый запуск сразу после деплоя.
if ($is_first_run && ($_POST['action'] ?? '') === 'setup_password') {
    $pw  = (string) ($_POST['password'] ?? '');
    $pw2 = (string) ($_POST['password2'] ?? '');
    if (mb_strlen($pw) < 6) {
        $setup_error = 'Пароль слишком короткий (минимум 6 символов).';
    } elseif ($pw !== $pw2) {
        $setup_error = 'Пароли не совпадают.';
    } else {
        $store->setSetting('ADMIN_PASSWORD', $pw);
        $_SESSION['admin_authed'] = true;
        header('Location: setup.php');
        exit;
    }
}

if (($_GET['logout'] ?? '') !== '') {
    $_SESSION = [];
    session_destroy();
    header('Location: setup.php');
    exit;
}

$h = static fn (string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

if (empty($_SESSION['admin_authed'])) {
    ?><!doctype html><html lang="ru"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>НейроПро — настройки (вход)</title>
    <style>
      /* Тот же светлый стиль, что и у приложения (/app/): тёмная тема настроек
         выглядела как чужой сервис. */
      body { font-family: Verdana, Geneva, sans-serif; background: #f4f6f8; color: #2a3138; font-size: 13px; margin: 0; padding: 24px; }
      .box { max-width: 360px; margin: 70px auto; background: #fff; border: 1px solid #e0e5ea; border-radius: 8px; padding: 24px 22px; }
      h1 { font-size: 18px; margin: 0 0 16px; color: #b3203b; }
      input { width: 100%; padding: 9px 11px; background: #fff; color: #2a3138; border: 1px solid #cfd6dd; border-radius: 5px; font: inherit; margin-bottom: 12px; box-sizing: border-box; }
      button { background: #b3203b; color: #fff; border: 0; padding: 10px 16px; border-radius: 5px; font: inherit; font-weight: bold; cursor: pointer; width: 100%; }
      button:hover { background: #8f1a30; }
      .err { background: #fdecef; border: 1px solid #e0a6b0; padding: 9px 10px; border-radius: 6px; margin: 0 0 12px; }
      .hint { color: #8a949d; line-height: 1.5; margin: 0 0 14px; }
      .hint code { color: #b3203b; }
    </style></head><body>
    <div class="box">
      <?php if ($is_first_run): ?>
        <h1>ПЕРВЫЙ ЗАПУСК</h1>
        <p class="hint">Пароль администратора ещё не задан. Задайте его сейчас — он сохранится в базе (каталог <code>data/</code> над веб-корнем) и переживёт деплой. Править <code>config.php</code> или загружать <code>.env</code> на хостинг не нужно.</p>
        <?php if ($setup_error): ?><p class="err"><?= $h($setup_error) ?></p><?php endif; ?>
        <form method="post" autocomplete="off">
          <input type="hidden" name="action" value="setup_password">
          <input type="password" name="password" autofocus required minlength="6" placeholder="Новый пароль администратора">
          <input type="password" name="password2" required minlength="6" placeholder="Повторите пароль">
          <button type="submit">Задать пароль и войти</button>
        </form>
      <?php else: ?>
        <h1>ВХОД В НАСТРОЙКИ</h1>
        <?php if ($login_error): ?><p class="err"><?= $h($login_error) ?></p><?php endif; ?>
        <form method="post" autocomplete="off">
          <input type="hidden" name="action" value="login">
          <input type="password" name="password" autofocus required placeholder="Пароль администратора">
          <button type="submit">Войти</button>
        </form>
      <?php endif; ?>
    </div></body></html><?php
    exit;
}

$messages = [];
$probe = null;   // ['provider' => …, 'rows' => [['label','full_id','ok','error'], …]]
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    if (isset($_POST['models_probe'])) {
        // Каталог AVAILABLE_MODELS широкий и общий для всех, а реально доступное
        // зависит от ключа и (у Яндекса) от подключённых в каталоге моделей.
        // Здесь проверяем факт доступности, не тратя токены на генерацию.
        // Ключи, только что введённые в форму, сохраняем — иначе проверка шла бы
        // по старым учётным данным. Пустые поля, как и при обычном сохранении,
        // ничего не перезаписывают.
        foreach (['OPENROUTER_API_KEY', 'YANDEX_API_KEY', 'YANDEX_FOLDER_ID'] as $k) {
            $v = trim((string) ($_POST[$k] ?? ''));
            if ($v !== '') $store->setSetting($k, $v);
        }
        $cfg_live = require __DIR__ . '/lib/config.php';
        // Проверка идёт по всему каталогу подряд, поэтому таймаут на модель
        // короткий — иначе одна «висящая» модель съедает время всей страницы.
        $cfg_live['LLM_TIMEOUT_SEC'] = 10;
        @set_time_limit(300);
        LLM::init($cfg_live);
        $prov = (string) $_POST['models_probe'] === 'openrouter' ? 'openrouter' : 'yandex';
        $rows = [];
        $catalog = $prov === 'openrouter' ? LLM::openRouterCatalog() : [];
        if ($prov === 'openrouter' && !$catalog) {
            $messages[] = ['ok' => false, 'text' => '⚠️ Не удалось получить список моделей OpenRouter (проверьте ключ и доступ к openrouter.ai).'];
        } else {
            foreach ((array) ($cfg_live['AVAILABLE_MODELS'] ?? []) as $mdl) {
                if (($mdl['provider'] ?? '') !== $prov || !empty($mdl['ocr_only'])) continue;
                // OpenRouter отдаёт весь каталог одним GET — сверяем слаги.
                // У Яндекса такого списка нет, поэтому пингуем каждую модель.
                $err = $prov === 'openrouter'
                    ? (in_array((string) $mdl['full_id'], $catalog, true) ? null : 'нет в каталоге OpenRouter')
                    : LLM::probeModel($mdl);
                $rows[] = ['label' => (string) $mdl['label'], 'id' => (string) $mdl['id'], 'full_id' => (string) $mdl['full_id'], 'error' => $err];
            }
            $ok = count(array_filter($rows, static fn ($r) => $r['error'] === null));
            $probe = ['provider' => $prov, 'rows' => $rows];
            $messages[] = ['ok' => $ok > 0, 'text' => 'Проверка ' . $prov . ': доступно ' . $ok . ' из ' . count($rows) . '.'];
        }
    } elseif (isset($_POST['smtp_test'])) {
        // Test letter via the CURRENT saved settings (re-read overlay).
        $cfg_live = require __DIR__ . '/lib/config.php';
        $test_to = trim((string) ($_POST['smtp_test_to'] ?? '')) ?: (string) ($cfg_live['ADMIN_EMAIL'] ?? '');
        try {
            Mailer::sendTest($cfg_live, $test_to);
            $messages[] = ['ok' => true, 'text' => '✅ Тестовое письмо отправлено на ' . $test_to
                . ' (host=' . ($cfg_live['SMTP_HOST'] ?? '') . ':' . ($cfg_live['SMTP_PORT'] ?? '') . ').'];
        } catch (Throwable $e) {
            $messages[] = ['ok' => false, 'text' => '⚠️ SMTP-тест не прошёл: ' . $e->getMessage()];
        }
    } elseif (($_POST['action'] ?? 'save') === 'save') {
        $edited = [];
        // String settings. Empty values never overwrite existing.
        $map = [
            'LLM_PROVIDER', 'LLM_PROVIDER_PRIORITY', 'LLM_DEFAULT_MODEL',
            'LLM_FALLBACK_MODELS',
            'LLM_VISION_MODEL', 'LLM_FALLBACK_MODEL', 'YANDEX_FALLBACK_MODEL',
            'LLM_OCR_MODELS', 'SCREENSHOT_MODEL', 'YANDEX_OCR_MODEL',
            'OPENROUTER_API_KEY', 'YANDEX_API_KEY', 'YANDEX_FOLDER_ID',
            'ADMIN_EMAIL', 'ERROR_EMAIL',
            'SMTP_HOST', 'SMTP_PORT', 'SMTP_USER', 'SMTP_PASS', 'SMTP_FROM', 'SMTP_FROM_NAME',
            'ADMIN_PASSWORD',
            'MATRIX_LOW_PCT', 'MATRIX_HIGH_PCT', 'MATRIX_MID_BAND_PCT', 'MATRIX_SIZE_POWER',
        ];
        foreach ($map as $k) {
            $v = trim((string) ($_POST[$k] ?? ''));
            if ($v !== '') { $store->setSetting($k, $v); $edited[] = $k; }
        }
        // Checkbox: Yandex Vision OCR. Always written.
        $store->setSetting('YANDEX_OCR_ENABLED', isset($_POST['YANDEX_OCR_ENABLED']) ? '1' : '0');
        $edited[] = 'YANDEX_OCR_ENABLED=' . (isset($_POST['YANDEX_OCR_ENABLED']) ? '1' : '0');
        $messages[] = ['ok' => true, 'text' => '✅ Сохранено: ' . implode(', ', $edited)];
    }
}

// Re-load config so freshly-saved overlay values render in the "current" hints.
$cfg = require __DIR__ . '/lib/config.php';
$current = $store->allSettings();
$mask = static fn (?string $v) => $v ? str_repeat('•', max(4, min(strlen((string) $v), 16))) : '';
$mask_key = static function (?string $v): string {
    $v = (string) $v;
    if ($v === '') return '';
    return strlen($v) > 10 ? substr($v, 0, 4) . '…' . substr($v, -4) : str_repeat('•', strlen($v));
};
// Effective value: saved overlay first, then config fallback.
$eff = static function (string $k) use ($cfg, $current): string {
    if (isset($current[$k]) && $current[$k] !== '') return (string) $current[$k];
    $v = $cfg[$k] ?? '';
    return is_array($v) ? implode(',', $v) : (string) $v;
};
$ocr_models_eff = $eff('LLM_OCR_MODELS');
?><!doctype html><html lang="ru"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>НейроПро — настройки</title>
<style>
  /* Оформление — как в приложении (www/app/index.php): шапка с навигацией,
     карточки, фирменный красный. Раньше настройки были тёмной страницей в
     другом шрифте и выглядели как отдельный сервис. */
  body { font-family: Verdana, Geneva, sans-serif; color: #2a3138; background: #f4f6f8; margin: 0; font-size: 13px; }
  header { background: #fff; border-bottom: 2px solid #b3203b; padding: 10px 20px; display: flex; gap: 18px; align-items: center; }
  header b { color: #b3203b; font-size: 16px; }
  header a { color: #2a3138; text-decoration: none; font-weight: bold; }
  header a:hover { color: #b3203b; }
  main { max-width: 1000px; margin: 18px auto; padding: 0 16px; }
  h1 { font-size: 20px; } h2 { font-size: 15px; margin: 14px 0 8px; }
  .card { background: #fff; border: 1px solid #e0e5ea; border-radius: 8px; padding: 16px; margin: 12px 0; }
  .lede, .muted { color: #8a949d; }
  label { display: block; margin: 10px 0; }
  label span { display: block; color: #6b7682; margin-bottom: 4px; }
  input, select { width: 100%; padding: 8px 10px; background: #fff; color: #2a3138; border: 1px solid #cfd6dd; border-radius: 5px; font: inherit; box-sizing: border-box; }
  button { background: #b3203b; color: #fff; border: 0; padding: 9px 14px; border-radius: 5px; font: inherit; font-weight: bold; cursor: pointer; }
  button:hover { background: #8f1a30; }
  button.ghost { background: #fff; color: #b3203b; border: 1px solid #b3203b; }
  .msg { padding: 10px 12px; margin: 8px 0; border-radius: 6px; }
  .msg.ok { background: #eaf7ee; border: 1px solid #a8d5b5; }
  .msg.bad { background: #fdecef; border: 1px solid #e0a6b0; }
  .row { display: flex; gap: 12px; flex-wrap: wrap; } .row > * { flex: 1; min-width: 180px; }
  a { color: #b3203b; }
  code { color: #b3203b; }
  .probe { border: 1px solid #e0e5ea; border-radius: 6px; margin: 10px 0 16px; max-height: 320px; overflow: auto; font-size: 12px; }
  .probe-row { display: flex; gap: 10px; justify-content: space-between; padding: 6px 10px; border-bottom: 1px solid #eef1f4; }
  .probe-row:last-child { border-bottom: 0; }
  .probe-row.bad { color: #8a949d; } .probe-row em { color: #b3203b; font-style: normal; text-align: right; }
</style></head><body>
<header><a href="/" style="color:#b3203b"><b>НейроПро</b></a>
  <a href="/app/?p=dashboard">Профили</a>
  <a href="/app/?p=upload">Загрузить</a>
  <a href="/app/?p=prompts">Промпты</a>
  <a href="/setup.php">Настройки</a>
  <a href="?logout=1" style="margin-left:auto;font-weight:normal">выйти</a>
</header>
<main>
<h1>Настройки</h1>
<p class="lede">Заполните только нужные поля — пустые значения не перезаписывают существующие. Значения сразу применяются ко всему сервису (overlay через таблицу <code>settings</code>).</p>

<?php foreach ($messages as $m): ?>
  <div class="msg <?= $m['ok'] ? 'ok' : 'bad' ?>"><?= $h($m['text']) ?></div>
<?php endforeach; ?>

<form method="post" autocomplete="off" class="card">
  <input type="hidden" name="action" value="save">

  <h2>Провайдер и модели</h2>
  <div class="row">
    <label><span>Провайдер по умолчанию</span>
      <select name="LLM_PROVIDER">
        <option value="yandex" <?= $eff('LLM_PROVIDER') === 'yandex' ? 'selected' : '' ?>>yandex (по умолчанию, fallback → openrouter)</option>
        <option value="openrouter" <?= $eff('LLM_PROVIDER') === 'openrouter' ? 'selected' : '' ?>>openrouter</option>
      </select>
    </label>
    <label><span>Приоритет провайдеров</span>
      <select name="LLM_PROVIDER_PRIORITY">
        <option value="yandex,openrouter" <?= $eff('LLM_PROVIDER_PRIORITY') === 'yandex,openrouter' ? 'selected' : '' ?>>yandex → openrouter</option>
        <option value="openrouter,yandex" <?= $eff('LLM_PROVIDER_PRIORITY') === 'openrouter,yandex' ? 'selected' : '' ?>>openrouter → yandex</option>
      </select>
    </label>
  </div>
  <label><span>Модель по умолчанию (из AVAILABLE_MODELS)</span>
    <?php
    $model_cur = $eff('LLM_DEFAULT_MODEL');
    $groups = LLM::modelsByGroup($cfg);
    // Цена справочная: ₽ за 1 млн токенов (вход/выход), 0 — неизвестна.
    $price = static function (array $m): string {
        $in = (float) ($m['price_in'] ?? 0); $out = (float) ($m['price_out'] ?? 0);
        return ($in > 0 || $out > 0) ? sprintf(' · ~%s/%s ₽ за 1M', rtrim(rtrim(number_format($in, 1, '.', ''), '0'), '.'), rtrim(rtrim(number_format($out, 1, '.', ''), '0'), '.')) : '';
    };
    ?>
    <select name="LLM_DEFAULT_MODEL">
      <?php foreach ($groups as $gname => $grows): ?>
        <optgroup label="<?= $h((string) $gname) ?>">
          <?php foreach ($grows as $mdl): ?>
            <option value="<?= $h($mdl['id']) ?>" <?= $model_cur === $mdl['id'] ? 'selected' : '' ?>>
              <?= $h($mdl['label']) ?> — <?= $h($mdl['full_id']) ?><?= $h($price($mdl)) ?>
            </option>
          <?php endforeach; ?>
        </optgroup>
      <?php endforeach; ?>
    </select>
  </label>
  <p class="lede" style="margin:-4px 0 8px">Каталог общий для всех установок. Что реально доступно вашему ключу — покажет проверка ниже; недоступная модель не ломает работу, запрос уходит следующему кандидату цепочки.</p>
  <div class="row">
    <button class="ghost" type="submit" name="models_probe" value="yandex" formnovalidate>Проверить каталог Yandex</button>
    <button class="ghost" type="submit" name="models_probe" value="openrouter" formnovalidate>Проверить каталог OpenRouter</button>
  </div>
  <?php if ($probe !== null): ?>
    <div class="probe">
      <?php foreach ($probe['rows'] as $r): ?>
        <div class="probe-row <?= $r['error'] === null ? 'good' : 'bad' ?>">
          <span><?= $r['error'] === null ? '✅' : '⛔' ?> <?= $h($r['label']) ?> <code><?= $h($r['id']) ?></code></span>
          <?php if ($r['error'] !== null): ?><em><?= $h(mb_substr($r['error'], 0, 160)) ?></em><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <label><span>Запасные модели (короткие id через запятую; пробуются после выбранной)</span><input type="text" name="LLM_FALLBACK_MODELS" placeholder="<?= $h($eff('LLM_FALLBACK_MODELS') ?: 'yandexgpt-lite,deepseek-v3') ?>"></label>
  <label><span>Vision-модель для PDF OCR (OpenRouter full_id)</span><input type="text" name="LLM_VISION_MODEL" placeholder="<?= $h($eff('LLM_VISION_MODEL') ?: 'google/gemini-2.0-flash-001') ?>"></label>
  <label><span>OpenRouter fallback-модель</span><input type="text" name="LLM_FALLBACK_MODEL" placeholder="<?= $h($eff('LLM_FALLBACK_MODEL') ?: 'openrouter/auto') ?>"></label>
  <label><span>Yandex fallback-модель (full_id без gpt://)</span><input type="text" name="YANDEX_FALLBACK_MODEL" placeholder="<?= $h($eff('YANDEX_FALLBACK_MODEL') ?: 'yandexgpt') ?>"></label>
  <label><span>OCR-модели OpenRouter (через запятую, по порядку)</span><input type="text" name="LLM_OCR_MODELS" placeholder="<?= $h($ocr_models_eff ?: 'google/gemini-2.5-flash,google/gemini-2.0-flash-001') ?>"></label>
  <label><span>Модель распознавания скриншота значимости (vision, OpenRouter full_id) — смотрит на графики, а не на OCR-текст</span><input type="text" name="SCREENSHOT_MODEL" placeholder="<?= $h($eff('SCREENSHOT_MODEL') ?: 'google/gemini-2.5-flash') ?>"></label>
  <label><span>Yandex Vision OCR модель</span><input type="text" name="YANDEX_OCR_MODEL" placeholder="<?= $h($eff('YANDEX_OCR_MODEL') ?: 'page') ?>"></label>
  <label style="display:flex;align-items:center;gap:8px;">
    <input type="checkbox" name="YANDEX_OCR_ENABLED" value="1" style="width:auto;" <?= $eff('YANDEX_OCR_ENABLED') === '1' ? 'checked' : '' ?>>
    <span style="margin:0;">Включить Yandex Vision OCR в цепочку PDF-распознавания</span>
  </label>

  <h2>API-ключи</h2>
  <label><span>OpenRouter API Key</span><input type="password" name="OPENROUTER_API_KEY" placeholder="<?= $h($mask_key($eff('OPENROUTER_API_KEY'))) ?>"></label>
  <label><span>Yandex API Key</span><input type="password" name="YANDEX_API_KEY" placeholder="<?= $h($mask_key($eff('YANDEX_API_KEY'))) ?>"></label>
  <label><span>Yandex Folder ID</span><input type="text" name="YANDEX_FOLDER_ID" placeholder="<?= $h($eff('YANDEX_FOLDER_ID')) ?>"></label>

  <h2>Почта (SMTP)</h2>
  <div class="row">
    <label><span>SMTP host</span><input type="text" name="SMTP_HOST" placeholder="<?= $h($eff('SMTP_HOST') ?: 'smtp.yandex.ru') ?>"></label>
    <label><span>SMTP port</span><input type="text" name="SMTP_PORT" placeholder="<?= $h($eff('SMTP_PORT') ?: '465') ?>"></label>
  </div>
  <label><span>SMTP user</span><input type="text" name="SMTP_USER" placeholder="<?= $h($eff('SMTP_USER')) ?>"></label>
  <label><span>SMTP password</span><input type="password" name="SMTP_PASS" placeholder="<?= $h($mask($eff('SMTP_PASS'))) ?>"></label>
  <div class="row">
    <label><span>SMTP from</span><input type="text" name="SMTP_FROM" placeholder="<?= $h($eff('SMTP_FROM')) ?>"></label>
    <label><span>From name</span><input type="text" name="SMTP_FROM_NAME" placeholder="<?= $h($eff('SMTP_FROM_NAME') ?: '4neuropro') ?>"></label>
  </div>
  <label><span>ADMIN_EMAIL (получатель копий/вложений)</span><input type="text" name="ADMIN_EMAIL" placeholder="<?= $h($eff('ADMIN_EMAIL')) ?>"></label>
  <label><span>ERROR_EMAIL (уведомления об ошибках)</span><input type="text" name="ERROR_EMAIL" placeholder="<?= $h($eff('ERROR_EMAIL')) ?>"></label>

  <h2>Матрица показателей</h2>
  <p class="lede">Пороги и контраст размеров кружков. Их же можно двигать мышью прямо на матрице
  (страница результата) — значение сохраняется сюда. Пороги решают не только цвет кружка, но и
  раздел отчёта, в который попадёт шкала: ниже нижнего порога — «низкий» уровень, выше верхнего —
  «высокий». Полоса медианы задаётся в процентах от предела шкалы физиологии, потому что «Знач.»
  у разных методик разного порядка.</p>
  <div class="row">
    <label><span>Нижний порог, % (по умолчанию <?= $h(Metrics::num(Metrics::LOW_PCT)) ?>)</span>
      <input type="number" min="1" max="98" step="0.5" name="MATRIX_LOW_PCT" value="<?= $h($eff('MATRIX_LOW_PCT')) ?>"></label>
    <label><span>Верхний порог, % (по умолчанию <?= $h(Metrics::num(Metrics::HIGH_PCT)) ?>)</span>
      <input type="number" min="2" max="99" step="0.5" name="MATRIX_HIGH_PCT" value="<?= $h($eff('MATRIX_HIGH_PCT')) ?>"></label>
  </div>
  <div class="row">
    <label><span>Полоса медианы, % от шкалы физиологии (по умолчанию <?= $h(Metrics::num(Metrics::MID_BAND_FRAC * 100)) ?>)</span>
      <input type="number" min="0" max="50" step="0.5" name="MATRIX_MID_BAND_PCT" value="<?= $h($eff('MATRIX_MID_BAND_PCT')) ?>"></label>
    <label><span>Мультипликатор размера кружка (1 — линейно, больше — контрастнее)</span>
      <input type="number" min="0.2" max="6" step="0.1" name="MATRIX_SIZE_POWER" value="<?= $h($eff('MATRIX_SIZE_POWER')) ?>"></label>
  </div>

  <h2>Доступ</h2>
  <p class="lede">Хранится в базе (каталог <code>data/</code> над веб-корнем) и переживает деплой — менять после каждого <code>pull.php</code> не нужно. Пустое поле оставляет текущий пароль.</p>
  <label><span>Пароль администратора (ADMIN_PASSWORD)</span><input type="password" name="ADMIN_PASSWORD" placeholder="<?= $h($mask($eff('ADMIN_PASSWORD'))) ?>"></label>

  <p><button type="submit">Сохранить</button></p>
</form>

<form method="post" autocomplete="off" class="card">
  <h2>Тест SMTP</h2>
  <label><span>Кому отправить тестовое письмо</span><input type="text" name="smtp_test_to" placeholder="<?= $h($eff('ADMIN_EMAIL')) ?>"></label>
  <p><button class="ghost" type="submit" name="smtp_test" value="1">Отправить тестовое письмо</button></p>
</form>
</main></body></html>
