<?php
require_once __DIR__.'/auth.php';
require_admin();
$isAdminPage = true;
require_once __DIR__.'/db.php';
$cfg = require __DIR__.'/config.php';
// CSRF token early
$csrf = csrf_token();

// Fetch operator
$id = (int)($_GET['id'] ?? 0);
// Redirect to users tab in settings if id invalid
if($id <= 0){ header('Location: /settings.php?tab=users'); exit; }

function column_exists(PDO $pdo, string $table, string $col): bool { try { $st=$pdo->prepare('SHOW COLUMNS FROM `'.$table.'` LIKE ?'); $st->execute([$col]); return (bool)$st->fetch(); } catch(Throwable $e){ return false; } }
$hasActive = column_exists($pdo,'creds','is_active');
$hasCreated = column_exists($pdo,'creds','created_at');

// Handle POST actions locally (avoid JS) to keep CSP strict
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!isset($_POST['_csrf']) || !hash_equals($_SESSION['csrf'] ?? '', $_POST['_csrf'])){ http_response_code(403); exit('CSRF'); }
  $action = $_POST['action'] ?? '';
  $targetId = (int)($_POST['id'] ?? 0);
  if($targetId !== $id){ header('Location: operator.php?id='.$id.'&msg=badid'); exit; }
  try {
    $st = $pdo->prepare('SELECT id, username, role'.($hasActive?', is_active':'').' FROM creds WHERE id=? LIMIT 1');
    $st->execute([$id]);
    $op = $st->fetch(PDO::FETCH_ASSOC);
  if(!$op){ header('Location: /settings.php?tab=users&msg=nf'); exit; }
    $isAdminRow = $op['role']==='admin';
    if($isAdminRow){ header('Location: operator.php?id='.$id.'&msg=forbidden'); exit; }
    if($action==='rename'){
      $nu = trim($_POST['new_username'] ?? '');
      if($nu==='' || !preg_match('/^[a-zA-Z0-9_.-]{3,40}$/',$nu)){ header('Location: operator.php?id='.$id.'&msg=uname'); exit; }
      $chk = $pdo->prepare('SELECT 1 FROM creds WHERE username=? AND id<>? LIMIT 1');
      $chk->execute([$nu,$id]);
      if($chk->fetch()){ header('Location: operator.php?id='.$id.'&msg=exists'); exit; }
      $pdo->prepare('UPDATE creds SET username=? WHERE id=? LIMIT 1')->execute([$nu,$id]);
      header('Location: operator.php?id='.$id.'&msg=renamed'); exit;
    } elseif($action==='toggle' && $hasActive){
      $cur = (int)($op['is_active'] ?? 1); $new = $cur?0:1;
      $pdo->prepare('UPDATE creds SET is_active=? WHERE id=? LIMIT 1')->execute([$new,$id]);
      header('Location: operator.php?id='.$id.'&msg=toggled'); exit;
    } elseif($action==='resetpw'){
      $p1 = $_POST['password'] ?? ''; $p2 = $_POST['password2'] ?? '';
      if($p1===''||$p2===''){ header('Location: operator.php?id='.$id.'&msg=empty'); exit; }
      if($p1!==$p2){ header('Location: operator.php?id='.$id.'&msg=mismatch'); exit; }
      if(strlen($p1) < 8){ header('Location: operator.php?id='.$id.'&msg=short'); exit; }
      $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
      $opts = $algo === PASSWORD_ARGON2ID ? ['memory_cost'=>1<<17,'time_cost'=>3,'threads'=>1] : ['cost'=>12];
      $hash = password_hash($p1,$algo,$opts);
      $pdo->prepare('UPDATE creds SET passhash=? WHERE id=? LIMIT 1')->execute([$hash,$id]);
      header('Location: operator.php?id='.$id.'&msg=pwreset'); exit;
    } elseif($action==='delete'){
      $pdo->prepare('DELETE FROM creds WHERE id=? LIMIT 1')->execute([$id]);
  header('Location: settings.php?tab=users&msg=deleted'); exit;
    } else {
      header('Location: operator.php?id='.$id.'&msg=unknown'); exit;
    }
  } catch(Throwable $e){ error_log('operator.php action error: '.$e->getMessage()); header('Location: operator.php?id='.$id.'&msg=err'); exit; }
}

// Load row for display
$row = null; $err=null;
try {
  $st = $pdo->prepare('SELECT id, username, role'
    .($hasActive?', is_active':'')
    .($hasCreated?', created_at':'')
    .' FROM creds WHERE id=? LIMIT 1');
  $st->execute([$id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if(!$row) { header('Location: /settings.php?tab=users&msg=nf'); exit; }
} catch(Throwable $e){ $err='db'; }

require_once __DIR__.'/header.php';
$msg = $_GET['msg'] ?? '';
?>
<section class="section">
  <h1 class="mt-0">Оператор #<?= htmlspecialchars($row['id']) ?></h1>
  <p class="fs-14 text-muted maxw-760">Керування окремим обліковим записом. Повернутися до списку – <a href="/settings.php?tab=users" class="link-accent">користувачі</a>.</p>
  <?php if($msg): ?>
    <div class="mb-12 fs-13 <?php if($msg==='err') echo 'text-danger'; ?>">
      <?php
        $map = [
          'renamed'=>'Логін змінено','toggled'=>'Статус змінено','pwreset'=>'Пароль оновлено','deleted'=>'Видалено','uname'=>'Невалідний логін','exists'=>'Логін вже зайнятий','mismatch'=>'Паролі не співпадають','short'=>'Пароль закороткий','empty'=>'Порожні поля','forbidden'=>'Заборонено','err'=>'Внутрішня помилка','unknown'=>'Невідома дія','badid'=>'ID не збігається'];
        echo htmlspecialchars($map[$msg] ?? $msg);
      ?>
    </div>
  <?php endif; ?>
  <?php if($err==='db'): ?>
    <div class="alert alert-error">Помилка БД.</div>
  <?php else: ?>
    <div class="details-grid mb-24">
      <div>ID</div><div class="mono"><?= (int)$row['id'] ?></div>
      <div>Логін</div><div class="mono"><?= htmlspecialchars($row['username']) ?></div>
      <div>Роль</div><div><?= htmlspecialchars($row['role']) ?></div>
      <div>Статус</div><div><?= ($hasActive ? ((int)$row['is_active']===1?'<span class="badge badge-success">активний</span>':'<span class="badge badge-danger">неактивний</span>') : '—') ?></div>
      <div>Створено</div><div><?= htmlspecialchars($row['created_at'] ?? '—') ?></div>
    </div>
    <?php if($row['role']==='admin'): ?>
      <div class="alert">Це адміністраторський акаунт. Зміни не дозволені через інтерфейс.</div>
    <?php else: ?>
      <h2 class="mt-0 fs-18">Дії</h2>
      <form method="post" class="form mb-12">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
        <input type="hidden" name="action" value="rename">
        <label>Новий логін
          <input type="text" name="new_username" required pattern="^[a-zA-Z0-9_.-]{3,40}$" maxlength="40" placeholder="<?= htmlspecialchars($row['username']) ?>">
        </label>
        <button class="btn btn-primary" type="submit">Змінити логін</button>
      </form>
      <?php if($hasActive): ?>
      <form method="post" class="form mb-12 d-inline">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
        <input type="hidden" name="action" value="toggle">
        <button class="btn btn-light" type="submit"><?= ((int)$row['is_active']===1?'Деактивувати':'Активувати') ?></button>
      </form>
      <?php endif; ?>
      <form method="post" class="form mb-12">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
        <input type="hidden" name="action" value="resetpw">
        <label>Новий пароль
          <input type="password" name="password" required minlength="8" autocomplete="new-password">
        </label>
        <label>Повтор паролю
          <input type="password" name="password2" required minlength="8" autocomplete="new-password">
        </label>
        <button class="btn btn-primary" type="submit">Скинути пароль</button>
      </form>
      <form method="post" onsubmit="return confirm('Видалити цього оператора безповоротно?');" class="form mb-12">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
        <input type="hidden" name="action" value="delete">
        <button class="btn btn-danger" type="submit">Видалити</button>
      </form>
    <?php endif; ?>
  <?php endif; ?>
  <div class="mt-18"><a href="/settings.php?tab=users" class="btn btn-light">← Назад до списку</a></div>
</section>
<?php require_once __DIR__.'/footer.php'; ?>
