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
require_once __DIR__ . '/../lib/matrix.php';

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
        case 'prompt_family_delete': act_prompt_family_delete($cfg); break;
        case 'interp_block':  act_interp_block($cfg); break;
        case 'interp_delete': act_interp_delete($cfg); break;
        case 'profile_delete': act_profile_delete($cfg); break;
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
        <td><a class="btn sm" href="?p=profile&id=<?= (int)$p['id'] ?>">Добавить скриншот →</a>
        <a class="btn sm danger" href="?p=profile_delete&id=<?= (int)$p['id'] ?>" onclick="return confirm('Удалить анализ? Действие необратимо.')">🗑</a></td></tr>
      <?php endforeach; ?></table>
    <?php endif; ?>
    <h2>Готовые профили</h2>
    <table class="grid"><tr><th>ФИО</th><th>Методика</th><th>Статус</th><th>Интерпретаций</th><th></th></tr>
    <?php foreach ($ready as $p):
        $c = Db::one('SELECT COUNT(*) c FROM interpretations WHERE profile_id=?', [$p['id']]); ?>
      <tr><td><?= $h($p['name']) ?></td><td><?= $h($p['methodic']) ?></td><td><?= $h($p['status']) ?></td>
      <td><?= (int)($c['c'] ?? 0) ?></td>
      <td><a class="btn sm" href="?p=result&id=<?= (int)$p['id'] ?>">Открыть →</a>
      <a class="btn sm danger" href="?p=profile_delete&id=<?= (int)$p['id'] ?>" onclick="return confirm('Удалить анализ и все его интерпретации? Действие необратимо.')">🗑</a></td></tr>
    <?php endforeach; ?></table>
    <?php
    layout('Дашборд', ob_get_clean(), $h);
}

function view_upload(array $cfg, callable $h): void {
    // Список методик — из известных типов тестов + уже встречавшихся в базе (#4).
    $methodics = [];
    foreach (Profile::TEST_TYPES as $info) $methodics[$info['label']] = true;
    foreach (Db::all("SELECT DISTINCT methodic FROM profiles WHERE methodic IS NOT NULL AND methodic<>'' ORDER BY methodic") as $r) {
        $methodics[(string)$r['methodic']] = true;
    }
    $methodics = array_keys($methodics);
    ob_start(); ?>
    <h1>Загрузка профиля</h1>
    <form method="post" enctype="multipart/form-data" class="card">
      <label><span>Excel-файл Эгоскопа (.xls / .xlsx)</span>
        <input type="file" name="file" accept=".xls,.xlsx,.csv"></label>
      <p class="muted">— или —</p>
      <label><span>Вставить данные как текст (CSV/TSV: № , параметр , балл)</span>
        <textarea name="pasted" rows="8" placeholder="1, Познавательный мотив, 9&#10;2, Состязательный мотив, 9 ..."></textarea></label>
      <label><span>Методика (если вставляете текстом)</span>
        <select name="methodic">
          <option value="">— выберите методику —</option>
          <?php foreach ($methodics as $mname): ?>
            <option value="<?= $h($mname) ?>"><?= $h($mname) ?></option>
          <?php endforeach; ?>
        </select></label>
      <button class="btn" type="submit">Загрузить</button>
    </form>
    <div class="card">
      <h2>Не загружать вручную</h2>
      <p class="muted">Наблюдатель папки для Windows: положите файл в ту папку, куда Эгоскоп
         сохраняет выгрузки, и запустите один раз. Он сам пропишется в автозагрузку и дальше
         будет отправлять сюда каждый новый <code>.xls</code>, открывая профиль в браузере.</p>
      <p><a class="btn" href="/tools/neuropro-watch.cmd" download>⤓ Скачать наблюдатель папки (Windows)</a></p>
      <p class="muted">Отслеживаемые папки и снятие с автозапуска — ключи <code>/status</code> и
         <code>/stop</code> у того же файла. PHP на компьютере оператора не нужен.</p>
    </div>
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
    // Расчёт один на всю страницу: диаграмма, матрица и таблица правки должны
    // показывать одни и те же числа — и незачем считать их трижды.
    $m = Metrics::build($prof, $phys);
    $svg = cognitive_svg($prof, $phys, $m);
    $interps = Db::all('SELECT i.*, v.version_no FROM interpretations i JOIN prompt_versions v ON v.id=i.prompt_version_id WHERE i.profile_id=? ORDER BY i.created_at DESC', [$p['id']]);
    // Интерактивная матрица: математика всегда, плюс фрагмент интерпретации по
    // каждой шкале, если она уже сделана (последняя по времени) — #9.
    $matrix = Matrix::interactiveHtml($m, (string)($interps[0]['content'] ?? ''), ['width' => 900]);
    $active = Prompts::activeVersion($prof['test_key']);
    // Список версий промптов для выбора вручную, если активная не задана (#8).
    $fam = Prompts::family($prof['test_key']);
    $famVersions = $fam ? Prompts::versions((int)$fam['id']) : [];
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
    <?php if ($matrix !== ''): ?>
      <div class="card">
        <div class="chart"><?= $matrix ?></div>
        <?= Matrix::legendHtml($m) ?>
        <p class="muted">Эта же матрица уходит в отчёт клиента вместо таблиц (в PDF/письме — статичная).</p>
      </div>
    <?php endif; ?>
    <?= phys_table_form($prof, $phys, (int)$p['id'], $h, $m, (string)($p['phys_image'] ?? '')) ?>
    <form method="post" action="?p=interpret" class="row">
      <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
      <span class="row" style="justify-content:flex-start;flex-wrap:wrap">
      <?php if ($active): ?>
        <button class="btn" type="submit">▶ Сделать интерпретацию (промпт v<?= $h($active['version_no']) ?>, <?= $h($active['model_id']) ?>)</button>
      <?php elseif ($famVersions): ?>
        <span class="muted">Промпт не выбран — выберите:</span>
        <select name="version_id">
          <?php foreach ($famVersions as $v): ?>
            <option value="<?= (int)$v['id'] ?>">v<?= $h($v['version_no']) ?> — <?= $h($v['model_id']) ?><?= $v['comment'] ? ' · '.$h($v['comment']) : '' ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn" type="submit">▶ Сделать интерпретацию</button>
      <?php else: ?>
        <button class="btn" type="submit" disabled>▶ Сделать интерпретацию (промпт не выбран)</button>
        <a class="btn ghost" href="?p=prompts">Создать промпт →</a>
      <?php endif; ?>
      </span>
      <span class="row" style="justify-content:flex-end;gap:8px">
        <a class="btn ghost" href="?p=profile&id=<?= (int)$p['id'] ?>">Заменить скриншот</a>
        <a class="btn ghost danger" href="?p=profile_delete&id=<?= (int)$p['id'] ?>" onclick="return confirm('Удалить анализ и все его интерпретации? Действие необратимо.')">🗑 Удалить анализ</a>
      </span>
    </form>
    <?php foreach ($interps as $it): ?>
      <div class="card">
        <div class="row"><h2>Интерпретация (промпт v<?= $h($it['version_no']) ?>, <?= $h($it['model_id']) ?>) <span class="muted"><?= $h($it['created_at']) ?></span>
          <?php if (!empty($it['edited_at'])): ?><span class="muted">· отредактировано <?= $h($it['edited_at']) ?></span><?php endif; ?></h2>
          <span>
            <a class="btn sm" href="?p=report&interp=<?= (int)$it['id'] ?>&print=1" target="_blank">⤓ PDF</a>
            <a class="btn sm" href="?p=email&interp=<?= (int)$it['id'] ?>" onclick="return confirm('Отправить отчёт на почту клиента?')">✉ На почту</a>
            <a class="btn sm danger" href="?p=interp_delete&interp=<?= (int)$it['id'] ?>" onclick="return confirm('Удалить интерпретацию? Действие необратимо.')">🗑</a>
          </span>
        </div>
        <p class="muted">Двойной клик по абзацу — правка (Ctrl+Enter — сохранить, Esc — отмена).
           Пустой абзац удаляется. Правка сразу попадает в PDF и в письмо.</p>
        <div class="interp editable" data-interp="<?= (int)$it['id'] ?>"><?= Report::interpToEditableHtml($it['content']) ?></div>
      </div>
    <?php endforeach; ?>
    <script>
    /* Правка отчёта до выгрузки в PDF: двойной клик открывает абзац как текст
       Markdown (ровно то, что лежит в базе), сохранение перерисовывает весь
       блок интерпретации — после удаления абзаца номера сдвигаются. */
    (function(){
      let editing = null;   // редактируемый .np-block

      // Состояние снимаем ДО подмены содержимого: удаление textarea вызывает
      // blur, и если бы блок ещё считался редактируемым, обработчик blur начал
      // бы вторую правку прямо во время перерисовки первой.
      function close(block, html){
        editing = null;
        block.classList.remove('editing');
        block.innerHTML = html;
      }

      function open(block, text){
        if (editing) return;                       // одна правка за раз
        editing = block;
        const rendered = block.innerHTML;
        const src = text !== undefined ? text : (block.dataset.src || '');
        const ta = document.createElement('textarea');
        ta.className = 'blockedit';
        ta.value = src;
        block.classList.add('editing');
        block.innerHTML = '';
        block.appendChild(ta);
        ta.style.height = Math.max(70, ta.scrollHeight + 12) + 'px';
        ta.focus();
        ta.setSelectionRange(ta.value.length, ta.value.length);
        ta.addEventListener('input', ()=>{ ta.style.height = Math.max(70, ta.scrollHeight + 4) + 'px'; });
        ta.addEventListener('keydown', e=>{
          if (e.key === 'Escape'){ e.preventDefault(); close(block, rendered); }
          if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)){ e.preventDefault(); save(block, ta.value, rendered); }
        });
        // Клик мимо абзаца = сохранить: оператор правит текст, а не заполняет форму.
        ta.addEventListener('blur', ()=>{ if (editing === block) save(block, ta.value, rendered); });
      }

      async function save(block, text, rendered){
        const box = block.closest('.editable');
        const idx = block.dataset.block;
        if (text === (block.dataset.src || '')) { close(block, rendered); return; }
        close(block, rendered);
        const body = new URLSearchParams({interp: box.dataset.interp, block: idx, text: text});
        try{
          const r = await fetch('?p=interp_block', {method:'POST', body:body,
            headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}});
          const data = await r.json();
          if (!data.ok) throw new Error(data.error || 'не удалось сохранить');
          box.innerHTML = data.html;
        }catch(err){
          // Правка не теряется молча: возвращаем её в поле, чтобы оператор мог
          // повторить сохранение или скопировать текст.
          alert('Абзац не сохранён: ' + err.message);
          const back = box.querySelector('.np-block[data-block="' + idx + '"]');
          if (back) open(back, text);
        }
      }

      document.querySelectorAll('.interp.editable').forEach(box=>{
        box.addEventListener('dblclick', e=>{
          const block = e.target.closest('.np-block');
          if (block && box.contains(block)) open(block);
        });
      });
    })();
    </script>
    <?php
    layout('Результат', ob_get_clean(), $h);
}

function view_report(array $cfg): void {
    $it = Db::one('SELECT * FROM interpretations WHERE id=?', [(int)($_GET['interp'] ?? 0)]);
    if (!$it) { http_response_code(404); echo 'not found'; return; }
    $p = profile_row((int)$it['profile_id']);
    $prof = profile_decode($p);
    $phys = Phys::decode($p['phys_json'] ?? null, count($prof['scores']));
    $html = Report::html($prof, Report::interpToHtml($it['content']), $cfg, [
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
        <div class="row"><h2><?= $h($f['name']) ?> <span class="muted">[<?= $h($f['test_key']) ?>]</span></h2>
          <a class="btn sm danger" href="?p=prompt_family_delete&id=<?= (int)$f['id'] ?>"
             onclick="return confirm('Удалить всё семейство промптов «<?= $h($f['name']) ?>» вместе со всеми версиями и интерпретациями? При следующем запуске оно пересоздастся с чистой версией v1.')">🗑 Удалить методику</a>
        </div>
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
              <?php else: ?>
                <a class="btn sm danger" href="?p=prompt_delete&v=<?= (int)$v['id'] ?>&force=1"
                   onclick="return confirm('Удалить версию v<?= $h($v['version_no']) ?>? <?= (int)$v['interp_count'] > 0 ? 'Будут удалены и '.(int)$v['interp_count'].' связанных интерпретаций. ' : '' ?><?= $isActive ? 'Это активная версия — активной станет другая (или ни одна). ' : '' ?>Действие необратимо.')">🗑</a>
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
    $labels = array_map(fn ($s) => $s['label'], $prof['scores']);
    // Сам скриншот сохраняем как data URI — оператор сверяет цифры с графиками
    // рядом с таблицей расчёта (см. phys_table_form).
    $image = 'data:' . $mime . ';base64,' . base64_encode($bytes);

    // Ошибку распознавания не глушим: сохраняем в профиль и показываем оператору
    // — «все ошибки обязательно выводить». Профиль при этом остаётся рабочим.
    // Основной путь — ОТДЕЛЬНАЯ vision-модель, которая смотрит на графики
    // (LLM::recognizeSignificance): плоский OCR не различал знак «Знач.» по
    // положению столбика. Yandex OCR остаётся запасным вариантом.
    $ocrText = '';
    $parsed = null;
    $errParts = [];
    try {
        $rec = LLM::recognizeSignificance($bytes, $mime, $labels);
        $ocrText = $rec['raw'];
        $parsed = Phys::fromVision($rec['rows'], $labels);
    } catch (Throwable $e) {
        $errParts[] = 'vision: ' . $e->getMessage();
    }
    // Запасной путь: Yandex OCR + эвристический разбор столбцов.
    if ($parsed === null || !Phys::hasData($parsed)) {
        try {
            $ocrText = LLM::ocrImage($bytes, $mime);
            $fallback = Phys::parse($ocrText, $labels);
            if (Phys::hasData($fallback)) $parsed = $fallback;
            elseif ($parsed === null) $parsed = $fallback;
        } catch (Throwable $e) {
            $errParts[] = 'ocr: ' . $e->getMessage();
        }
    }
    if ($parsed === null) {
        $parsed = Phys::parse('', $labels);
        $parsed['error'] = 'Распознавание недоступно (' . implode(' | ', $errParts) . '). Значения можно ввести вручную ниже.';
    } elseif (!Phys::hasData($parsed)) {
        $parsed['error'] = 'Скриншот распознан, но ни одно значение «Знач.» не сопоставлено с осями'
            . ($errParts ? ' (' . implode(' | ', $errParts) . ')' : '') . '. Введите значения вручную ниже.';
    }
    Db::q('UPDATE profiles SET phys_ocr_text=?, phys_json=?, phys_image=?, status=? WHERE id=?',
        [$ocrText, json_encode($parsed, JSON_UNESCAPED_UNICODE), $image, 'ready', $id]);
    redirect('?p=result&id=' . $id . ($parsed['error'] !== null ? '&err=' . urlencode($parsed['error']) : ''));
}

/** Ручная правка физиологии: значения «Знач.» и флажки p<0.05 по осям. */
function act_phys_save(array $cfg): void {
    $id = (int)($_POST['id'] ?? 0);
    $p = profile_row($id);
    $prof = profile_decode($p);
    $count = count($prof['scores']);
    $old = Phys::decode($p['phys_json'] ?? null, $count);
    // Правку ведёт Phys: распознанные p / СМК / «Балл» по остальным осям
    // сохраняются, а флажок достоверности не обнуляет p, а задаёт его.
    $parsed = Phys::fromManual((array)($_POST['zna'] ?? []), $count, (array)($_POST['sig'] ?? []), $old);
    Db::q('UPDATE profiles SET phys_json=?, status=? WHERE id=?',
        [json_encode($parsed, JSON_UNESCAPED_UNICODE), 'ready', $id]);
    redirect('?p=result&id=' . $id . '&ok=' . urlencode('Физиология сохранена.'));
}

function act_interpret(array $cfg): void {
    $id = (int)($_POST['id'] ?? 0);
    $p = profile_row($id);
    $prof = profile_decode($p);
    // Оператор мог выбрать версию вручную (#8), иначе — активная версия методики.
    $vid = (int)($_POST['version_id'] ?? 0);
    $version = $vid ? Prompts::version($vid) : null;
    if (!$version) $version = Prompts::activeVersion($prof['test_key']);
    if (!$version) {
        redirect('?p=result&id=' . $id . '&err=' . urlencode('Для этой методики не выбран промпт (страница «Промпты»).'));
    }
    // Нейросеть получает РАСЧЁТ (Metrics), а не сырой текст OCR: уровни,
    // достоверность и разделы считает код, модель только пишет текст.
    $phys = Phys::decode($p['phys_json'] ?? null, count($prof['scores']));
    // Ошибку нейросети показываем на странице результата, а не голой 500-й.
    try {
        $content = Interpret::run($prof, $phys, $version);
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
    $html = Report::html($prof, Report::interpToHtml($it['content']), $cfg, ['phys' => $phys]);
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
    $res = Prompts::deleteVersion((int)($_GET['v'] ?? 0), !empty($_GET['force']));
    redirect('?p=prompts' . ($res['ok'] ? '&ok=' . urlencode('Версия промпта удалена.') : '&err=' . urlencode($res['error'])));
}

/**
 * Правка одного абзаца интерпретации (двойной клик на странице результата).
 * Ответ — JSON с заново собранным HTML всей интерпретации: после удаления
 * абзаца номера блоков сдвигаются, и перерисовать проще, чем синхронизировать
 * их на клиенте. Правка сразу попадает и в PDF, и в письмо — они читают тот же
 * текст из базы.
 */
function act_interp_block(array $cfg): void {
    header('Content-Type: application/json; charset=utf-8');
    $json = static function (array $data, int $code = 200): void {
        http_response_code($code);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    };
    $id = (int)($_POST['interp'] ?? 0);
    $idx = (int)($_POST['block'] ?? -1);
    $it = Db::one('SELECT * FROM interpretations WHERE id=?', [$id]);
    if (!$it) { $json(['ok' => false, 'error' => 'Интерпретация не найдена'], 404); return; }
    try {
        $updated = Report::replaceBlock((string)$it['content'], $idx, (string)($_POST['text'] ?? ''));
    } catch (Throwable $e) {
        $json(['ok' => false, 'error' => $e->getMessage()], 409); return;
    }
    Db::q("UPDATE interpretations SET content=?, edited_at=datetime('now') WHERE id=?", [$updated, $id]);
    $json(['ok' => true, 'html' => Report::interpToEditableHtml($updated)]);
}

function act_interp_delete(array $cfg): void {
    $it = Db::one('SELECT profile_id FROM interpretations WHERE id=?', [(int)($_GET['interp'] ?? 0)]);
    Db::q('DELETE FROM interpretations WHERE id=?', [(int)($_GET['interp'] ?? 0)]);
    redirect('?p=result&id=' . (int)($it['profile_id'] ?? 0));
}

/** Удаление анализа (профиля) целиком: интерпретации уходят каскадом (FK). */
function act_profile_delete(array $cfg): void {
    $id = (int)($_GET['id'] ?? 0);
    profile_row($id); // 404, если профиля нет
    Db::q('DELETE FROM interpretations WHERE profile_id=?', [$id]);
    Db::q('DELETE FROM profiles WHERE id=?', [$id]);
    redirect('?p=dashboard&ok=' . urlencode('Анализ удалён.'));
}

/** Удаление семейства промптов целиком (со всеми версиями и интерпретациями). */
function act_prompt_family_delete(array $cfg): void {
    $famId = (int)($_GET['id'] ?? 0);
    $res = Prompts::deleteFamily($famId);
    redirect('?p=prompts' . ($res['ok'] ? '&ok=' . urlencode('Семейство промптов удалено.') : '&err=' . urlencode($res['error'])));
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

/**
 * @param array|null $phys    структура Phys::decode (aligned + p + sig) или null
 * @param array|null $metrics готовый расчёт, если он уже посчитан на странице
 */
function cognitive_svg(array $prof, ?array $phys = null, ?array $metrics = null): string {
    return Chart::fromMetrics($metrics ?? Metrics::build($prof, $phys), [
        'title' => Profile::chartTitle((string)($prof['test_key'] ?? '')),
        'size' => 560,
    ]);
}

/**
 * Таблица разобранной физиологии с ручной правкой + ПОСЧИТАННЫЕ уровень,
 * положение относительно медианы и раздел отчёта. Оператор видит ровно то, что
 * уйдёт в отчёт и в нейросеть, и может поправить «Знач.»/достоверность до
 * интерпретации. Строки с p<0.05 — жирные; пустое поле = данных нет.
 */
function phys_table_form(array $prof, ?array $phys, int $id, callable $h, ?array $metrics = null, string $image = ''): string {
    if ($phys === null) return '';
    $m = $metrics ?? Metrics::build($prof, $phys);
    ob_start(); ?>
    <div class="card">
      <h2>Расчёт по шкалам — проверьте перед интерпретацией</h2>
      <p class="muted">«Знач.» и достоверность распознаны со скриншота; уровень, положение
      относительно медианы и раздел отчёта считает сервис (не нейросеть).
      Поправьте значения при необходимости и сохраните. Шкала физиологии на
      диаграмме: ±<?= $h(Metrics::num($m['phys_scale'])) ?>.</p>
      <?php if ($image !== ''): ?>
        <div class="shot">
          <p class="muted">Исходный скриншот значимости — сверьте цифры ниже с положением графиков:</p>
          <img src="<?= $h($image) ?>" alt="Скриншот таблицы значимости">
        </div>
      <?php endif; ?>
      <?php foreach ($m['warnings'] as $w): ?><div class="msg warn">⚠ <?= $h($w) ?></div><?php endforeach; ?>
      <form method="post" action="?p=phys_save">
        <input type="hidden" name="id" value="<?= $id ?>">
        <table class="grid">
          <tr><th>Шкала</th><th>Балл</th><th>Уровень</th><th>Знач.</th><th>p&lt;0.05</th>
              <th>Физиология</th><th>СМК</th><th>Раздел отчёта</th></tr>
          <?php foreach ($m['axes'] as $i => $a):
              $v = $a['zna']; ?>
          <tr<?= $a['sig'] ? ' class="sig"' : '' ?>>
            <td><?= $h($a['label']) ?></td>
            <td class="num"><?= $h(Metrics::num($a['score'])) ?> из <?= (int)$a['scale_max'] ?>
                <span class="muted">(<?= $h(Metrics::num($a['pct'])) ?>%)</span></td>
            <td><?= $h($a['level_label']) ?>, <?= $h($a['rank_label']) ?></td>
            <td><input class="num" type="text" name="zna[<?= $i ?>]" value="<?= $v === null ? '' : $h(Metrics::num((float)$v)) ?>" placeholder="—"></td>
            <td><input type="checkbox" name="sig[<?= $i ?>]" value="1" <?= $a['sig'] ? 'checked' : '' ?>>
                <span class="muted"><?= $h($a['p_label']) ?></span></td>
            <td><?= $h($a['phys_label']) ?></td>
            <td><?= $h($a['smk'] ?: '—') ?></td>
            <td><?= $h($a['category_title'] ?: 'не упоминается') ?></td>
          </tr>
          <?php endforeach; ?>
        </table>
        <button class="btn sm" type="submit">Сохранить и пересчитать</button>
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
  .legend{color:#6b7682;font-size:11px;line-height:1.6}
  .paste{border:2px dashed #cfd6dd;border-radius:6px;padding:24px;text-align:center;color:#8a949d;cursor:text}
  .shot img{max-width:100%;border:1px solid #d7dde3;border-radius:6px;margin:4px 0 10px}
  .interp h1,.interp h2,.interp h3{color:#b3203b}
  .interp.editable .np-block{border:1px solid transparent;border-radius:5px;padding:1px 6px;margin:0 -6px;cursor:text}
  .interp.editable .np-block:hover{border-color:#e0e5ea;background:#fbfcfd}
  .interp.editable .np-block.editing{border-color:#b3203b;background:#fff}
  textarea.blockedit{width:100%;font:inherit;font-family:Verdana,Geneva,sans-serif;border:0;outline:0;resize:vertical;padding:6px 0;background:transparent}
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
