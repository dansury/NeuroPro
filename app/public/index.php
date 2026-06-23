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

$cfg = np_boot();
$h = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$page = $_GET['p'] ?? 'dashboard';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($page) {
        case 'upload':        $method === 'POST' ? act_upload($cfg) : view_upload($cfg, $h); break;
        case 'profile':       view_profile($cfg, $h); break;
        case 'screenshot':    act_screenshot($cfg); break;
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
          <div id="paste" class="paste" tabindex="0">Кликните сюда и нажмите Ctrl+V, чтобы вставить скриншот</div>
          <input type="hidden" name="pasted_image" id="pasted_image">
          <img id="preview" style="max-width:100%;display:none;margin:8px 0;border:1px solid #ccc">
          <label><span>…или файл</span><input type="file" name="screenshot" accept="image/*"></label>
          <button class="btn" type="submit">Распознать и продолжить →</button>
        </form>
      </div>
    </div>
    <script>
    (function(){
      const z=document.getElementById('paste'), inp=document.getElementById('pasted_image'), pv=document.getElementById('preview');
      window.addEventListener('paste', e=>{
        for(const it of (e.clipboardData||{}).items||[]){
          if(it.type.indexOf('image')===0){
            const r=new FileReader(); r.onload=()=>{inp.value=r.result; pv.src=r.result; pv.style.display='block'; z.textContent='Скриншот вставлен ✓';};
            r.readAsDataURL(it.getAsFile());
          }
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
    $phys = $p['phys_json'] ? json_decode($p['phys_json'], true) : null;
    $svg = cognitive_svg($prof, $phys);
    $interps = Db::all('SELECT i.*, v.version_no FROM interpretations i JOIN prompt_versions v ON v.id=i.prompt_version_id WHERE i.profile_id=? ORDER BY i.created_at DESC', [$p['id']]);
    $active = Prompts::activeVersion($prof['test_key']);
    ob_start(); ?>
    <h1><?= $h($prof['name']) ?> <span class="muted">— <?= $h($prof['methodic']) ?></span></h1>
    <div class="chart card"><?= $svg ?></div>
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
    $phys = $p['phys_json'] ? json_decode($p['phys_json'], true) : null;
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
    $models = array_filter($cfg['AVAILABLE_MODELS'] ?? [], fn ($m) => empty($m['ocr_only']));
    ob_start(); ?>
    <h1><?= $h($fam['name']) ?></h1>
    <p class="muted">Сохранение создаёт новую версию (история сохраняется). <?= $v ? 'Открыта v'.$h($v['version_no']) : '' ?></p>
    <form method="post" action="?p=prompt_edit" class="card">
      <input type="hidden" name="id" value="<?= $famId ?>">
      <label><span>Нейросеть для интерпретации</span>
        <select name="model_id"><?php foreach ($models as $m): ?>
          <option value="<?= $h($m['id']) ?>" data-prov="<?= $h($m['provider']) ?>" <?= ($v['model_id'] ?? '')===$m['id']?'selected':'' ?>><?= $h($m['label']) ?> — <?= $h($m['provider']) ?></option>
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
    $ocrText = '';
    if ($bytes !== null) {
        try { $ocrText = LLM::ocrImage($bytes, $mime); }
        catch (Throwable $e) { $ocrText = ''; /* keep going; phys optional */ }
    }
    $labels = array_map(fn ($s) => $s['label'], $prof['scores']);
    $parsed = Phys::parse($ocrText, $labels);
    Db::q('UPDATE profiles SET phys_ocr_text=?, phys_json=?, status=? WHERE id=?',
        [$ocrText, json_encode($parsed['aligned']), 'ready', $id]);
    redirect('?p=result&id=' . $id);
}

function act_interpret(array $cfg): void {
    $id = (int)($_POST['id'] ?? 0);
    $p = profile_row($id);
    $prof = profile_decode($p);
    $version = Prompts::activeVersion($prof['test_key']);
    if (!$version) throw new RuntimeException('Для этой методики не выбран активный промпт');
    $content = Interpret::run($prof, (string)$p['phys_ocr_text'], $version);
    Interpret::save($id, (int)$version['id'], $version['model_id'], $content);
    redirect('?p=result&id=' . $id);
}

function act_email(array $cfg): void {
    require_once __DIR__ . '/../lib/mailer.php';
    $it = Db::one('SELECT * FROM interpretations WHERE id=?', [(int)($_GET['interp'] ?? 0)]);
    if (!$it) throw new RuntimeException('Интерпретация не найдена');
    $p = profile_row((int)$it['profile_id']);
    $prof = profile_decode($p);
    $phys = $p['phys_json'] ? json_decode($p['phys_json'], true) : null;
    $html = Report::html($prof, Report::mdToHtml($it['content']), $cfg, ['phys' => $phys]);
    $to = $cfg['ADMIN_EMAIL'] ?? '';
    if ($to === '') throw new RuntimeException('Не задан адрес получателя (ADMIN_EMAIL в настройках)');
    Mailer::sendCustom($cfg, $to, 'Результаты исследования НейроПро', $html, strip_tags($it['content']));
    redirect('?p=result&id=' . (int)$it['profile_id']);
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
    return [
        'name' => $p['name'], 'age' => $p['age'], 'sex' => $p['sex'], 'date' => $p['test_date'],
        'methodic' => $p['methodic'], 'test_key' => $p['test_key'],
        'scores' => json_decode($p['scores_json'], true) ?: [],
        'score_max' => (function () use ($p) {
            $s = json_decode($p['scores_json'], true) ?: [];
            return ($p['test_key'] === 'lsi') ? 100 : 10;
        })(),
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
            'score_max' => $key === 'lsi' ? 100 : 10];
}

function cognitive_svg(array $prof, ?array $phys = null): string {
    $labels = array_map(fn ($s) => Profile::shortLabel($s['label']), $prof['scores']);
    $cog = array_map(fn ($s) => (float) $s['score'], $prof['scores']);
    return Chart::svg($labels, $cog, (int)($prof['score_max'] ?? 10), $phys, ['title' => 'Структура мотивации', 'size' => 560]);
}

function redirect(string $to): void { header('Location: ' . $to); exit; }

function layout(string $title, string $body, callable $h): void {
    $err = $_GET['err'] ?? '';
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
  @media(max-width:760px){.grid2{grid-template-columns:1fr}}
</style></head><body>
<header><b>НейроПро</b>
  <a href="?p=dashboard">Профили</a>
  <a href="?p=upload">Загрузить</a>
  <a href="?p=prompts">Промпты</a>
  <a href="setup.php">Настройки</a>
</header>
<main>
  <?php if ($err): ?><div class="msg bad"><?= $h($err) ?></div><?php endif; ?>
  <?= $body ?>
</main></body></html>
<?php
}
