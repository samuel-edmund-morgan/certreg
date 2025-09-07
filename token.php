<?php
require_once __DIR__.'/auth.php';
require_admin();
$isAdminPage = true;
$cfg = require __DIR__.'/config.php';
require_once __DIR__.'/db.php';
$cid = trim($_GET['cid'] ?? '');
if($cid===''){ http_response_code(400); echo 'Missing cid'; exit; }
$st = $pdo->prepare("SELECT * FROM tokens WHERE cid=? LIMIT 1");
$st->execute([$cid]);
$row = $st->fetch(PDO::FETCH_ASSOC);
require_once __DIR__.'/header.php';
if(!$row){ echo '<section class="section"><div class="alert alert-error">Не знайдено</div></section>'; require_once __DIR__.'/footer.php'; exit; }
$csrf = csrf_token();
?>
<section class="section">
  <h2 style="margin-top:0">CID: <span style="font-family:monospace;"><?= htmlspecialchars($row['cid']) ?></span></h2>
  <div class="card" style="max-width:760px">
    <div style="display:grid;grid-template-columns:160px 1fr;gap:12px;font-size:14px">
      <div><strong>Версія</strong></div><div><?= (int)$row['version'] ?></div>
      <div><strong>Курс</strong></div><div><?= htmlspecialchars($row['course'] ?? '') ?></div>
      <div><strong>Оцінка</strong></div><div><?= htmlspecialchars($row['grade'] ?? '') ?></div>
      <div><strong>Дата (issued)</strong></div><div><?= htmlspecialchars($row['issued_date'] ?? '') ?></div>
      <div><strong>Створено (UTC)</strong></div><div><?= htmlspecialchars($row['created_at']) ?></div>
      <div><strong>Статус</strong></div><div>
        <?php if($row['revoked_at']): ?>
          <span class="badge badge-danger">Відкликано</span><br>
          <small>Дата: <?= htmlspecialchars($row['revoked_at']) ?></small><br>
          <small>Причина: <?= htmlspecialchars($row['revoke_reason'] ?? '') ?></small>
        <?php else: ?>
          <span class="badge badge-success">Активний</span>
        <?php endif; ?>
      </div>
  <div><strong>Lookup count</strong></div><div><?= (int)($row['lookup_count'] ?? 0) ?></div>
  <div><strong>Last lookup</strong></div><div><?= $row['last_lookup_at'] ? htmlspecialchars($row['last_lookup_at']) : '—' ?></div>
    </div>
    <hr style="margin:18px 0">
    <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-start">
      <?php if(!$row['revoked_at']): ?>
        <form id="revokeForm" method="post" action="/api/revoke.php" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="cid" value="<?= htmlspecialchars($row['cid']) ?>">
          <input type="text" name="reason" placeholder="Причина відкликання" maxlength="120" style="flex:1;min-width:200px">
          <button class="btn btn-danger btn-sm" type="submit">Відкликати</button>
        </form>
      <?php else: ?>
        <form id="unrevokeForm" method="post" action="/api/unrevoke.php" style="display:flex;gap:6px;align-items:center">
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="cid" value="<?= htmlspecialchars($row['cid']) ?>">
          <button class="btn btn-light btn-sm" type="submit">Відновити</button>
        </form>
      <?php endif; ?>
  <form id="deleteForm" method="post" action="/api/delete_token.php">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="cid" value="<?= htmlspecialchars($row['cid']) ?>">
        <button class="btn btn-sm" type="submit" style="background:#64748b;color:#fff;border-color:#64748b">Видалити</button>
      </form>
    </div>
    <p style="font-size:11px;color:#475569;margin-top:14px">Видалення безповоротне. Сервер не зберігає ПІБ, тому повторно привʼязати особу буде неможливо.</p>
    <p style="margin-top:10px"><a href="/tokens.php" class="btn btn-sm">← До списку</a></p>
  </div>
</section>
<script src="/assets/js/token_page.js"></script>
<?php require_once __DIR__.'/footer.php'; ?>
