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
        case 'matrix_conf':   act_matrix_conf($cfg); break;
        case 'interpret':     act_interpret($cfg); break;
        case 'result':        view_result($cfg, $h); break;
        case 'report':        view_report($cfg); break;
        case 'email':         act_email($cfg); break;
        case 'prompts':       view_prompts($cfg, $h); break;
        case 'prompt_edit':   $method === 'POST' ? act_prompt_save($cfg) : view_prompt_edit($cfg, $h); break;
        case 'prompt_delete': act_prompt_delete($cfg); break;
        case 'prompt_family_delete': act_prompt_family_delete($cfg); break;
        case 'interp_text':   act_interp_text($cfg); break;
        case 'interp_source': view_interp_source($cfg); break;
        case 'interp_paste':  act_interp_paste($cfg); break;
        case 'interp_delete': act_interp_delete($cfg); break;
        case 'profile_delete': act_profile_delete($cfg); break;
        case 'trash':         view_trash($cfg, $h); break;
        case 'trash_restore': act_trash_restore($cfg); break;
        case 'trash_purge':   act_trash_purge($cfg); break;
        default:              view_dashboard($cfg, $h);
    }
} catch (Throwable $e) {
    http_response_code(500);
    error_log('NeuroPro 500: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    layout('Ошибка', '<div class="msg bad">' . $h($e->getMessage()) . '</div>', $h);
}

/* ─────────────────────────── Views ─────────────────────────── */

function view_dashboard(array $cfg, callable $h): void {
    // Корзину чистим там, где она видна: отдельного крона у сервиса нет, а
    // просроченное (старше Trash::KEEP_DAYS) не должно копиться месяцами.
    Trash::purgeExpired();
    $awaiting = Db::all("SELECT * FROM profiles WHERE status='awaiting_screenshot' AND deleted_at IS NULL ORDER BY created_at DESC");
    $ready = Db::all("SELECT * FROM profiles WHERE status!='awaiting_screenshot' AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 30");
    $trash = Trash::counts();
    ob_start(); ?>
    <div class="row"><h1>Профили</h1><a class="btn" href="?p=upload">+ Загрузить Excel</a></div>
    <?php if ($awaiting): ?>
      <h2>Ожидают скриншот значимости</h2>
      <table class="grid"><tr><th>ФИО</th><th>Методика</th><th>Дата</th><th></th></tr>
      <?php foreach ($awaiting as $p): ?>
        <tr><td><?= $h($p['name']) ?></td><td><?= $h($p['methodic']) ?></td><td><?= $h($p['test_date']) ?></td>
        <td><a class="btn sm" href="?p=profile&id=<?= (int)$p['id'] ?>">Добавить скриншот →</a>
        <a class="btn sm danger" href="?p=profile_delete&id=<?= (int)$p['id'] ?>" onclick="return confirm('Удалить анализ? Его можно будет вернуть из «Удалённых» в течение <?= Trash::KEEP_DAYS ?> дней.')">🗑</a></td></tr>
      <?php endforeach; ?></table>
    <?php endif; ?>
    <h2>Готовые профили</h2>
    <table class="grid"><tr><th>ФИО</th><th>Методика</th><th>Статус</th><th>Интерпретаций</th><th></th></tr>
    <?php foreach ($ready as $p):
        $c = Db::one('SELECT COUNT(*) c FROM interpretations WHERE profile_id=? AND deleted_at IS NULL', [$p['id']]); ?>
      <tr><td><?= $h($p['name']) ?></td><td><?= $h($p['methodic']) ?></td><td><?= $h($p['status']) ?></td>
      <td><?= (int)($c['c'] ?? 0) ?></td>
      <td><a class="btn sm" href="?p=result&id=<?= (int)$p['id'] ?>">Открыть →</a>
      <a class="btn sm danger" href="?p=profile_delete&id=<?= (int)$p['id'] ?>" onclick="return confirm('Удалить анализ и все его интерпретации? Их можно будет вернуть из «Удалённых» в течение <?= Trash::KEEP_DAYS ?> дней.')">🗑</a></td></tr>
    <?php endforeach; ?></table>
    <!-- Корзина: неброская серая ссылка под списком — она нужна редко, но
         без неё удаление остаётся необратимым (#3). -->
    <p class="trashlink"><a href="?p=trash">Удалённые<?= $trash['total'] ? ' (' . (int)$trash['total'] . ')' : '' ?></a></p>
    <?php
    layout('Дашборд', ob_get_clean(), $h);
}

function view_upload(array $cfg, callable $h): void {
    $methodics = methodic_options();
    ob_start(); ?>
    <h1>Загрузка профиля</h1>
    <form method="post" enctype="multipart/form-data" class="card">
      <label><span>Excel-файл Эгоскопа (.xls / .xlsx)</span>
        <input type="file" name="file" accept=".xls,.xlsx,.csv"></label>
      <p class="muted">— или —</p>
      <label><span>Вставить данные как текст (CSV/TSV: № , параметр , балл)</span>
        <textarea name="pasted" rows="8" placeholder="1, Познавательный мотив, 9&#10;2, Состязательный мотив, 9 ..."></textarea></label>
      <label><span>Методика (если вставляете текстом)</span>
        <select name="test_key">
          <option value="">— выберите методику —</option>
          <?php foreach ($methodics as $key => $mname): ?>
            <option value="<?= $h($key) ?>"><?= $h($mname) ?></option>
          <?php endforeach; ?>
        </select>
        <span class="muted">Список — методики со страницы «Промпты»: одна методика = одно название,
        поэтому одна и та же методика не двоится из-за разного написания.</span></label>
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
    $interps = Db::all('SELECT i.*, v.version_no FROM interpretations i JOIN prompt_versions v ON v.id=i.prompt_version_id
                        WHERE i.profile_id=? AND i.deleted_at IS NULL ORDER BY i.created_at DESC', [$p['id']]);
    $trashed = count(Trash::interps((int)$p['id']));
    // Интерактивная матрица: математика всегда, плюс фрагмент интерпретации по
    // каждой шкале, если она уже сделана (последняя по времени) — #9.
    $matrix = Matrix::interactiveHtml($m, (string)($interps[0]['content'] ?? ''),
        ['width' => 900, 'save_url' => '?p=matrix_conf']);
    $active = Prompts::activeVersion($prof['test_key']);
    // Список версий промптов для выбора вручную, если активная не задана (#8).
    $fam = Prompts::family($prof['test_key']);
    $famVersions = $fam ? Prompts::versions((int)$fam['id']) : [];
    // Модели для выбора под кнопкой: по умолчанию — модель промпта, но оператор
    // может переделать отчёт на другой нейросети (#3).
    $models = LLM::modelsByGroup($cfg);
    $defaultModel = (string)($active['model_id'] ?? ($famVersions[0]['model_id'] ?? ''));
    // Пока интерпретаций нет, правой колонке нечего показывать — страница
    // остаётся обычной лентой во всю ширину.
    $split = $interps !== [];
    ob_start(); ?>
    <h1><?= $h($prof['name']) ?> <span class="muted">— <?= $h($prof['methodic']) ?></span></h1>
    <?php if (!empty($p['deleted_at'])): ?>
      <div class="msg warn">Этот анализ удалён <?= $h($p['deleted_at']) ?> и лежит в «Удалённых»:
        исчезнет насовсем через <?= (int)Trash::daysLeft($p['deleted_at']) ?> дн.
        <a href="?p=trash_restore&kind=profile&id=<?= (int)$p['id'] ?>">Восстановить</a></div>
    <?php endif; ?>
    <?php if ($phys !== null && $phys['error'] !== null): ?>
      <div class="msg bad"><b>Ошибка распознавания скриншота:</b> <?= $h($phys['error']) ?><br>
      Значения физиологии можно ввести вручную в таблице ниже.</div>
    <?php elseif ($phys !== null && !Phys::hasData($phys)): ?>
      <div class="msg warn">Физиология не распознана: OCR не нашёл ни одного значения «Знач.» по осям.
      Проверьте скриншот или введите значения вручную в таблице ниже.</div>
    <?php endif; ?>
    <div class="split<?= $split ? ' on' : '' ?>">
    <div class="split-col">
    <div class="chart card"><?= $svg ?></div>
    <?php if ($matrix !== ''): ?>
      <div class="card">
        <div class="chart"><?= $matrix ?></div>
        <?= Matrix::legendHtml($m) ?>
        <p class="muted">Эта же матрица уходит в отчёт клиента вместо таблиц (в PDF/письме — статичная).</p>
      </div>
    <?php endif; ?>
    <?= phys_table_form($prof, $phys, (int)$p['id'], $h, $m, (string)($p['phys_image'] ?? '')) ?>
    <form method="post" action="?p=interpret" id="interpform">
      <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
      <div class="row">
      <span class="row" style="justify-content:flex-start;flex-wrap:wrap">
      <?php if ($active): ?>
        <button class="btn" type="submit">▶ Составить интерпретацию (промпт v<?= $h($active['version_no']) ?>)</button>
      <?php elseif ($famVersions): ?>
        <span class="muted">Промпт не выбран — выберите:</span>
        <select name="version_id">
          <?php foreach ($famVersions as $v): ?>
            <option value="<?= (int)$v['id'] ?>">v<?= $h($v['version_no']) ?> — <?= $h($v['model_id']) ?><?= $v['comment'] ? ' · '.$h($v['comment']) : '' ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn" type="submit">▶ Составить интерпретацию</button>
      <?php else: ?>
        <button class="btn" type="submit" disabled>▶ Составить интерпретацию (промпт не выбран)</button>
        <a class="btn ghost" href="?p=prompts">Создать промпт →</a>
      <?php endif; ?>
      <?php if ($active || $famVersions): ?>
        <!-- Рядом с кнопкой отчёта — то же самое, но без нейросети: копия того,
             что уходит в модель (промпты обоих слоёв + расчёт). type=button,
             иначе кнопка отправила бы форму интерпретации (#1). Кнопка сразу
             открывает окно вставки: оператор уходит с промптом в чужой чат и
             возвращается с готовым отчётом, который надо куда-то деть. -->
        <button class="btn ghost" type="button" id="copysrc"
                title="Скопировать промпты обоих слоёв и данные — и сразу вставить в открывшемся окне готовый отчёт из чата">⧉ Скопировать промпт и данные</button>
      <?php endif; ?>
      </span>
      <span class="row" style="justify-content:flex-end;gap:8px">
        <a class="btn ghost" href="?p=profile&id=<?= (int)$p['id'] ?>">Заменить скриншот</a>
        <a class="btn ghost danger" href="?p=profile_delete&id=<?= (int)$p['id'] ?>" onclick="return confirm('Удалить анализ и все его интерпретации? Их можно будет вернуть из «Удалённых» в течение <?= Trash::KEEP_DAYS ?> дней.')">🗑 Удалить анализ</a>
      </span>
      </div>
      <?php if (($active || $famVersions) && $models): ?>
        <!-- Модель под кнопкой (#3): по умолчанию — из промпта; можно выбрать
             другую и переделать отчёт — он добавится новой строкой в список
             ниже, с этой моделью. -->
        <label class="modelpick"><span>Нейросеть для отчёта — по умолчанию из промпта; выберите другую, чтобы переделать анализ на ней</span>
          <select name="model_id">
            <?php foreach ($models as $gname => $grows): ?>
              <optgroup label="<?= $h((string)$gname) ?>">
                <?php foreach ($grows as $mrow): ?>
                  <option value="<?= $h($mrow['id']) ?>"<?= (string)$mrow['id'] === $defaultModel ? ' selected' : '' ?>><?= $h($mrow['label']) ?> — <?= $h($mrow['full_id']) ?></option>
                <?php endforeach; ?>
              </optgroup>
            <?php endforeach; ?>
          </select>
        </label>
      <?php endif; ?>
    </form>
    <?php if ($active || $famVersions): ?>
      <!-- Окно вставки. Копирование промпта — половина работы: отчёт оператор
           получает в чужом чате и возвращается его сюда положить, а места для
           этого не было (приходилось заводить интерпретацию нейросетью и
           переписывать её текст). Поэтому кнопка копирования сразу открывает
           поле вставки, а сам промпт лежит здесь же, ниже: буфер обмена
           браузер отдаёт только по https (или на localhost), и без него текст
           берётся выделением — Ctrl+C работает всегда. -->
      <dialog class="np-dialog" id="srcdlg">
        <form method="post" action="?p=interp_paste" id="pasteform">
          <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
          <input type="hidden" name="version_id" id="pasteversion" value="">
          <div class="row"><h2 style="margin:0">Промпт с данными скопирован — вставьте готовый отчёт</h2>
            <button class="btn sm ghost" type="button" id="srcclose" title="Закрыть (Esc)">✕</button></div>
          <p class="muted" id="srcstate">Собираем промпт…</p>
          <textarea id="pastetext" name="text" rows="14" placeholder="Ctrl+V — вставьте сюда текст отчёта из чата нейросети. Markdown: ## заголовок, **жирный**, - список."></textarea>
          <div class="row editbar">
            <span class="muted">Отчёт добавится в список новой интерпретацией — с правкой абзацев,
              PDF и письмом, как у отчёта нейросети. Ctrl+Enter — сохранить, Esc — закрыть
              (вставленный текст не пропадёт).</span>
            <span><button class="btn" type="submit">Сохранить отчёт</button></span>
          </div>
          <details class="srcbox" id="srcbox">
            <summary>Промпт и данные, которые уходят в нейросеть</summary>
            <p class="muted">Два системных промпта (содержание и язык) и сообщение с расчётом —
               ровно в том виде, в каком их получает модель. Текст только для чтения:
               правки делаются на странице «Промпты».</p>
            <textarea id="srcdump" rows="10" readonly></textarea>
          </details>
        </form>
      </dialog>
      <script>
      (function(){
        var btn=document.getElementById('copysrc'), dlg=document.getElementById('srcdlg'),
            box=document.getElementById('srcbox'), dump=document.getElementById('srcdump'),
            state=document.getElementById('srcstate'), ta=document.getElementById('pastetext'),
            form=document.getElementById('pasteform'), vfield=document.getElementById('pasteversion');
        if(!btn||!dlg||!dump||!ta) return;
        btn.addEventListener('click', async function(){
          // Версию промпта оператор мог выбрать вручную — берём ту же, что уйдёт
          // в интерпретацию, иначе скопированное разошлось бы с отчётом, а
          // вставленный текст лёг бы в базу с чужой версией.
          var vsel=document.querySelector('#interpform select[name=version_id]'),
              msel=document.querySelector('#interpform select[name=model_id]'),
              url='?p=interp_source&id=<?= (int)$p['id'] ?>'
                 + (vsel&&vsel.value ? '&version_id='+encodeURIComponent(vsel.value) : '')
                 + (msel&&msel.value ? '&model_id='+encodeURIComponent(msel.value) : '');
          vfield.value = vsel && vsel.value ? vsel.value : '';
          // Окно открываем СРАЗУ, до ответа сервера: оператору нужно поле для
          // вставки, а не ожидание сборки промпта.
          state.textContent='Собираем промпт…';
          box.open=false;
          if(!dlg.open) dlg.showModal();
          ta.focus();
          try{
            var r=await fetch(url), d=await r.json();
            if(!d.ok) throw new Error(d.error||'не удалось собрать промпт');
            dump.value=d.text;
            var copied=false;
            try{ await navigator.clipboard.writeText(d.text); copied=true; }catch(e){}
            state.textContent = copied
              ? '✓ Промпт с данными в буфере обмена. Вставьте его в чат нейросети, а готовый отчёт — сюда.'
              : 'Буфер обмена недоступен (нужен https). Раскройте промпт ниже и скопируйте его Ctrl+C, а готовый отчёт вставьте сюда.';
            if(!copied) box.open=true;
          }catch(err){
            state.textContent='Промпт собрать не удалось: '+err.message+'. Поле вставки ниже работает — сохранить отчёт можно.';
          }
        });
        document.getElementById('srcclose').addEventListener('click', function(){ dlg.close(); });
        // Ctrl+Enter — как в правке отчёта: руки те же, привычка та же.
        ta.addEventListener('keydown', function(e){
          if(e.key==='Enter' && (e.ctrlKey||e.metaKey)){ e.preventDefault(); form.requestSubmit(); }
        });
        // Уход со страницы с невставленным… точнее, с вставленным, но не
        // сохранённым отчётом — потеря чужой работы: текст живёт только в поле.
        var saving=false;
        form.addEventListener('submit', function(){ saving=true; });
        window.addEventListener('beforeunload', function(e){
          if(!saving && ta.value.trim()!==''){ e.preventDefault(); e.returnValue=''; }
        });
      })();
      </script>
      <!-- Полоса хода: интерпретация идёт десятки секунд (два слоя — первый
           пишет текст, второй его правит), и без обратной связи оператор жал
           кнопку повторно, порождая лишние запросы к нейросети (#12). -->
      <div class="card np-progress" id="interp-progress" hidden>
        <div class="row"><b>Составляем интерпретацию…</b><span class="muted" id="interp-timer">0 с</span></div>
        <div class="bar"><i></i></div>
        <p class="muted" id="interp-step">Шаг 1 из 2: разбор показателей нейросетью. Не закрывайте страницу.</p>
      </div>
      <script>
      (function(){
        var f=document.getElementById('interpform'), box=document.getElementById('interp-progress');
        if(!f||!box) return;
        f.addEventListener('submit', function(){
          // Кнопку гасим ПОСЛЕ отправки формы (setTimeout): disabled-кнопка не
          // попадает в тело запроса, а браузер уже начал отправку.
          setTimeout(function(){
            f.querySelectorAll('button,select,a.btn').forEach(function(el){
              if(el.tagName==='A') el.classList.add('off'); else el.disabled=true;
            });
          }, 0);
          box.hidden=false;
          box.scrollIntoView({behavior:'smooth', block:'nearest'});
          var t=0, timer=document.getElementById('interp-timer'), step=document.getElementById('interp-step');
          setInterval(function(){
            t++;
            timer.textContent=t+' с';
            // Точного прогресса у нас нет — есть два известных шага; вторая
            // фраза появляется примерно тогда, когда первый слой обычно готов.
            if(t===35) step.textContent='Шаг 2 из 2: литературная правка отчёта. Не закрывайте страницу.';
          }, 1000);
        });
      })();
      </script>
    <?php endif; ?>
    </div><!-- /split-col: графики -->
    <div class="split-col">
    <?php foreach ($interps as $it): ?>
      <div class="card">
        <div class="row"><h2>Интерпретация (промпт v<?= $h($it['version_no']) ?>, <?= $h($it['model_id']) ?>) <span class="muted"><?= $h($it['created_at']) ?></span>
          <?php if (!empty($it['edited_at'])): ?><span class="muted">· отредактировано <?= $h($it['edited_at']) ?></span><?php endif; ?></h2>
          <span>
            <!-- Откат/возврат — последние СОХРАНЁННЫЕ правки этой интерпретации
                 в рамках текущего открытия страницы (#6). -->
            <a class="btn sm ghost off" href="#" data-undo="<?= (int)$it['id'] ?>" title="Отменить последнюю правку">↶</a>
            <a class="btn sm ghost off" href="#" data-redo="<?= (int)$it['id'] ?>" title="Вернуть отменённую правку">↷</a>
            <a class="btn sm" href="?p=report&interp=<?= (int)$it['id'] ?>&print=1" target="_blank">⤓ PDF</a>
            <a class="btn sm" href="?p=email&interp=<?= (int)$it['id'] ?>" onclick="return confirm('Отправить отчёт на почту клиента?')">✉ На почту</a>
            <!-- Карандаш: тот же общий редактор, что и двойной клик — на
                 случай, если нечего кликнуть (пустой отчёт) или курсор нужен
                 просто в начале текста. -->
            <a class="btn sm" href="#" data-editall="<?= (int)$it['id'] ?>"
               title="Редактировать весь текст интерпретации (Markdown)">✏</a>
            <a class="btn sm danger" href="?p=interp_delete&interp=<?= (int)$it['id'] ?>" onclick="return confirm('Удалить интерпретацию? Её можно будет вернуть из «Удалённых» в течение <?= Trash::KEEP_DAYS ?> дней.')">🗑</a>
          </span>
        </div>
        <p class="muted">Двойной клик по тексту — правка открывается курсором ровно там, где вы
           кликнули. Клик за пределами поля правки — сохраняет текст автоматически
           (Ctrl+Enter — сохранить сразу, Esc — отменить правку). Правка сразу попадает
           в PDF и в письмо; ↶/↷ справа — откат и возврат последних сохранённых правок.</p>
        <div class="interp editable" data-interp="<?= (int)$it['id'] ?>"
             data-src="<?= $h(Report::normalizeForEdit((string)$it['content'])) ?>"><?= Report::interpToHtml($it['content']) ?></div>
      </div>
    <?php endforeach; ?>
    <?php if ($interps || $trashed): ?>
      <p class="trashlink"><a href="?p=trash&id=<?= (int)$p['id'] ?>">Удалённые<?= $trashed ? ' (' . (int)$trashed . ')' : '' ?></a></p>
    <?php endif; ?>
    </div><!-- /split-col: интерпретации -->
    </div><!-- /split -->
    <script>
    /* Правка отчёта до выгрузки в PDF — ОДИН общий редактор на весь текст
       (#6): построчной правки по абзацам больше нет, двойной клик в любом
       месте текста открывает Markdown-редактор с курсором ровно там, где
       кликнули, а клик за пределами поля правки сохраняет её автоматически —
       заполнять форму или нажимать «Сохранить» не обязательно. */
    (function(){
      // История ПОСЛЕДНИХ СОХРАНЁННЫХ версий — на время текущего открытия
      // страницы (сама интерпретация неограниченно версионируется только в
      // виде «что сейчас в базе»): ↶/↷ листают её, не трогая сервер, кроме
      // самого отката/возврата.
      const history = new Map();  // interpId -> {stack:[text,…], pos:int}

      function histOf(box){
        const id = box.dataset.interp;
        if (!history.has(id)) history.set(id, {stack: [box.dataset.src || ''], pos: 0});
        return history.get(id);
      }

      function updateHistButtons(box){
        const h = histOf(box), id = box.dataset.interp;
        const u = document.querySelector('[data-undo="' + id + '"]'), r = document.querySelector('[data-redo="' + id + '"]');
        if (u) u.classList.toggle('off', h.pos <= 0);
        if (r) r.classList.toggle('off', h.pos >= h.stack.length - 1);
      }

      function pushHist(box, text){
        const h = histOf(box);
        if (text === h.stack[h.pos]) return;
        h.stack = h.stack.slice(0, h.pos + 1);
        h.stack.push(text);
        h.pos = h.stack.length - 1;
        updateHistButtons(box);
      }

      /* Курсор туда, где кликнули (#6): ищем текстовый узел под курсором,
         берём короткий буквальный кусок вокруг клика и находим его в самом
         Markdown — разметка (#, **, -) вокруг слов не мешает: сами слова в
         исходнике те же, что и в отрисованном тексте. */
      function clickOffset(rawText, x, y){
        let range = null;
        if (document.caretRangeFromPoint) range = document.caretRangeFromPoint(x, y);
        else if (document.caretPositionFromPoint) {
          const pos = document.caretPositionFromPoint(x, y);
          if (pos) { range = document.createRange(); range.setStart(pos.offsetNode, pos.offset); }
        }
        if (!range || range.startContainer.nodeType !== Node.TEXT_NODE) return 0;
        const full = range.startContainer.textContent, off = range.startOffset;
        const start = Math.max(0, off - 16);
        const anchor = full.slice(start, Math.min(full.length, off + 16));
        for (let len = anchor.length; len >= 4; len -= 2) {
          const probe = anchor.slice(0, len);
          const idx = rawText.indexOf(probe);
          if (idx !== -1) return Math.min(rawText.length, idx + Math.min(off - start, probe.length));
        }
        return 0;
      }

      /* ─── Общий редактор ────────────────────────────────────────────── */
      function openFull(box, caret){
        if (box.classList.contains('fullediting')) return;
        const rendered = box.innerHTML;
        box.classList.add('fullediting');
        box.innerHTML = '';
        const ta = document.createElement('textarea');
        ta.className = 'fulledit';
        ta.value = box.dataset.src || '';
        const bar = document.createElement('div');
        bar.className = 'row editbar';
        const hint = document.createElement('span');
        hint.className = 'muted';
        hint.textContent = 'Markdown: ## заголовок, **жирный**, - список. Клик за пределами поля — сохранить. Ctrl+Enter — сохранить сразу, Esc — отменить правку.';
        bar.append(hint);
        box.append(ta, bar);
        ta.style.height = Math.max(320, ta.scrollHeight + 16) + 'px';
        ta.focus();
        const pos = typeof caret === 'number' ? Math.max(0, Math.min(ta.value.length, caret)) : ta.value.length;
        ta.setSelectionRange(pos, pos);
        // Флаг вместо повторного blur-сохранения: Esc и Ctrl+Enter снимают
        // textarea из DOM сами, а blur — только запасной путь для клика мимо.
        let settled = false;
        const cancel = ()=>{ settled = true; box.classList.remove('fullediting'); box.innerHTML = rendered; };
        const commit = ()=>{ if (settled) return; settled = true; saveFull(box, ta.value, rendered); };
        // Клик за пределами textarea (blur) — не отмена, а сохранение: правка
        // текста, а не заполнение формы, которую можно захлопнуть без следа.
        ta.addEventListener('blur', commit);
        ta.addEventListener('keydown', e=>{
          if (e.key === 'Escape'){ e.preventDefault(); cancel(); }
          if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)){ e.preventDefault(); commit(); }
        });
      }

      async function saveFull(box, text, rendered){
        if (text === (box.dataset.src || '')) { box.classList.remove('fullediting'); box.innerHTML = rendered; return; }
        const body = new URLSearchParams({interp: box.dataset.interp, text: text});
        try{
          const r = await fetch('?p=interp_text', {method:'POST', body:body,
            headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}});
          const data = await r.json();
          if (!data.ok) throw new Error(data.error || 'не удалось сохранить');
          box.classList.remove('fullediting');
          box.dataset.src = data.src;
          box.innerHTML = data.html;
          pushHist(box, data.src);
        }catch(err){
          // Текст остаётся в поле: правка целого отчёта — работа не на минуту,
          // и терять её из-за сбоя сети нельзя.
          alert('Текст не сохранён: ' + err.message);
        }
      }

      /* Откат/возврат — просто переигрывают saveFull() на нужной версии из
         истории, без открытия редактора. */
      async function revertTo(box, text){
        if (box.classList.contains('fullediting')) return;
        const rendered = box.innerHTML;
        const body = new URLSearchParams({interp: box.dataset.interp, text: text});
        try{
          const r = await fetch('?p=interp_text', {method:'POST', body:body,
            headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}});
          const data = await r.json();
          if (!data.ok) throw new Error(data.error || 'не удалось сохранить');
          box.dataset.src = data.src;
          box.innerHTML = data.html;
          updateHistButtons(box);
        }catch(err){
          box.innerHTML = rendered;
          alert('Не удалось откатить правку: ' + err.message);
        }
      }

      document.querySelectorAll('.interp.editable').forEach(box=>{
        histOf(box);
        box.addEventListener('dblclick', e=>{
          if (box.classList.contains('fullediting')) return;
          openFull(box, clickOffset(box.dataset.src || '', e.clientX, e.clientY));
        });
      });

      document.querySelectorAll('[data-editall]').forEach(btn=>{
        btn.addEventListener('click', e=>{
          e.preventDefault();
          const box = document.querySelector('.interp.editable[data-interp="' + btn.dataset.editall + '"]');
          if (box) openFull(box);
        });
      });

      document.querySelectorAll('[data-undo],[data-redo]').forEach(btn=>{
        btn.addEventListener('click', e=>{
          e.preventDefault();
          if (btn.classList.contains('off')) return;
          const id = btn.dataset.undo || btn.dataset.redo;
          const box = document.querySelector('.interp.editable[data-interp="' + id + '"]');
          if (!box) return;
          const h = histOf(box);
          if (btn.dataset.undo && h.pos > 0) h.pos--;
          else if (btn.dataset.redo && h.pos < h.stack.length - 1) h.pos++;
          else return;
          updateHistButtons(box);
          revertTo(box, h.stack[h.pos]);
        });
      });
    })();
    </script>
    <?php
    layout('Результат', ob_get_clean(), $h, ['wide' => $split]);
}

function view_report(array $cfg): void {
    // Удалённый отчёт (или отчёт удалённого анализа) не печатается: сначала его
    // возвращают из «Удалённых» — иначе по старой ссылке уходил бы в PDF текст,
    // который оператор считает выброшенным.
    $it = interp_row((int)($_GET['interp'] ?? 0));
    if (!$it) { http_response_code(404); echo 'Интерпретация не найдена или удалена (см. «Удалённые»).'; return; }
    $p = profile_row((int)$it['profile_id']);
    $prof = profile_decode($p);
    $phys = Phys::decode($p['phys_json'] ?? null, count($prof['scores']));
    $html = Report::html($prof, Report::interpToHtml($it['content']), $cfg, [
        'phys' => $phys, 'autoprint' => !empty($_GET['print']),
        'conf' => interp_math_conf($it),
    ]);
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
}

/**
 * КОРЗИНА: недавно удалённые анализы и интерпретации. Открывается серой ссылкой
 * под списком отчётов. Всё, что здесь лежит, можно вернуть одним кликом; через
 * Trash::KEEP_DAYS дней оно исчезает само (или раньше — «удалить навсегда»).
 */
function view_trash(array $cfg, callable $h): void {
    Trash::purgeExpired();
    // ?id=N — пришли по ссылке со страницы результата: показываем удалённые
    // отчёты этого анализа, иначе счётчик в ссылке («Удалённые (1)») не сходился
    // бы со списком. «Все удалённые» — та же страница без id.
    $back = (int)($_GET['id'] ?? 0);
    $profiles = $back ? [] : Trash::profiles();
    $interps = Trash::interps($back);
    $others = $back ? max(0, Trash::counts()['total'] - count($interps)) : 0;
    ob_start(); ?>
    <div class="row"><h1>Удалённые<?= $back ? ' — этот анализ' : '' ?></h1>
      <?php if (!$back && ($profiles || $interps)): ?>
        <a class="btn sm danger" href="?p=trash_purge&kind=all"
           onclick="return confirm('Очистить корзину полностью? Восстановить будет нельзя.')">Очистить корзину</a>
      <?php endif; ?>
    </div>
    <p class="muted">Удалённое хранится <?= Trash::KEEP_DAYS ?> дней, потом исчезает насовсем.
       Анализ возвращается вместе со своими интерпретациями.
       <?php if ($back): ?><a href="?p=result&id=<?= $back ?>">← к анализу</a>
         <?php if ($others): ?>· <a href="?p=trash">все удалённые (<?= (int)$others ?> ещё)</a><?php endif; ?>
       <?php endif; ?></p>

    <?php if (!$profiles && !$interps): ?>
      <div class="card"><p class="muted">Корзина пуста.
        <?php if ($back && $others): ?><a href="?p=trash">Показать все удалённые →</a><?php endif; ?></p></div>
    <?php endif; ?>

    <?php if ($interps): ?>
      <h2>Интерпретации</h2>
      <table class="grid"><tr><th>Анализ</th><th>Отчёт</th><th>Удалён</th><th>Исчезнет через</th><th></th></tr>
      <?php foreach ($interps as $it): ?>
        <tr>
          <td><?= $h($it['profile_name']) ?> <span class="muted"><?= $h($it['methodic']) ?></span></td>
          <td>промпт v<?= $h($it['version_no']) ?>, <?= $h($it['model_id']) ?>
              <span class="muted">от <?= $h($it['created_at']) ?></span></td>
          <td><?= $h($it['deleted_at']) ?></td>
          <td><?= (int)Trash::daysLeft($it['deleted_at']) ?> дн.</td>
          <td><a class="btn sm" href="?p=trash_restore&kind=interp&id=<?= (int)$it['id'] ?><?= $back ? '&back=' . $back : '' ?>">↩ Восстановить</a>
              <a class="btn sm danger" href="?p=trash_purge&kind=interp&id=<?= (int)$it['id'] ?><?= $back ? '&back=' . $back : '' ?>"
                 onclick="return confirm('Удалить интерпретацию навсегда?')">🗑</a></td>
        </tr>
      <?php endforeach; ?></table>
    <?php endif; ?>

    <?php if ($profiles): ?>
      <h2>Анализы</h2>
      <table class="grid"><tr><th>ФИО</th><th>Методика</th><th>Интерпретаций</th><th>Удалён</th><th>Исчезнет через</th><th></th></tr>
      <?php foreach ($profiles as $p): ?>
        <tr>
          <td><?= $h($p['name']) ?></td><td><?= $h($p['methodic']) ?></td>
          <td><?= (int)$p['interp_count'] ?></td>
          <td><?= $h($p['deleted_at']) ?></td>
          <td><?= (int)Trash::daysLeft($p['deleted_at']) ?> дн.</td>
          <td><a class="btn sm" href="?p=trash_restore&kind=profile&id=<?= (int)$p['id'] ?>">↩ Восстановить</a>
              <a class="btn sm danger" href="?p=trash_purge&kind=profile&id=<?= (int)$p['id'] ?>"
                 onclick="return confirm('Удалить анализ навсегда — вместе со всеми его интерпретациями?')">🗑</a></td>
        </tr>
      <?php endforeach; ?></table>
    <?php endif; ?>
    <?php
    layout('Удалённые', ob_get_clean(), $h);
}

function act_trash_restore(array $cfg): void {
    $id = (int)($_GET['id'] ?? 0);
    $back = (int)($_GET['back'] ?? 0);
    if ((string)($_GET['kind'] ?? '') === 'profile') {
        Trash::restoreProfile($id);
        redirect('?p=result&id=' . $id . '&ok=' . urlencode('Анализ восстановлен.'));
    }
    $it = Db::one('SELECT profile_id FROM interpretations WHERE id=?', [$id]);
    Trash::restoreInterp($id);
    $pid = (int)($it['profile_id'] ?? $back);
    redirect($pid ? '?p=result&id=' . $pid . '&ok=' . urlencode('Интерпретация восстановлена.')
                  : '?p=trash&ok=' . urlencode('Интерпретация восстановлена.'));
}

function act_trash_purge(array $cfg): void {
    $kind = (string)($_GET['kind'] ?? '');
    $id = (int)($_GET['id'] ?? 0);
    $back = (int)($_GET['back'] ?? 0);
    if ($kind === 'all') { Trash::purgeAll(); $msg = 'Корзина очищена.'; }
    elseif ($kind === 'profile') { Trash::purgeProfile($id); $msg = 'Анализ удалён навсегда.'; }
    else { Trash::purgeInterp($id); $msg = 'Интерпретация удалена навсегда.'; }
    redirect('?p=trash' . ($back ? '&id=' . $back : '') . '&ok=' . urlencode($msg));
}

function view_prompts(array $cfg, callable $h): void {
    ob_start(); ?>
    <h1>Промпты</h1>
    <?php foreach (Prompts::allFamilies() as $f):
        // Второй слой — не методика, а обработка готового текста: у него другая
        // подпись кнопки и пояснение, иначе «Удалить методику» вводит в
        // заблуждение (методик у него нет).
        $isStyle = (string)$f['test_key'] === Interpret::STYLE_KEY; ?>
      <div class="card">
        <div class="row"><h2><?= $h($f['name']) ?> <span class="muted">[<?= $h($f['test_key']) ?>]</span></h2>
          <a class="btn sm danger" href="?p=prompt_family_delete&id=<?= (int)$f['id'] ?>"
             onclick="return confirm('Удалить всё семейство промптов «<?= $h($f['name']) ?>» вместе со всеми версиями и интерпретациями? При следующем запуске оно пересоздастся с чистой версией v1.')">🗑 Удалить <?= $isStyle ? 'слой' : 'методику' ?></a>
        </div>
        <?php if ($isStyle): ?>
          <p class="muted">Второй слой: правит язык уже готового отчёта — короче и живее, факты не трогает.
             Применяется после интерпретации любой методики. Если снять активную версию (удалить её),
             слой отключается и клиент получает текст первого слоя как есть.</p>
        <?php endif; ?>
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
        $prof = profile_from_text((string)$_POST['pasted'], (string)($_POST['test_key'] ?? ''));
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
    // Правку ведёт Phys: распознанные p / «Балл» по остальным осям сохраняются,
    // а флажок достоверности не обнуляет p, а задаёт его. СМК приходит из формы
    // целиком (пустая строка = «не распознан»), поэтому передаём его отдельно.
    $parsed = Phys::fromManual((array)($_POST['zna'] ?? []), $count, (array)($_POST['sig'] ?? []), $old,
        isset($_POST['smk']) ? (array)$_POST['smk'] : null);
    Db::q('UPDATE profiles SET phys_json=?, status=? WHERE id=?',
        [json_encode($parsed, JSON_UNESCAPED_UNICODE), 'ready', $id]);
    redirect('?p=result&id=' . $id . '&ok=' . urlencode('Физиология сохранена.'));
}

/**
 * Пороги матрицы и мультипликатор размера кружков — из перетаскивания пунктира
 * или из полей под матрицей. Значения общие для сервиса (та же таблица
 * settings, что и у /setup.php): пороги решают, в какой раздел отчёта попадёт
 * шкала, поэтому храниться «по профилю» они не могут — иначе два отчёта одного
 * клиента считались бы по разным правилам.
 */
function act_matrix_conf(array $cfg): void {
    header('Content-Type: application/json; charset=utf-8');
    $store = new SettingsStore($cfg['DB_PATH']);
    $clamp = static function (string $key, float $min, float $max): ?float {
        if (!isset($_POST[$key]) || !is_numeric($_POST[$key])) return null;
        return max($min, min($max, round((float)$_POST[$key], 1)));
    };
    $low  = $clamp('low', 1.0, 98.0);
    $high = $clamp('high', 2.0, 99.0);
    if ($low !== null && $high !== null && $high <= $low) $high = min(99.0, $low + 1.0);
    $map = [
        'MATRIX_LOW_PCT' => $low,
        'MATRIX_HIGH_PCT' => $high,
        'MATRIX_MID_BAND_PCT' => $clamp('band', 0.0, 50.0),
        'MATRIX_SIZE_POWER' => $clamp('power', 0.2, 6.0),
    ];
    $saved = [];
    try {
        foreach ($map as $k => $v) {
            if ($v === null) continue;
            $store->setSetting($k, Metrics::num($v));
            $saved[$k] = Metrics::num($v);
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        return;
    }
    echo json_encode(['ok' => true, 'saved' => $saved], JSON_UNESCAPED_UNICODE);
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
    // Оператор может выбрать модель прямо под кнопкой и переделать отчёт на
    // другой нейросети — новая интерпретация добавится в список отдельной
    // строкой со своей моделью (#3). По умолчанию берётся модель версии промпта.
    $pickedId = trim((string)($_POST['model_id'] ?? ''));
    $pickedRow = $pickedId !== '' ? LLM::findModel($pickedId) : null;
    $effModel = $pickedRow ? (string)$pickedRow['id'] : (string)($version['model_id'] ?? '');
    $effProvider = $pickedRow ? (string)($pickedRow['provider'] ?? '') : (string)($version['provider'] ?? '');
    // Нейросеть получает РАСЧЁТ (Metrics), а не сырой текст OCR: уровни,
    // достоверность и разделы считает код, модель только пишет текст.
    $phys = Phys::decode($p['phys_json'] ?? null, count($prof['scores']));
    // СЛОЙ МАТЕМАТИКИ считаем ЗДЕСЬ и сохраняем вместе с текстом: пороги матрицы
    // оператор двигает мышью, и модель обязана получить именно те значения,
    // которые он выставил к этому моменту, а отчёт — собраться по ним же (#14).
    $metrics = Metrics::build($prof, $phys);
    // Ошибку нейросети показываем на странице результата, а не голой 500-й.
    try {
        $res = Interpret::run($prof, $phys, $version, Interpret::styleVersion(),
            $pickedRow ? $effModel : null, $pickedRow ? $effProvider : null);
    } catch (Throwable $e) {
        redirect('?p=result&id=' . $id . '&err=' . urlencode('Нейросеть недоступна, интерпретация не создана: ' . $e->getMessage()));
    }
    Interpret::save($id, (int)$version['id'], $effModel, $res['text'], $metrics, $res['style_version_id']);
    // Сбой второго слоя не прячем: текст сохранён, но он без литературной правки.
    $msg = 'Интерпретация готова.'
         . ($res['style_error'] !== null ? ' Литературный слой не применён (' . $res['style_error'] . ').' : '');
    redirect('?p=result&id=' . $id . '&' . ($res['style_error'] !== null ? 'err' : 'ok') . '=' . urlencode($msg));
}

/**
 * Готовый отчёт, вставленный руками. Оператор копирует промпт с данными,
 * получает текст в чужом чате и возвращается вставить его в окно, которое
 * открылось сразу по кнопке копирования. Сохраняем так же, как интерпретацию
 * нейросети: та же версия промпта и тот же снимок слоя математики — иначе PDF
 * и письмо считались бы по порогам, отличным от тех, по которым считали текст.
 */
function act_interp_paste(array $cfg): void {
    $id = (int)($_POST['id'] ?? 0);
    $p = profile_row($id);
    $prof = profile_decode($p);
    // Версия — та же, что выбрана в форме интерпретации: вставленный текст
    // получен по ЕЁ промпту, и в списке отчётов он должен стоять с ней.
    $vid = (int)($_POST['version_id'] ?? 0);
    $version = $vid ? Prompts::version($vid) : null;
    if (!$version) $version = Prompts::activeVersion($prof['test_key']);
    if (!$version) {
        redirect('?p=result&id=' . $id . '&err=' . urlencode('Для этой методики не выбран промпт (страница «Промпты»).'));
    }
    // В базе — Markdown, как и у текста нейросети: правка абзацев, PDF и письмо
    // читают его без оговорок.
    $text = Report::normalizeForEdit((string)($_POST['text'] ?? ''));
    if ($text === '') {
        redirect('?p=result&id=' . $id . '&err=' . urlencode('Текст отчёта пуст — вставьте его в окно и сохраните.'));
    }
    $phys = Phys::decode($p['phys_json'] ?? null, count($prof['scores']));
    // Модель не выдумываем: сервис не знает, в каком чате оператор получил текст.
    Interpret::save($id, (int)$version['id'], Interpret::MANUAL_MODEL, $text, Metrics::build($prof, $phys));
    redirect('?p=result&id=' . $id . '&ok=' . urlencode('Отчёт сохранён — вставлен вручную.'));
}

function act_email(array $cfg): void {
    require_once __DIR__ . '/../lib/mailer.php';
    $it = interp_row((int)($_GET['interp'] ?? 0));
    if (!$it) throw new RuntimeException('Интерпретация не найдена или удалена (см. «Удалённые»).');
    $pid = (int)$it['profile_id'];
    $p = profile_row($pid);
    $prof = profile_decode($p);
    $phys = Phys::decode($p['phys_json'] ?? null, count($prof['scores']));
    $html = Report::html($prof, Report::interpToHtml($it['content']), $cfg,
        ['phys' => $phys, 'conf' => interp_math_conf($it)]);
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
/**
 * Правка ВСЕГО текста интерпретации сразу — единственный редактор страницы
 * результата (#6): построчной правки по абзацам больше нет, двойной клик по
 * любому месту текста и откат/возврат (↶/↷) идут через этот же маршрут.
 * Оператор получает Markdown из базы, возвращает Markdown же — сервис только
 * приводит его к каноническому виду (Report::normalizeForEdit). Ответ — заново
 * собранный HTML и сохранённый текст: страница обновляет и то, и другое без
 * перезагрузки.
 */
function act_interp_text(array $cfg): void {
    header('Content-Type: application/json; charset=utf-8');
    $json = static function (array $data, int $code = 200): void {
        http_response_code($code);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    };
    $id = (int)($_POST['interp'] ?? 0);
    $it = Db::one('SELECT * FROM interpretations WHERE id=?', [$id]);
    if (!$it) { $json(['ok' => false, 'error' => 'Интерпретация не найдена'], 404); return; }
    $text = Report::normalizeForEdit((string)($_POST['text'] ?? ''));
    // Пустой текст — не правка, а потеря отчёта: для удаления есть корзина.
    if ($text === '') { $json(['ok' => false, 'error' => 'Текст пуст — используйте 🗑, если отчёт не нужен'], 409); return; }
    Db::q("UPDATE interpretations SET content=?, edited_at=datetime('now') WHERE id=?", [$text, $id]);
    $json(['ok' => true, 'src' => $text, 'html' => Report::interpToHtml($text)]);
}

/**
 * То, что уходит в нейросеть, одним текстом — для кнопки «Скопировать промпт и
 * данные» рядом с кнопкой интерпретации. Ничего не сохраняет и никуда не
 * обращается: собирает те же строки, что и Interpret::run() (#1).
 */
function view_interp_source(array $cfg): void {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $p = profile_row((int)($_GET['id'] ?? 0));
        $prof = profile_decode($p);
        // Версия и модель — те же, что выбраны в форме интерпретации, иначе
        // скопированное разошлось бы с тем, что сервис отправит на самом деле.
        $vid = (int)($_GET['version_id'] ?? 0);
        $version = $vid ? Prompts::version($vid) : null;
        if (!$version) $version = Prompts::activeVersion($prof['test_key']);
        if (!$version) {
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => 'Для этой методики не выбран промпт (страница «Промпты»).'], JSON_UNESCAPED_UNICODE);
            return;
        }
        $picked = trim((string)($_GET['model_id'] ?? ''));
        $pickedRow = $picked !== '' ? LLM::findModel($picked) : null;
        $phys = Phys::decode($p['phys_json'] ?? null, count($prof['scores']));
        $text = Interpret::sourceDump($prof, $phys, $version, Interpret::styleVersion(),
            $pickedRow ? (string)$pickedRow['id'] : null);
        echo json_encode(['ok' => true, 'text' => $text], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
}

/** Удаление интерпретации — мягкое: месяц лежит в «Удалённых» (Trash). */
function act_interp_delete(array $cfg): void {
    $id = (int)($_GET['interp'] ?? 0);
    $it = Db::one('SELECT profile_id FROM interpretations WHERE id=?', [$id]);
    if (!$it) throw new RuntimeException('Интерпретация не найдена');
    Trash::deleteInterp($id);
    redirect('?p=result&id=' . (int)$it['profile_id'] . '&ok='
        . urlencode('Интерпретация удалена — её можно вернуть из «Удалённых» ещё ' . Trash::KEEP_DAYS . ' дней.'));
}

/**
 * Удаление анализа (профиля) целиком — тоже мягкое. Интерпретации помечать не
 * нужно: они скрыты вместе с анализом и вернутся вместе с ним в том же составе.
 */
function act_profile_delete(array $cfg): void {
    $id = (int)($_GET['id'] ?? 0);
    profile_row($id); // 404, если профиля нет
    Trash::deleteProfile($id);
    redirect('?p=dashboard&ok='
        . urlencode('Анализ удалён — его можно вернуть из «Удалённых» ещё ' . Trash::KEEP_DAYS . ' дней.'));
}

/** Удаление семейства промптов целиком (со всеми версиями и интерпретациями). */
function act_prompt_family_delete(array $cfg): void {
    $famId = (int)($_GET['id'] ?? 0);
    $res = Prompts::deleteFamily($famId);
    redirect('?p=prompts' . ($res['ok'] ? '&ok=' . urlencode('Семейство промптов удалено.') : '&err=' . urlencode($res['error'])));
}

/* ─────────────────────────── Helpers ─────────────────────────── */

/**
 * Настройки слоя математики, по которым сделана эта интерпретация. Пороги
 * матрицы — общая настройка сервиса, и оператор двигает их мышью; без снимка
 * PDF, собранный после сдвига, разложил бы шкалы по другим разделам, чем текст,
 * который читает клиент (#14). У интерпретаций, сделанных до появления снимка,
 * его нет — там честно считаем по текущим настройкам.
 */
function interp_math_conf(array $interp): array {
    $raw = $interp['metrics_json'] ?? null;
    if (!is_string($raw) || trim($raw) === '') return [];
    $snapshot = json_decode($raw, true);
    return is_array($snapshot) ? Metrics::confFromSnapshot($snapshot) : [];
}

/** Живая интерпретация: не в корзине и её анализ тоже не в корзине. */
function interp_row(int $id): ?array {
    return Db::one('SELECT i.* FROM interpretations i JOIN profiles p ON p.id=i.profile_id
                    WHERE i.id=? AND i.deleted_at IS NULL AND p.deleted_at IS NULL', [$id]);
}

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

/**
 * Список методик для выбора при загрузке — по СЕМЕЙСТВАМ ПРОМПТОВ, а не по
 * тому, что уже встречалось в базе. Раньше выпадающий список собирался из
 * `SELECT DISTINCT methodic`, и одна методика попадала в него несколькими
 * строками («ИЖС», «LSI», «Индекс жизненного стиля»), потому что в выгрузках
 * Эгоскопа она названа по-разному. Теперь пункт списка — методика, для которой
 * есть промпт, а её название каноническое (Profile::TEST_TYPES).
 *
 * @return array<string,string> test_key => название методики
 */
function methodic_options(): array {
    $labels = [];
    foreach (Profile::TEST_TYPES as $info) $labels[$info['key']] = $info['label'];
    $out = [];
    foreach (Prompts::allFamilies() as $fam) {
        $key = (string)$fam['test_key'];
        // Семейство второго слоя — не методика: у него нет ни тестов, ни
        // профилей, и в списке «какой тест загружаем» ему делать нечего.
        if ($key === '' || $key === Interpret::STYLE_KEY) continue;
        $out[$key] = $labels[$key] ?? (string)$fam['name'];
    }
    return $out;
}

/**
 * Build a profile from pasted CSV/TSV text.
 *
 * @param string $testKey ключ методики из methodic_options() (пусто — не выбрана)
 */
function profile_from_text(string $text, string $testKey): array {
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
    // Название методики берём каноническое — по выбранному ключу, а не из
    // произвольного текста: так один и тот же тест всегда назван одинаково.
    $options = methodic_options();
    $key = isset($options[$testKey]) ? $testKey : '';
    return ['name' => 'Без имени', 'age' => '', 'sex' => '', 'date' => date('d m Y H:i'),
            'methodic' => $options[$key] ?? 'Не указана', 'test_key' => $key, 'scores' => $scores,
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
      <p class="muted">«Знач.», достоверность и СМК распознаны со скриншота; уровень, положение
      относительно медианы и раздел отчёта считает сервис (не нейросеть).
      Поправьте значения при необходимости и сохраните. Столбец «СМК» — соотношение
      параметров как на скриншоте (например <code>Z*&gt;X&gt;Y</code>): от него зависит цвет
      кружка на матрице, и он используется независимо от достоверности. Шкала физиологии на
      диаграмме: ±<?= $h(Metrics::num($m['phys_scale'])) ?>.</p>
      <?php if ($image !== ''): ?>
        <div class="shot">
          <p class="muted">Исходный скриншот значимости — сверьте цифры ниже с положением графиков:</p>
          <img src="<?= $h($image) ?>" alt="Скриншот таблицы значимости">
        </div>
      <?php endif; ?>
      <?php foreach ($m['warnings'] as $w): ?><div class="msg warn">⚠ <?= $h($w) ?></div><?php endforeach; ?>
      <?php if (!empty($m['first_step'])): ?>
        <p class="muted">Отчёт закончится одним конкретным действием. Шкалу для него выбирает сервис
        (не нейросеть): <b><?= $h($m['first_step']['label']) ?></b> — <?= $h($m['first_step']['basis_label']) ?>.
        Поправите «Знач.» или достоверность — опора пересчитается.</p>
      <?php endif; ?>
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
            <td><input class="smk" type="text" name="smk[<?= $i ?>]" value="<?= $h($a['smk']) ?>" placeholder="—"
                       title="Соотношение параметров, как в столбце «СМК»: Z*&gt;X&gt;Y"></td>
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

/**
 * Каркас страницы. $opts['wide'] расширяет колонку контента: это нужно только
 * странице результата в двухколоночном режиме (графики слева, интерпретация
 * справа) — на 1000 px такой режим смысла не имеет.
 */
function layout(string $title, string $body, callable $h, array $opts = []): void {
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
  .modelpick{max-width:560px;margin:10px 0 0}
  .chart{text-align:center} .chart svg{max-width:100%;height:auto}
  .legend{color:#6b7682;font-size:11px;line-height:1.6}
  .paste{border:2px dashed #cfd6dd;border-radius:6px;padding:24px;text-align:center;color:#8a949d;cursor:text}
  .shot img{max-width:100%;border:1px solid #d7dde3;border-radius:6px;margin:4px 0 10px}
  .interp h1,.interp h2,.interp h3,.interp h4,.interp h5,.interp h6{color:#b3203b}
  .interp h4,.interp h5,.interp h6{font-size:13px;margin:12px 0 4px}
  /* Один общий редактор на весь текст (#6): весь блок — цель двойного клика,
     без разбивки на отдельно кликабельные абзацы. */
  .interp.editable{cursor:text;border-radius:5px;padding:1px 6px;margin:0 -6px}
  .interp.editable:hover{background:#fbfcfd}
  /* Правка всего текста разом: поле во всю карточку + панель подсказки. */
  textarea.fulledit{width:100%;font:inherit;font-family:Verdana,Geneva,sans-serif;border:1px solid #b3203b;
                    border-radius:5px;resize:vertical;padding:10px;background:#fff;line-height:1.5}
  .editbar{margin-top:8px;align-items:flex-start} .editbar .btn{margin-left:6px}
  /* Окно «скопировал промпт → вставил готовый отчёт»: поле вставки сверху,
     копия промпта — сложенной внизу (нужна, только когда буфер недоступен). */
  .np-dialog{width:min(900px,94vw);max-height:90vh;border:1px solid #e0e5ea;border-radius:8px;
             padding:16px;background:#fff;color:#2a3138;font:inherit;overflow:auto}
  .np-dialog::backdrop{background:rgba(42,49,56,.45)}
  .np-dialog h2{font-size:15px}
  #pastetext{font-family:Verdana,Geneva,sans-serif;border:1px solid #b3203b;border-radius:5px;
             padding:10px;resize:vertical;line-height:1.5}
  .np-dialog .editbar .btn{white-space:nowrap}
  .srcbox{margin-top:12px}
  .srcbox summary{cursor:pointer;font-weight:bold;color:#b3203b}
  .srcbox textarea{margin-top:8px;font-family:monospace;font-size:11px;white-space:pre;background:#fbfcfd}
  /* Корзина: ссылка нарочно неброская — она нужна редко. */
  .trashlink{margin:10px 0 0;text-align:right}
  .trashlink a{color:#8a949d;font-size:12px;text-decoration:none;border-bottom:1px dotted #c8d0d7}
  .trashlink a:hover{color:#b3203b;border-bottom-color:#b3203b}
  .np-progress .bar{height:6px;background:#eef1f4;border-radius:3px;overflow:hidden;margin:8px 0 4px}
  .np-progress .bar i{display:block;height:100%;width:40%;background:#b3203b;border-radius:3px;
                      animation:np-run 1.4s ease-in-out infinite}
  @keyframes np-run{0%{margin-left:-40%}100%{margin-left:100%}}
  .btn.off{opacity:.5;pointer-events:none}
  .msg.bad{background:#fdecef;border:1px solid #e0a6b0;padding:10px;border-radius:6px;margin:10px 0}
  .msg.warn{background:#fff8e6;border:1px solid #e8d29a;padding:10px;border-radius:6px;margin:10px 0}
  .msg.ok{background:#eaf7ee;border:1px solid #a8d5b5;padding:10px;border-radius:6px;margin:10px 0}
  tr.sig td{font-weight:bold}
  input.num{width:80px;text-align:center} input.smk{width:96px;text-align:center}
  @media(max-width:760px){.grid2{grid-template-columns:1fr}}
  /* Двухколоночный результат: слева графики и правка физиологии, справа —
     интерпретации. Каждая колонка листается отдельно, чтобы оператор правил
     текст, не теряя из вида диаграмму и матрицу. Узкий экран — обычная лента:
     класс .on добавляется только когда интерпретация уже есть. */
  .split-col{min-width:0}
  @media(min-width:1240px){
    main.wide{max-width:1720px}
    .split.on{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:18px;align-items:start}
    /* sticky + своя прокрутка: сколько бы ни занимали шапка и баннеры, после
       короткой прокрутки страницы обе колонки прилипают и занимают весь экран. */
    .split.on>.split-col{position:sticky;top:12px;max-height:calc(100vh - 24px);overflow-y:auto;padding-right:6px}
    .split.on>.split-col>*:first-child{margin-top:0}
    .split.on>.split-col::-webkit-scrollbar{width:9px}
    .split.on>.split-col::-webkit-scrollbar-thumb{background:#d7dde3;border-radius:5px}
  }
</style></head><body>
<header><a href="/" style="color:#b3203b"><b>НейроПро</b></a>
  <a href="?p=dashboard">Профили</a>
  <a href="?p=upload">Загрузить</a>
  <a href="?p=prompts">Промпты</a>
  <a href="/setup.php">Настройки</a>
</header>
<main<?= !empty($opts['wide']) ? ' class="wide"' : '' ?>>
  <?php foreach ($warnings as $w): ?><div class="msg warn">⚠ <?= $h($w) ?> <a href="/setup.php">Настройки →</a></div><?php endforeach; ?>
  <?php if ($err): ?><div class="msg bad"><?= $h($err) ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="msg ok"><?= $h($ok) ?></div><?php endif; ?>
  <?= $body ?>
</main></body></html>
<?php
}
