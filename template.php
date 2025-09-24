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
      $pdo->prepare('UPDATE templates SET name=?, updated_at=NOW() WHERE id=? LIMIT 1')->execute([$name,$id]);
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

require_once __DIR__.'/header.php';
$msg = $_GET['msg'] ?? '';
?>
<section class="section">
  <h1 class="mt-0">Шаблон #<?= htmlspecialchars($row['id']) ?></h1>
  <p class="fs-14 text-muted maxw-760">Управління окремим шаблоном. <a href="/settings.php?tab=templates" class="link-accent">← Повернутися до списку</a></p>
  <?php if($msg): ?><div class="mb-12 fs-13"><?php
  $map=[ 'renamed'=>'Назву змінено','toggled'=>'Статус змінено','replaced'=>'Файл оновлено','deleted'=>'Видалено','badname'=>'Некоректна назва','badid'=>'ID не збігається','nofile'=>'Файл не надано','upload'=>'Помилка завантаження','invalid'=>'Невалідний upload','filesize'=>'Розмір файлу не підходить','badext'=>'Погане розширення','notimg'=>'Не зображення','dim'=>'Неприпустимі розміри','badstatus'=>'Неможливо змінити статус (невідомий або заборонений)','in_use'=>'Шаблон використовується у вже виданих нагородах — видалення заблоковано.','err'=>'Внутрішня помилка','unknown'=>'Невідома дія' ];
    echo htmlspecialchars($map[$msg] ?? $msg);
  ?></div><?php endif; ?>
  <?php if($err==='db'): ?><div class="alert alert-error">Помилка БД.</div><?php else: ?>
  <div class="details-grid mb-24">
    <div>ID</div><div class="mono"><?= (int)$row['id'] ?></div>
    <div>Орг</div><div class="mono"><?= (int)$row['org_id'] ?></div>
    <div>Назва</div><div class="mono"><?= htmlspecialchars($row['name']) ?></div>
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
    <div><img src="<?= htmlspecialchars($prevPath) ?>?v=<?= (int)$row['version'] ?>" alt="preview" style="max-width:480px;border:1px solid #e2e8f0;border-radius:8px" loading="lazy"></div>
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
    <label>Нова назва
      <input type="text" name="name" required maxlength="160" placeholder="<?= htmlspecialchars($row['name']) ?>">
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
<?php require_once __DIR__.'/footer.php'; ?>
