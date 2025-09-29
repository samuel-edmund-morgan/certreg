<?php
require_once __DIR__.'/auth.php';
require_login();
require_once __DIR__.'/db.php';
$cfg = require __DIR__.'/config.php';
$isAdminPage = true; // reuse admin layout styles
$csrf = csrf_token();
$isAdmin = is_admin();
$sessionOrg = current_org_id();

$id = (int)($_GET['id'] ?? 0);
if($id<=0){ header('Location: settings.php?tab=templates'); exit; }

function col_exists(PDO $pdo,string $t,string $c):bool{ try{$s=$pdo->prepare('SHOW COLUMNS FROM `'.$t.'` LIKE ?');$s->execute([$c]);return (bool)$s->fetch();}catch(Throwable $e){return false;} }

$row=null; $err=null;
try {
  $st=$pdo->prepare('SELECT * FROM templates WHERE id=? LIMIT 1');
  $st->execute([$id]);
  $row=$st->fetch(PDO::FETCH_ASSOC);
  if(!$row){ header('Location: settings.php?tab=templates&msg=nf'); exit; }
} catch(Throwable $e){ $err='db'; }

// Access control for operator scope
if(!$isAdmin){
  if($sessionOrg===null || (int)$row['org_id'] !== (int)$sessionOrg){ http_response_code(403); exit('Forbidden'); }
}

// Handle POST actions similar to operator.php patterns
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!isset($_POST['_csrf']) || !hash_equals($_SESSION['csrf'] ?? '', $_POST['_csrf'])){ http_response_code(403); exit('CSRF'); }
  $action = $_POST['action'] ?? '';
  if((int)($_POST['id'] ?? 0) !== $id){ header('Location: template.php?id='.$id.'&msg=badid'); exit; }
  try {
    if($action==='rename'){
      $name=trim($_POST['name'] ?? '');
      if($name===''||mb_strlen($name)>160){ header('Location: template.php?id='.$id.'&msg=badname'); exit; }
      $awardTitleRaw = trim($_POST['award_title'] ?? '');
      $awardTitle = $awardTitleRaw !== '' ? $awardTitleRaw : 'Нагорода';
      if(mb_strlen($awardTitle) > 160){ header('Location: template.php?id='.$id.'&msg=badaward'); exit; }
      $pdo->prepare('UPDATE templates SET name=?, award_title=?, updated_at=NOW() WHERE id=? LIMIT 1')->execute([$name,$awardTitle,$id]);
      header('Location: template.php?id='.$id.'&msg=renamed'); exit;
    } elseif($action==='toggle') {
      $st2=$pdo->prepare('SELECT status FROM templates WHERE id=? LIMIT 1');
      $st2->execute([$id]);
      $cur=$st2->fetchColumn();
      if($cur===false){ header('Location: template.php?id='.$id.'&msg=nf'); exit; }
      $curNorm = trim(strtolower((string)$cur));
      if($curNorm==='archived'){ header('Location: template.php?id='.$id.'&msg=badstatus'); exit; }
      if($curNorm!=='active' && $curNorm!=='inactive'){ header('Location: template.php?id='.$id.'&msg=badstatus'); exit; }
      $next = ($curNorm==='active') ? 'inactive' : 'active';
      if($next===$curNorm){ header('Location: template.php?id='.$id.'&msg=toggled'); exit; }
      $up=$pdo->prepare('UPDATE templates SET status=?, updated_at=NOW() WHERE id=? LIMIT 1');
      $up->execute([$next,$id]);
      header('Location: template.php?id='.$id.'&msg=toggled'); exit;
    } elseif($action==='replace') {
      if(!isset($_FILES['template_file']) || ($_FILES['template_file']['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE){ header('Location: template.php?id='.$id.'&msg=nofile'); exit; }
      $f=$_FILES['template_file'];
      if(($f['error']??UPLOAD_ERR_OK)!==UPLOAD_ERR_OK){ header('Location: template.php?id='.$id.'&msg=upload'); exit; }
      $tmp=$f['tmp_name']; if(!is_uploaded_file($tmp)){ header('Location: template.php?id='.$id.'&msg=invalid'); exit; }
      $size=(int)$f['size']; if($size<=0 || $size>15*1024*1024){ header('Location: template.php?id='.$id.'&msg=filesize'); exit; }
      $ext=strtolower(pathinfo($f['name'],PATHINFO_EXTENSION)); $allowed=['jpg','jpeg','png','webp']; if(!in_array($ext,$allowed,true)){ header('Location: template.php?id='.$id.'&msg=badext'); exit; }
      $info=@getimagesize($tmp); if(!$info){ header('Location: template.php?id='.$id.'&msg=notimg'); exit; }
      [$w,$h]=$info; if($w<200||$h<200||$w>12000||$h>12000){ header('Location: template.php?id='.$id.'&msg=dim'); exit; }
      $hash=hash_file('sha256',$tmp);
      $pdo->beginTransaction();
      $pdo->prepare('UPDATE templates SET filename=?, file_ext=?, file_hash=?, file_size=?, width=?, height=?, version=version+1, updated_at=NOW() WHERE id=? LIMIT 1')
          ->execute([$f['name'],$ext,$hash,$size,$w,$h,$id]);
      $pdo->commit();
  $orgId=(int)$row['org_id']; $tplDir=__DIR__.'/files/templates/'.$orgId.'/'.$id; if(!is_dir($tplDir)) @mkdir($tplDir,0775,true);
      $dest=$tplDir.'/original.'.$ext; @move_uploaded_file($tmp,$dest); @unlink($tmp);
      // rebuild preview
      $info2=@getimagesize($dest); if($info2){
        $maxW=800; $rw=$info2[0]; $rh=$info2[1]; $ratio=$rw>0?min(1,$maxW/$rw):1; $tw=$ratio>=1?$rw:(int)round($rw*$ratio); $th=$ratio>=1?$rh:(int)round($rh*$ratio);
        switch($info2[2]){case IMAGETYPE_JPEG:$im=@imagecreatefromjpeg($dest);break;case IMAGETYPE_PNG:$im=@imagecreatefrompng($dest);break;case IMAGETYPE_WEBP: if(function_exists('imagecreatefromwebp')) $im=@imagecreatefromwebp($dest); else $im=null; break;default:$im=null;}
        if($im){ $out=imagecreatetruecolor($tw,$th); imagecopyresampled($out,$im,0,0,0,0,$tw,$th,imagesx($im),imagesy($im)); @imagejpeg($out,$tplDir.'/preview.jpg',82); imagedestroy($im); imagedestroy($out);} }
      header('Location: template.php?id='.$id.'&msg=replaced'); exit;
    } elseif($action==='delete') {
      // Block deletion if tokens reference this template (when tokens.template_id exists)
      $hasTplFk = col_exists($pdo,'tokens','template_id');
      if($hasTplFk){
        try {
          $cc=$pdo->prepare('SELECT COUNT(*) FROM tokens WHERE template_id=?');
          $cc->execute([$id]);
          if((int)$cc->fetchColumn() > 0){ header('Location: template.php?id='.$id.'&msg=in_use'); exit; }
        } catch(Throwable $e){ header('Location: template.php?id='.$id.'&msg=err'); exit; }
      }
      $pdo->prepare('DELETE FROM templates WHERE id=? LIMIT 1')->execute([$id]);
      header('Location: settings.php?tab=templates&msg=deleted'); exit;
    } else {
      header('Location: template.php?id='.$id.'&msg=unknown'); exit;
    }
  } catch(Throwable $e){ if($pdo->inTransaction()) $pdo->rollBack(); header('Location: template.php?id='.$id.'&msg=err'); exit; }
}

// Reload row after any GET
try { $st=$pdo->prepare('SELECT * FROM templates WHERE id=? LIMIT 1'); $st->execute([$id]); $row=$st->fetch(PDO::FETCH_ASSOC); if(!$row){ header('Location: settings.php?tab=templates&msg=nf'); exit; } } catch(Throwable $e){ $err='db'; }

// Compute usage count if schema supports it
$hasTplFk = col_exists($pdo,'tokens','template_id');
$inUseCount = null;
if($hasTplFk){
  try { $c=$pdo->prepare('SELECT COUNT(*) FROM tokens WHERE template_id=?'); $c->execute([$id]); $inUseCount=(int)$c->fetchColumn(); } catch(Throwable $e){ $inUseCount=null; }
}

$coordsDecoded = null;
if(isset($row['coords']) && $row['coords'] !== null){
  $tmp = json_decode($row['coords'], true);
  if($tmp !== null || trim((string)$row['coords']) === 'null'){
    $coordsDecoded = $tmp;
  }
}
$coordsJsonPretty = $coordsDecoded !== null ? json_encode($coordsDecoded, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT) : null;
$coordsJsonForScript = json_encode($coordsDecoded, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
if($coordsJsonForScript === false) { $coordsJsonForScript = 'null'; }
$coordsJsonForScriptEsc = htmlspecialchars($coordsJsonForScript, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$coordsJsonPrettyEsc = $coordsJsonPretty !== null ? htmlspecialchars($coordsJsonPretty, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : null;
$tplWidth = isset($row['width']) ? (int)$row['width'] : 0;
$tplHeight = isset($row['height']) ? (int)$row['height'] : 0;
$tplExt = isset($row['file_ext']) && $row['file_ext'] ? $row['file_ext'] : 'jpg';
$tplOriginalPath = '/files/templates/'.$row['org_id'].'/'.$row['id'].'/original.'.$tplExt;
$tplOriginalPathVer = $tplOriginalPath.'?v='.(int)$row['version'];
$tplOriginalEsc = htmlspecialchars($tplOriginalPathVer, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$tplPreviewEsc = htmlspecialchars('/files/templates/'.$row['org_id'].'/'.$row['id'].'/preview.jpg?v='.(int)$row['version'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$coordsEditorJsVer = @filemtime($_SERVER['DOCUMENT_ROOT'].'/assets/js/template_coords_editor.js') ?: time();

require_once __DIR__.'/header.php';
$msg = $_GET['msg'] ?? '';
?>
<section class="section" data-template-id="<?= (int)$row['id'] ?>" data-template-org="<?= (int)$row['org_id'] ?>" data-template-width="<?= $tplWidth ?>" data-template-height="<?= $tplHeight ?>" data-template-original="<?= $tplOriginalEsc ?>" data-template-preview="<?= $tplPreviewEsc ?>" data-template-award="<?= htmlspecialchars($row['award_title'] ?? '') ?>">
  <h1 class="mt-0">Шаблон #<?= htmlspecialchars($row['id']) ?></h1>
  <p class="fs-14 text-muted maxw-760">Управління окремим шаблоном. <a href="/settings.php?tab=templates" class="link-accent">← Повернутися до списку</a></p>
  <?php if($msg): ?><div class="mb-12 fs-13"><?php
  $map=[ 'renamed'=>'Назви оновлено','toggled'=>'Статус змінено','replaced'=>'Файл оновлено','deleted'=>'Видалено','badname'=>'Некоректна назва шаблону','badaward'=>'Некоректна назва нагороди','badid'=>'ID не збігається','nofile'=>'Файл не надано','upload'=>'Помилка завантаження','invalid'=>'Невалідний upload','filesize'=>'Розмір файлу не підходить','badext'=>'Погане розширення','notimg'=>'Не зображення','dim'=>'Неприпустимі розміри','badstatus'=>'Неможливо змінити статус (невідомий або заборонений)','in_use'=>'Шаблон використовується у вже виданих нагородах — видалення заблоковано.','err'=>'Внутрішня помилка','unknown'=>'Невідома дія' ];
    echo htmlspecialchars($map[$msg] ?? $msg);
  ?></div><?php endif; ?>
  <?php if($err==='db'): ?><div class="alert alert-error">Помилка БД.</div><?php else: ?>
  <div class="details-grid mb-24">
    <div>ID</div><div class="mono"><?= (int)$row['id'] ?></div>
    <div>Орг</div><div class="mono"><?= (int)$row['org_id'] ?></div>
  <div>Назва шаблону</div><div class="mono"><?= htmlspecialchars($row['name']) ?></div>
  <div>Назва нагороди</div><div class="mono"><?= htmlspecialchars($row['award_title'] ?? '') ?></div>
    <div>Код</div><div class="mono"><code><?= htmlspecialchars($row['code']) ?></code></div>
    <div>Статус</div><div><?php
      $statusMap = [ 'active'=>['label'=>'активний','cls'=>'badge-status-active'], 'inactive'=>['label'=>'неактивний','cls'=>'badge-status-inactive'], 'archived'=>['label'=>'архівований','cls'=>'badge-status-archived'] ];
      $s = strtolower(trim($row['status']));
      $m = $statusMap[$s] ?? ['label'=>htmlspecialchars($row['status']), 'cls'=>'badge-status-inactive'];
      echo '<span class="badge '.$m['cls'].'">'.$m['label'].'</span>';
    ?></div>
    <div>Розмір</div><div class="mono"><?= htmlspecialchars($row['file_size']).' B, '.$row['width'].'×'.$row['height'] ?></div>
    <div>Файл</div><div class="mono"><?= htmlspecialchars($row['filename']) ?> (.<?= htmlspecialchars($row['file_ext']) ?>)</div>
    <div>Версія</div><div class="mono">v<?= (int)$row['version'] ?></div>
    <div>Оновлено</div><div class="mono"><?= htmlspecialchars($row['updated_at']) ?></div>
  </div>
  <div class="mb-18">
  <?php $prevPath = '/files/templates/'.$row['org_id'].'/'.$row['id'].'/preview.jpg'; ?>
    <div class="mb-8 fs-13 text-muted">Поточний превʼю:</div>
  <div><img class="img-preview-480" src="<?= htmlspecialchars($prevPath) ?>?v=<?= (int)$row['version'] ?>" alt="preview" loading="lazy"></div>
  </div>
  <div class="mb-18" id="templateCoordsSummary">
    <div class="mb-8 fs-13 text-muted">Статус координат:</div>
    <div class="coords-summary fs-13 <?= $coordsDecoded !== null ? 'text-success' : 'text-muted' ?>" id="coordsSummaryText">
      <?php if($coordsDecoded !== null): ?>
        Індивідуальні координати збережено для цього шаблону.
      <?php else: ?>
        Налаштування ще не змінювалися – використовуються глобальні координати з конфігурації.
      <?php endif; ?>
    </div>
  </div>
  <div class="coords-editor-block" id="coordsEditorBlock">
    <h2 class="fs-18 mt-0">Редактор координат</h2>
    <p class="fs-13 text-muted">Перетягніть маркери на зображенні або скористайтесь полями справа. Координати зберігаються у пікселях відносно оригінального зображення шаблону.</p>
    <div class="coords-editor" id="coordsEditorRoot">
      <div class="coords-editor__stage" id="coordsEditorStage">
        <div class="coords-editor__stage-inner">
          <img src="<?= htmlspecialchars($prevPath) ?>?v=<?= (int)$row['version'] ?>" alt="Фон шаблону" id="coordsEditorBg" class="coords-editor__bg" loading="lazy">
          <div class="coords-editor__overlay" id="coordsEditorOverlay" role="presentation"></div>
        </div>
      </div>
  <div class="coords-editor__panel form" id="coordsEditorPanel">
        <div class="coords-editor__field">
          <label for="coordsFieldSelect">Поле</label>
          <select id="coordsFieldSelect">
            <option value="award">Назва нагороди</option>
            <option value="name">ПІБ</option>
            <option value="id">CID</option>
            <option value="extra">Додаткова інформація</option>
            <option value="date">Дата</option>
            <option value="expires">Дійсний до</option>
            <option value="qr">QR</option>
            <option value="int">INT код</option>
          </select>
        </div>
        <div class="coords-editor__grid">
          <label for="coordsFieldX">X</label>
          <input type="number" id="coordsFieldX" step="1" min="-2000" max="20000">
          <label for="coordsFieldY">Y</label>
          <input type="number" id="coordsFieldY" step="1" min="-2000" max="20000">
          <label for="coordsFieldSize">Розмір</label>
          <input type="number" id="coordsFieldSize" step="1" min="1" max="5000">
          <label for="coordsFieldAngle" class="coords-editor__angle">Кут</label>
          <input type="number" id="coordsFieldAngle" class="coords-editor__angle" step="1" min="-360" max="360">
        </div>
        <div class="coords-editor__toggles" id="coordsStyleToggles">
          <label class="coords-editor__toggle">
            <input type="checkbox" id="coordsFieldBold">
            <span>Жирний</span>
          </label>
          <label class="coords-editor__toggle">
            <input type="checkbox" id="coordsFieldItalic">
            <span>Курсив</span>
          </label>
        </div>
        <div class="coords-editor__color" id="coordsColorRow">
          <label for="coordsFieldColor">Колір тексту</label>
          <input type="color" id="coordsFieldColor" value="#1f2937">
          <button type="button" class="btn btn-xs btn-light" id="coordsFieldColorReset">Скинути</button>
        </div>
        <div class="coords-editor__hint fs-12 text-muted" id="coordsEditorHint"></div>
      </div>
    </div>
    <div class="coords-editor__actions">
      <button type="button" class="btn btn-primary" id="coordsSaveBtn">Зберегти координати</button>
      <button type="button" class="btn btn-light" id="coordsResetBtn">Скинути зміни</button>
      <button type="button" class="btn btn-light" id="coordsDefaultsBtn">Глобальні за замовчанням</button>
      <span class="coords-editor__status fs-12" id="coordsStatus"></span>
    </div>
  </div>
  <?php if($hasTplFk): ?>
  <div class="mb-18">
    <a class="btn btn-light" href="/tokens.php?tpl=<?= (int)$row['id'] ?>">Переглянути всі нагороди з цим шаблоном</a>
  </div>
  <?php endif; ?>
  <h2 class="mt-0 fs-18">Дії</h2>
  <form method="post" class="form mb-12">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
    <input type="hidden" name="action" value="rename">
    <label>Нова назва шаблону
      <input type="text" name="name" required maxlength="160" value="<?= htmlspecialchars($row['name']) ?>" placeholder="<?= htmlspecialchars($row['name']) ?>">
    </label>
    <label>Назва нагороди (відображається на сертифікаті)
      <input type="text" name="award_title" maxlength="160" value="<?= htmlspecialchars($row['award_title'] ?? '') ?>" placeholder="<?= htmlspecialchars($row['award_title'] ?? 'Нагорода') ?>">
    </label>
    <button class="btn btn-primary" type="submit">Змінити назву</button>
  </form>
  <?php if($s!=='archived'): ?>
  <form method="post" class="form mb-12 d-inline">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
    <input type="hidden" name="action" value="toggle">
    <button class="btn btn-light" type="submit"><?= $s==='active'?'Вимкнути':'Активувати' ?></button>
  </form>
  <?php else: ?>
    <span class="fs-12 text-muted d-inline-block mb-12">Архівований шаблон неможливо перемкнути.</span>
  <?php endif; ?>
  <form method="post" class="form mb-12" enctype="multipart/form-data">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
    <input type="hidden" name="action" value="replace">
    <label>Новий файл
      <input type="file" name="template_file" accept="image/jpeg,image/png,image/webp" required>
    </label>
    <button class="btn btn-primary" type="submit">Замінити фон</button>
  </form>
  <form method="post" class="form mb-12" onsubmit="return confirm('Видалити шаблон безповоротно?');">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
    <input type="hidden" name="action" value="delete">
    <?php if($hasTplFk && is_int($inUseCount) && $inUseCount>0): ?>
      <div class="fs-12 text-muted mb-8">Використано у <?= (int)$inUseCount ?> нагородах. Видалення недоступне.</div>
      <button class="btn btn-danger" type="submit" disabled aria-disabled="true" title="Шаблон використовується">Видалити</button>
    <?php else: ?>
      <button class="btn btn-danger" type="submit">Видалити</button>
    <?php endif; ?>
  </form>
  <?php endif; ?>
  <div class="mt-18"><a href="/settings.php?tab=templates" class="btn btn-light">← Назад</a></div>
</section>
<script type="application/json" id="template-coords-data"><?= $coordsJsonForScriptEsc ?></script>
<script src="/assets/js/template_coords_editor.js?v=<?= (int)$coordsEditorJsVer ?>" defer></script>
<?php require_once __DIR__.'/footer.php'; ?>
