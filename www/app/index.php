<?php
/**
 * NeuroPro web service — front controller.
 *
 * Flow: upload Эгоскоп Excel → profile (await + paste screenshot) → OCR + chart
 * (cognitive ⊕ physiological, mathematical) → interpretation (auto-selected
 * prompt) → branded PDF / email. Plus prompt versioning admin (?p=prompts) and
 * provider/model/SMTP settings (setup.php).
 */

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/excel.php';
require_once __DIR__ . '/../lib/chart.php';

$h = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

try {
    // Бутстрап (config + БД + LLM + сидинг) держим ВНУТРИ try: при сбое — например
    // нет прав на запись в каталог данных — пользователь увидит понятную страницу
    // ошибки, а не «пустой» 500. Деталь уходит в лог сервера.
    $cfg = np_boot();
    $page = $_GET['p'] ?? 'dashboard';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    switch ($page) {
        case 'upload':        $method === 'POST' ? act_upload($cfg) : view_upload($cfg, $h); break;
        case 'profile':       view_profile($cfg, $h); break;
        case 'screenshot':    act_screenshot($cfg); break;
        case 'phys_save':     act_phys_save($cfg); break;
        case 'interpret':     act_interpret($cfg); break;
        case 'result':        view_result($cfg, $h); break;
        case 'report':        view_report($cfg); break;
        case 'email':         act_email($cfg); break;
        case 'prompts':       view_prompts($cfg, $h); break;
        case 'prompt_edit':   $method === 'POST' ? act_prompt_save($cfg) : view_prompt_edit($cfg, $h); break;
        case 'prompt_delete': act_prompt_delete($cfg); break;
        case 'interp_delete': act_interp_delete($cfg); break;
        default:              view_dashboard($cfg, $h);
    }
} catch (Throwable $e) {
    http_response_code(500);
    error_log('NeuroPro 500: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    layout('Ошибка', '<div class="msg bad">' . $h($e->getMessage()) . '</div>', $h);
}

/* ─────────────────────────── Views ─────────────────────────── */

function view_dashboard(array $cfg, callable $h): void {
    $awaiting = Db::all("SELECT * FROM profiles WHERE status='awaiting_screenshot' ORDER BY created_at DESC");
    $ready = Db::all("SELECT * FROM profiles WHERE status!='awaiting_screenshot' ORDER BY created_at DESC LIMIT 30");
    ob_start(); ?>
    <div class="row"><h1>Профили</h1><a class="btn" href="?p=upload">+ Загрузить Excel</a></div>
    <?php if ($awaiting): ?>
      <h2>Ожидают скриншот значимости</h2>
      <table class="grid"><tr><th>ФИО</th><th>Методика</th><th>Дата</th><th></th></tr>
      <?php foreach ($awaiting as $p): ?>
        <tr><td><?= $h($p['name']) ?></td><td><?= $h($p['methodic']) ?></td><td><?= $h($p['test_date']) ?></td>
        <td><a class="btn sm" href="?p=profile&id=<?= (int)$p['id'] ?>">Добавить скриншот →</a></td></tr>
      <?php endforeach; ?></table>
    <?php endif; ?>
    <h2>Готовые профили</h2>
    <table class="grid"><tr><th>ФИО</th><th>Методика</th><th>Статус</th><th>Интерпретаций</th><th></th></tr>
    <?php foreach ($ready as $p):
        $c = Db::one('SELECT COUNT(*) c FROM interpretations WHERE profile_id=?', [$p['id']]); ?>
      <tr><td><?= $h($p['name']) ?></td><td><?= $h($p['methodic']) ?></td><td><?= $h($p['status']) ?></td>
      <td><?= (int)($c['c'] ?? 0) ?></td>
      <td><a class="btn sm" href="?p=result&id=<?= (int)$p['id'] ?>">Открыть →</a></td></tr>
    <?php endforeach; ?></table>
    <?php
    layout('Дашборд', ob_get_clean(), $h);
}

function view_upload(array $cfg, callable $h): void {
    ob_start(); ?>
    <h1>Загрузка профиля</h1>
    <form method="post" enctype="multipart/form-data" class="card">
      <label><span>Excel-файл Эгоскопа (.xls / .xlsx)</span>
        <input type="file" name="file" accept=".xls,.xlsx,.csv"></label>
      <p class="muted">— или —</p>
      <label><span>Вставить данные как текст (CSV/TSV: № , параметр , балл)</span>
        <textarea name="pasted" rows="8" placeholder="1, Познавательный мотив, 9&#10;2, Состязательный мотив, 9 ..."></textarea></label>
      <label><span>Методика (если вставляете текстом)</span>
        <input type="text" name="methodic" placeholder="Структура мотивации участия (СМУ)"></label>
      <button class="btn" type="submit">Загрузить</button>
    </form>
    <?php
    layout('Загрузка', ob_get_clean(), $h);
}

function view_profile(array $cfg, callable $h): void {
    $p = profile_row((int)($_GET['id'] ?? 0));
    $prof = profile_decode($p);
    $svg = cognitive_svg($prof);
    ob_start(); ?>
    <h1><?= $h($prof['name']) ?> <span class="muted">— <?= $h($prof['methodic']) ?></span></h1>
    <div class="grid2">
      <div class="chart"><?= $svg ?></div>
      <div class="card">
        <h2>Скриншот «смысло-эмоциональной значимости»</h2>
        <p class="muted">Вставьте скриншот таблицы (Ctrl+V) или выберите файл. Распознаём через Yandex OCR.</p>
        <form method="post" action="?p=screenshot" enctype="multipart/form-data" id="ssform">
          <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
          <div id="paste" class="paste" tabindex="0">Кликните, чтобы вставить из буфера обмена — или нажмите Ctrl+V, или перетащите картинку сюда</div>
          <input type="hidden" name="pasted_image" id="pasted_image">
          <img id="preview" style="max-width:100%;display:none;margin:8px 0;border:1px solid #ccc">
          <label><span>…или файл</span><input type="file" id="ssfile" name="screenshot" accept="image/*"></label>
          <button class="btn" type="submit">Распознать и продолжить →</button>
        </form>
      </div>
    </div>
    <script>
    (function(){
      const z=document.getElementById('paste'),
            inp=document.getElementById('pasted_image'),
            pv=document.getElementById('preview'),
            fileInp=document.getElementById('ssfile');
      const HINT='Кликните, чтобы вставить из буфера обмена — или нажмите Ctrl+V, или перетащите картинку сюда';
      const show=(m)=>{ z.textContent=m; };

      // Единый путь: показать превью и положить data URL в скрытое поле.
      function useBlob(blob){
        if(!blob) return false;
        const r=new FileReader();
        r.onload=()=>{ inp.value=r.result; pv.src=r.result; pv.style.display='block'; show('Скриншот вставлен ✓'); };
        r.readAsDataURL(blob);
        if(fileInp) fileInp.value=''; // вставка перекрывает выбранный ранее файл
        return true;
      }

      // 1) Обычная вставка Ctrl+V. Свежий скриншот кладёт image/* прямо в событие.
      function handlePaste(e){
        const items=((e.clipboardData||window.clipboardData||{}).items)||[];
        for(const it of items){
          if(it.type && it.type.indexOf('image')===0){
            const f=it.getAsFile();
            if(f){ e.preventDefault(); useBlob(f); return; }
          }
        }
      }
      window.addEventListener('paste', handlePaste);
      z.addEventListener('paste', handlePaste);

      // 2) Клик по зоне: асинхронный Clipboard API читает СКОПИРОВАННУЮ картинку
      //    (из файла/браузера/просмотрщика), которую событие paste часто не отдаёт.
      z.addEventListener('click', async ()=>{
        z.focus();
        if(!(navigator.clipboard && navigator.clipboard.read)){
          show('Нажмите Ctrl+V, чтобы вставить скриншот'); return;
        }
        try{
          const items=await navigator.clipboard.read();
          for(const it of items){
            const type=(it.types||[]).find(t=>t.indexOf('image')===0);
            if(type){ useBlob(await it.getType(type)); return; }
          }
          show('В буфере обмена нет картинки — скопируйте изображение или нажмите Ctrl+V');
        }catch(err){
          show('Не удалось прочитать буфер автоматически — нажмите Ctrl+V или выберите файл');
        }
      });

      // 3) Перетаскивание картинки прямо в зону.
      ['dragover','dragenter'].forEach(ev=>z.addEventListener(ev, e=>{ e.preventDefault(); z.style.borderColor='#b3203b'; }));
      ['dragleave','drop'].forEach(ev=>z.addEventListener(ev, e=>{ e.preventDefault(); if(ev!=='drop') z.style.borderColor=''; }));
      z.addEventListener('drop', e=>{
        z.style.borderColor='';
        const f=((e.dataTransfer&&e.dataTransfer.files)||[])[0];
        if(f && f.type.indexOf('image')===0) useBlob(f); else show(HINT);
      });

      // 4) Выбор файла — показываем превью; на сервере файл имеет приоритет,
      //    поэтому data URL из вставки очищаем, чтобы не отправлять две копии.
      if(fileInp) fileInp.addEventListener('change', ()=>{
        const f=fileInp.files&&fileInp.files[0];
        if(!f) return;
        inp.value='';
        if(f.type.indexOf('image')===0){
          const r=new FileReader();
          r.onload=()=>{ pv.src=r.result; pv.style.display='block'; };
          r.readAsDataURL(f);
        }
      });
    })();
    </script>
    <?php
    layout('Профиль', ob_get_clean(), $h);
}

function view_result(array $cfg, callable $h): void {
    $p = profile_row((int)($_GET['id'] ?? 0));
    $prof = profile_decode($p);
    $phys = Phys::decode($p['phys_json'] ?? null, count($prof['scores']));
    $svg = cognitive_svg($prof, $phys);
    $interps = Db::all('SELECT i.*, v.version_no FROM interpretations i JOIN prompt_versions v ON v.id=i.prompt_version_id WHERE i.profile_id=? ORDER BY i.created_at DESC', [$p['id']]);
    $active = Prompts::activeVersion($prof['test_key']);
    ob_start(); ?>
    <h1><?= $h($prof['name']) ?> <span class="muted">— <?= $h($prof['methodic']) ?></span></h1>
    <?php if ($phys !== null && $phys['error'] !== null): ?>
      <div class="msg bad"><b>Ошибка распознавания скриншота:</b> <?= $h($phys['error']) ?><br>
      Значения физиологии можно ввести вручную в таблице ниже.</div>
    <?php elseif ($phys !== null && !Phys::hasData($phys)): ?>
      <div class="msg warn">Физиология не распознана: OCR не нашёл ни одного значения «Знач.» по осям.
      Проверьте скриншот или введите значения вручную в таблице ниже.</div>
    <?php endif; ?>
    <div class="chart card"><?= $svg ?></div>
    <?= phys_table_form($prof, $phys, (int)$p['id'], $h) ?>
    <form method="post" action="?p=interpret" class="row">
      <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
      <button class="btn" type="submit" <?= $active ? '' : 'disabled' ?>>▶ Сделать интерпретацию (промпт <?= $active ? 'v'.$h($active['version_no']).', '.$h($active['model_id']) : 'не выбран' ?>)</button>
      <a class="btn ghost" href="?p=profile&id=<?= (int)$p['id'] ?>">Заменить скриншот</a>
    </form>
    <?php foreach ($interps as $it): ?>
      <div class="card">
        <div class="row"><h2>Интерпретация (промпт v<?= $h($it['version_no']) ?>, <?= $h($it['model_id']) ?>) <span class="muted"><?= $h($it['created_at']) ?></span></h2>
          <span>
            <a class="btn sm" href="?p=report&interp=<?= (int)$it['id'] ?>&print=1" target="_blank">⤓ PDF</a>
            <a class="btn sm" href="?p=email&interp=<?= (int)$it['id'] ?>" onclick="return confirm('Отправить отчёт на почту клиента?')">✉ На почту</a>
            <a class="btn sm danger" href="?p=interp_delete&interp=<?= (int)$it['id'] ?>" onclick="return confirm('Удалить интерпретацию? Действие необратимо.')">🗑</a>
          </span>
        </div>
        <div class="interp"><?= Report::mdToHtml($it['content']) ?></div>
      </div>
    <?php endforeach; ?>
    <?php
    layout('Результат', ob_get_clean(), $h);
}

function view_report(array $cfg): void {
    $it = Db::one('SELECT * FROM interpretations WHERE id=?', [(int)($_GET['interp'] ?? 0)]);
    if (!$it) { http_response_code(404); echo 'not found'; return; }
    $p = profile_row((int)$it['profile_id']);
    $prof = profile_decode($p);
    $phys = Phys::decode($p['phys_json'] ?? null, count($prof['scores']));
    $html = Report::html($prof, Report::mdToHtml($it['content']), $cfg, [
        'phys' => $phys, 'autoprint' => !empty($_GET['print']),
    ]);
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
}

function view_prompts(array $cfg, callable $h): void {
    ob_start(); ?>
    <h1>Промпты</h1>
    <?php foreach (Prompts::allFamilies() as $f): ?>
      <div class="card">
        <h2><?= $h($f['name']) ?> <span class="muted">[<?= $h($f['test_key']) ?>]</span></h2>
        <table class="grid"><tr><th>Версия</th><th>Модель</th><th>Комментарий</th><th>Интерпретаций</th><th>Активна</th><th></th></tr>
        <?php foreach (Prompts::versions((int)$f['id']) as $v):
            $isActive = (int)$f['active_version_id'] === (int)$v['id']; ?>
          <tr>
            <td>v<?= $h($v['version_no']) ?></td>
            <td><?= $h($v['model_id']) ?> / <?= $h($v['provider']) ?></td>
            <td><?= $h($v['comment']) ?></td>
            <td><?= (int)$v['interp_count'] ?></td>
            <td><?= $isActive ? '✓' : '' ?></td>
            <td>
              <a class="btn sm" href="?p=prompt_edit&id=<?= (int)$f['id'] ?>&v=<?= (int)$v['id'] ?>">Открыть</a>
              <?php if ((int)$v['interp_count'] === 0 && !$isActive): ?>
                <a class="btn sm danger" href="?p=prompt_delete&v=<?= (int)$v['id'] ?>" onclick="return confirm('Удалить версию промпта?')">🗑</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?></table>
        <a class="btn sm" href="?p=prompt_edit&id=<?= (int)$f['id'] ?>">+ Новая версия</a>
      </div>
    <?php endforeach; ?>
    <?php
    layout('Промпты', ob_get_clean(), $h);
}

function view_prompt_edit(array $cfg, callable $h): void {
    $famId = (int)($_GET['id'] ?? 0);
    $fam = Prompts::familyById($famId);
    if (!$fam) { throw new RuntimeException('Семейство промптов не найдено'); }
    $v = isset($_GET['v']) ? Prompts::version((int)$_GET['v']) : null;
    $body = $v['body'] ?? '';
    $groups = LLM::modelsByGroup($cfg);
    ob_start(); ?>
    <h1><?= $h($fam['name']) ?></h1>
    <p class="muted">Сохранение создаёт новую версию (история сохраняется). <?= $v ? 'Открыта v'.$h($v['version_no']) : '' ?></p>
    <form method="post" action="?p=prompt_edit" class="card">
      <input type="hidden" name="id" value="<?= $famId ?>">
      <label><span>Нейросеть для интерпретации</span>
        <select name="model_id"><?php foreach ($groups as $gname => $grows): ?>
          <optgroup label="<?= $h((string)$gname) ?>"><?php foreach ($grows as $m): ?>
            <option value="<?= $h($m['id']) ?>" data-prov="<?= $h($m['provider']) ?>" <?= ($v['model_id'] ?? '')===$m['id']?'selected':'' ?>><?= $h($m['label']) ?> — <?= $h($m['full_id']) ?></option>
          <?php endforeach; ?></optgroup>
        <?php endforeach; ?></select></label>
      <input type="hidden" name="provider" id="prov" value="<?= $h($v['provider'] ?? 'yandex') ?>">
      <label><span>Комментарий к версии (для себя)</span>
        <input type="text" name="comment" value="<?= $h($v['comment'] ?? '') ?>"></label>
      <label><span>Текст промпта</span>
        <textarea name="body" rows="22" style="font-family:monospace"><?= $h($body) ?></textarea></label>
      <label class="chk"><input type="checkbox" name="set_active" value="1" checked> Сделать активной (использовать в авто-режиме)</label>
      <button class="btn" type="submit">Сохранить как новую версию</button>
    </form>
    <script>
      const sel=document.querySelector('select[name=model_id]'), prov=document.getElementById('prov');
      function sync(){ prov.value = sel.options[sel.selectedIndex].dataset.prov; }
      sel.addEventListener('change', sync); sync();
    </script>
    <?php
    layout('Редактор промпта', ob_get_clean(), $h);
}

/* ─────────────────────────── Actions ─────────────────────────── */

function act_upload(array $cfg): void {
    $prof = null;
    if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
        $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION)) ?: 'xls';
        $dest = rtrim($cfg['UPLOAD_DIR'], '/') . '/' . date('Ymd_His') . '_' . preg_replace('/[^\w.\-]+/', '_', $_FILES['file']['name']);
        move_uploaded_file($_FILES['file']['tmp_name'], $dest);
        $prof = Profile::fromFile($dest);
        $src = $dest;
    } elseif (trim((string)($_POST['pasted'] ?? '')) !== '') {
        $prof = profile_from_text((string)$_POST['pasted'], (string)($_POST['methodic'] ?? ''));
        $src = 'pasted';
    } else {
        throw new RuntimeException('Не передан файл и не вставлен текст');
    }
    $id = create_profile($prof, $src);
    redirect('?p=profile&id=' . $id);
}

function act_screenshot(array $cfg): void {
    $id = (int)($_POST['id'] ?? 0);
    $p = profile_row($id);
    $prof = profile_decode($p);
    $bytes = null; $mime = 'image/png';
    if (!empty($_FILES['screenshot']['tmp_name']) && is_uploaded_file($_FILES['screenshot']['tmp_name'])) {
        $bytes = file_get_contents($_FILES['screenshot']['tmp_name']);
        $mime = mime_content_type($_FILES['screenshot']['tmp_name']) ?: 'image/png';
    } elseif (!empty($_POST['pasted_image']) && preg_match('#^data:(image/\w+);base64,(.*)$#s', (string)$_POST['pasted_image'], $m)) {
        $mime = $m[1]; $bytes = base64_decode($m[2]);
    }
    if ($bytes === null) {
        redirect('?p=profile&id=' . $id . '&err=' . urlencode('Скриншот не передан: вставьте изображение (Ctrl+V) или выберите файл.'));
    }
    // Ошибку OCR не глушим: сохраняем в профиль и показываем оператору —
    // «все ошибки обязательно выводить». Профиль при этом остаётся рабочим.
    $ocrText = '';
    $ocrError = null;
    try { $ocrText = LLM::ocrImage($bytes, $mime); }
    catch (Throwable $e) { $ocrError = $e->getMessage(); }
    $labels = array_map(fn ($s) => $s['label'], $prof['scores']);
    $parsed = Phys::parse($ocrText, $labels);
    if ($ocrError !== null) {
        $parsed['error'] = 'OCR недоступен: ' . $ocrError;
    } elseif (!Phys::hasData($parsed)) {
        $parsed['error'] = 'OCR отработал, но ни одно значение «Знач.» не сопоставлено с осями. Распознанный текст сохранён (см. лог), значения можно ввести вручную.';
    }
    Db::q('UPDATE profiles SET phys_ocr_text=?, phys_json=?, status=? WHERE id=?',
        [$ocrText, json_encode($parsed, JSON_UNESCAPED_UNICODE), 'ready', $id]);
    redirect('?p=result&id=' . $id . ($parsed['error'] !== null ? '&err=' . urlencode($parsed['error']) : ''));
}

/** Ручная правка физиологии: значения «Знач.» и флажки p<0.05 по осям. */
function act_phys_save(array $cfg): void {
    $id = (int)($_POST['id'] ?? 0);
    $p = profile_row($id);
    $prof = profile_decode($p);
    $count = count($prof['scores']);
    $old = Phys::decode($p['phys_json'] ?? null, $count);
    $parsed = [
        'aligned' => Phys::fromManual((array)($_POST['zna'] ?? []), $count),
        'p'       => $old['p'] ?? array_fill(0, $count, null),
        'sig'     => [],
        'rows'    => $old['rows'] ?? [],
        'error'   => null, // оператор поправил вручную — прежняя ошибка OCR снята
    ];
    $sigPost = (array)($_POST['sig'] ?? []);
    for ($i = 0; $i < $count; $i++) {
        $parsed['sig'][$i] = !empty($sigPost[$i]);
        // Ручной флажок перекрывает распознанное p: снят — значит недостоверно.
        if ($old !== null && $parsed['sig'][$i] !== ($old['sig'][$i] ?? false)) $parsed['p'][$i] = null;
    }
    Db::q('UPDATE profiles SET phys_json=?, status=? WHERE id=?',
        [json_encode($parsed, JSON_UNESCAPED_UNICODE), 'ready', $id]);
    redirect('?p=result&id=' . $id . '&ok=' . urlencode('Физиология сохранена.'));
}

function act_interpret(array $cfg): void {
    $id = (int)($_POST['id'] ?? 0);
    $p = profile_row($id);
    $prof = profile_decode($p);
    $version = Prompts::activeVersion($prof['test_key']);
    if (!$version) {
        redirect('?p=result&id=' . $id . '&err=' . urlencode('Для этой методики не выбран активный промпт (страница «Промпты»).'));
    }
    // Ошибку нейросети показываем на странице результата, а не голой 500-й.
    try {
        $content = Interpret::run($prof, (string)$p['phys_ocr_text'], $version);
    } catch (Throwable $e) {
        redirect('?p=result&id=' . $id . '&err=' . urlencode('Нейросеть недоступна, интерпретация не создана: ' . $e->getMessage()));
    }
    Interpret::save($id, (int)$version['id'], $version['model_id'], $content);
    redirect('?p=result&id=' . $id . '&ok=' . urlencode('Интерпретация готова.'));
}

function act_email(array $cfg): void {
    require_once __DIR__ . '/../lib/mailer.php';
    $it = Db::one('SELECT * FROM interpretations WHERE id=?', [(int)($_GET['interp'] ?? 0)]);
    if (!$it) throw new RuntimeException('Интерпретация не найдена');
    $pid = (int)$it['profile_id'];
    $p = profile_row($pid);
    $prof = profile_decode($p);
    $phys = Phys::decode($p['phys_json'] ?? null, count($prof['scores']));
    $html = Report::html($prof, Report::mdToHtml($it['content']), $cfg, ['phys' => $phys]);
    $to = $cfg['ADMIN_EMAIL'] ?? '';
    if ($to === '') {
        redirect('?p=result&id=' . $pid . '&err=' . urlencode('Не задан адрес получателя (ADMIN_EMAIL в настройках /setup.php).'));
    }
    try {
        Mailer::sendCustom($cfg, $to, 'Результаты исследования НейроПро', $html, strip_tags($it['content']));
    } catch (Throwable $e) {
        redirect('?p=result&id=' . $pid . '&err=' . urlencode('Письмо не отправлено (SMTP): ' . $e->getMessage()));
    }
    redirect('?p=result&id=' . $pid . '&ok=' . urlencode('Письмо отправлено на ' . $to . '.'));
}

function act_prompt_save(array $cfg): void {
    $famId = (int)($_POST['id'] ?? 0);
    if (!Prompts::familyById($famId)) throw new RuntimeException('Семейство не найдено');
    $vid = Prompts::addVersion($famId, (string)$_POST['body'], (string)$_POST['model_id'], (string)$_POST['provider'], (string)($_POST['comment'] ?? ''));
    if (!empty($_POST['set_active'])) Prompts::setActive($famId, $vid);
    redirect('?p=prompts');
}

function act_prompt_delete(array $cfg): void {
    $res = Prompts::deleteVersion((int)($_GET['v'] ?? 0));
    redirect('?p=prompts' . ($res['ok'] ? '' : '&err=' . urlencode($res['error'])));
}

function act_interp_delete(array $cfg): void {
    $it = Db::one('SELECT profile_id FROM interpretations WHERE id=?', [(int)($_GET['interp'] ?? 0)]);
    Db::q('DELETE FROM interpretations WHERE id=?', [(int)($_GET['interp'] ?? 0)]);
    redirect('?p=result&id=' . (int)($it['profile_id'] ?? 0));
}

/* ─────────────────────────── Helpers ─────────────────────────── */

function profile_row(int $id): array {
    $p = Db::one('SELECT * FROM profiles WHERE id=?', [$id]);
    if (!$p) throw new RuntimeException('Профиль не найден');
    return $p;
}

function profile_decode(array $p): array {
    $scores = json_decode($p['scores_json'], true) ?: [];
    return [
        'name' => $p['name'], 'age' => $p['age'], 'sex' => $p['sex'], 'date' => $p['test_date'],
        'methodic' => $p['methodic'], 'test_key' => $p['test_key'],
        'scores' => $scores,
        'score_max' => Profile::scoreMax($scores, (string) $p['test_key']),
    ];
}

function create_profile(array $prof, string $src): int {
    return Db::insert(
        'INSERT INTO profiles (name, age, sex, test_date, methodic, test_key, scores_json, source_file, status)
         VALUES (?,?,?,?,?,?,?,?, ?)',
        [$prof['name'], $prof['age'], $prof['sex'], $prof['date'], $prof['methodic'], $prof['test_key'],
         json_encode($prof['scores'], JSON_UNESCAPED_UNICODE), $src, 'awaiting_screenshot']
    );
}

/** Build a profile from pasted CSV/TSV text. */
function profile_from_text(string $text, string $methodic): array {
    $scores = [];
    foreach (preg_split('/\r?\n/', trim($text)) as $line) {
        if (trim($line) === '') continue;
        $parts = preg_split('/[\t;,]+/', trim($line));
        $parts = array_map('trim', $parts);
        // pattern: [n,] label, score
        $score = (float) str_replace(',', '.', array_pop($parts));
        $label = '';
        $n = 0;
        foreach ($parts as $pp) {
            if ($label === '' && is_numeric($pp)) { $n = (int)$pp; }
            else { $label = trim($label . ' ' . $pp); }
        }
        if ($label !== '') $scores[] = ['n' => $n ?: count($scores) + 1, 'label' => $label, 'score' => $score];
    }
    $key = '';
    $mu = mb_strtoupper($methodic);
    foreach (Profile::TEST_TYPES as $needle => $info) if (mb_strpos($mu, mb_strtoupper($needle)) !== false) { $key = $info['key']; break; }
    return ['name' => 'Без имени', 'age' => '', 'sex' => '', 'date' => date('d m Y H:i'),
            'methodic' => $methodic ?: 'Не указана', 'test_key' => $key, 'scores' => $scores,
            'score_max' => Profile::scoreMax($scores, $key)];
}

/** @param array|null $phys структура Phys::decode (aligned + sig) или null */
function cognitive_svg(array $prof, ?array $phys = null): string {
    $labels = array_map(fn ($s) => Profile::shortLabel($s['label']), $prof['scores']);
    $cog = array_map(fn ($s) => (float) $s['score'], $prof['scores']);
    return Chart::svg($labels, $cog, (int)($prof['score_max'] ?? 10), $phys['aligned'] ?? null, [
        'title' => Profile::chartTitle((string)($prof['test_key'] ?? '')),
        'size' => 560,
        'phys_sig' => $phys['sig'] ?? [],
    ]);
}

/**
 * Таблица распознанной физиологии («Знач.» + достоверность) с ручной правкой.
 * Строки с p<0.05 — жирные; нераспознанные значения — прочерк (пустое поле).
 */
function phys_table_form(array $prof, ?array $phys, int $id, callable $h): string {
    if ($phys === null) return '';
    ob_start(); ?>
    <div class="card">
      <h2>Физиология — «Знач.» со скриншота значимости</h2>
      <p class="muted">Проверьте распознанные значения и достоверность; при необходимости поправьте и сохраните.
      Пустое поле = данных нет (ось останется без точки).</p>
      <form method="post" action="?p=phys_save">
        <input type="hidden" name="id" value="<?= $id ?>">
        <table class="grid">
          <tr><th>Ось</th><th>Знач.</th><th>Достоверность</th><th>p&lt;0.05</th></tr>
          <?php foreach ($prof['scores'] as $i => $s):
              $v = $phys['aligned'][$i] ?? null;
              $pv = $phys['p'][$i] ?? null;
              $sig = !empty($phys['sig'][$i]); ?>
          <tr<?= $sig ? ' class="sig"' : '' ?>>
            <td><?= $h($s['label']) ?></td>
            <td><input class="num" type="text" name="zna[<?= $i ?>]" value="<?= $v === null ? '' : $h(rtrim(rtrim(number_format((float)$v, 1, '.', ''), '0'), '.')) ?>" placeholder="—"></td>
            <td><?= $pv === null ? '<span class="muted">—</span>' : $h(($pv < Phys::SIG ? 'p<' : 'p>') . '0.05') ?></td>
            <td><input type="checkbox" name="sig[<?= $i ?>]" value="1" <?= $sig ? 'checked' : '' ?>></td>
          </tr>
          <?php endforeach; ?>
        </table>
        <button class="btn sm" type="submit">Сохранить физиологию</button>
      </form>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Предупреждения о ненастроенных внешних сервисах — выводятся на каждой
 * странице приложения: оператор сразу видит, что OCR/нейросеть/почта
 * недоступны, а не получает молчаливо пустой результат.
 */
function service_warnings(array $cfg): array {
    $w = [];
    $hasYA = !empty($cfg['YANDEX_API_KEY']) && !empty($cfg['YANDEX_FOLDER_ID']);
    $hasOR = !empty($cfg['OPENROUTER_API_KEY']);
    if (!$hasYA) {
        $w[] = 'Yandex OCR не настроен (YANDEX_API_KEY / YANDEX_FOLDER_ID) — распознавание скриншотов значимости недоступно. Задайте ключи в настройках или .env.';
    }
    if (!$hasYA && !$hasOR) {
        $w[] = 'Ни один LLM-провайдер не настроен (OpenRouter / Yandex) — интерпретация нейросетью недоступна.';
    }
    if (empty($cfg['SMTP_USER']) || empty($cfg['SMTP_PASS'])) {
        $w[] = 'SMTP не настроен (SMTP_USER / SMTP_PASS) — отправка отчётов на почту недоступна.';
    }
    return $w;
}

function redirect(string $to): void { header('Location: ' . $to); exit; }

function layout(string $title, string $body, callable $h): void {
    $err = $_GET['err'] ?? '';
    $ok = $_GET['ok'] ?? '';
    // Баннеры о ненастроенных сервисах. try: layout зовётся и со страницы
    // ошибки бутстрапа, где конфиг может быть недоступен.
    $warnings = [];
    try { $warnings = service_warnings(np_boot()); } catch (Throwable $e) { /* без баннеров */ }
    header('Content-Type: text/html; charset=utf-8'); ?>
<!doctype html><html lang="ru"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>НейроПро — <?= $h($title) ?></title>
<style>
  body{font-family:Verdana,Geneva,sans-serif;color:#2a3138;background:#f4f6f8;margin:0;font-size:13px}
  header{background:#fff;border-bottom:2px solid #b3203b;padding:10px 20px;display:flex;gap:18px;align-items:center}
  header b{color:#b3203b;font-size:16px} header a{color:#2a3138;text-decoration:none;font-weight:bold}
  header a:hover{color:#b3203b}
  main{max-width:1000px;margin:18px auto;padding:0 16px}
  h1{font-size:20px} h2{font-size:15px;margin:14px 0 8px}
  .muted{color:#8a949d;font-weight:normal}
  .btn{display:inline-block;background:#b3203b;color:#fff;border:0;padding:9px 14px;border-radius:5px;cursor:pointer;text-decoration:none;font:inherit;font-weight:bold}
  .btn:hover{background:#8f1a30} .btn.sm{padding:5px 9px;font-size:12px} .btn.ghost{background:#fff;color:#b3203b;border:1px solid #b3203b}
  .btn.danger{background:#fff;color:#b3203b;border:1px solid #e0a6b0} .btn[disabled]{opacity:.5;pointer-events:none}
  .card{background:#fff;border:1px solid #e0e5ea;border-radius:8px;padding:16px;margin:12px 0}
  .grid{width:100%;border-collapse:collapse} .grid th,.grid td{border:1px solid #e0e5ea;padding:7px 10px;text-align:left}
  .grid th{background:#f4f6f8}
  .grid2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
  .row{display:flex;gap:12px;align-items:center;justify-content:space-between}
  label{display:block;margin:10px 0} label span{display:block;color:#6b7682;margin-bottom:4px}
  input[type=text],textarea,select{width:100%;padding:8px 10px;border:1px solid #cfd6dd;border-radius:5px;font:inherit}
  .chk{display:flex;gap:8px;align-items:center} .chk input{width:auto}
  .chart{text-align:center} .chart svg{max-width:100%;height:auto}
  .paste{border:2px dashed #cfd6dd;border-radius:6px;padding:24px;text-align:center;color:#8a949d;cursor:text}
  .interp h1,.interp h2,.interp h3{color:#b3203b}
  .msg.bad{background:#fdecef;border:1px solid #e0a6b0;padding:10px;border-radius:6px;margin:10px 0}
  .msg.warn{background:#fff8e6;border:1px solid #e8d29a;padding:10px;border-radius:6px;margin:10px 0}
  .msg.ok{background:#eaf7ee;border:1px solid #a8d5b5;padding:10px;border-radius:6px;margin:10px 0}
  tr.sig td{font-weight:bold}
  input.num{width:80px;text-align:center}
  @media(max-width:760px){.grid2{grid-template-columns:1fr}}
</style></head><body>
<header><a href="/" style="color:#b3203b"><b>НейроПро</b></a>
  <a href="?p=dashboard">Профили</a>
  <a href="?p=upload">Загрузить</a>
  <a href="?p=prompts">Промпты</a>
  <a href="/setup.php">Настройки</a>
</header>
<main>
  <?php foreach ($warnings as $w): ?><div class="msg warn">⚠ <?= $h($w) ?> <a href="/setup.php">Настройки →</a></div><?php endforeach; ?>
  <?php if ($err): ?><div class="msg bad"><?= $h($err) ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="msg ok"><?= $h($ok) ?></div><?php endif; ?>
  <?= $body ?>
</main></body></html>
<?php
}
