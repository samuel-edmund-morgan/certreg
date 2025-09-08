<?php
require_once __DIR__.'/auth.php';
require_admin();
require_csrf();
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

// Derive short integrity code (first 10 hex of h) if available
$intShort = null;
if(!empty($row['h'] ?? null)){
  $intShort = strtoupper(substr($row['h'],0,10));
  $intShort = substr($intShort,0,5).'-'.substr($intShort,5);
}
?>
<section class="section">
  <h2 class="mt-0 flex flex-wrap gap-8 align-center">CID: <span class="mono"><?= htmlspecialchars($row['cid']) ?></span>
    <button type="button" class="btn btn-sm" id="copyCidBtn" title="Копіювати CID">Копіювати CID</button>
    <?php if($intShort): ?>
      <span class="fs-14 text-muted">INT <code id="intCode" class="badge-int"><?= htmlspecialchars($intShort) ?></code></span>
      <button type="button" class="btn btn-sm" id="copyIntBtn" title="Копіювати INT">Копіювати INT</button>
    <?php endif; ?>
    <span id="copyStatus" class="fs-11 text-success d-none">Скопійовано</span>
  </h2>
  <div class="card maxw-760">
    <div class="details-grid">
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
  <div><strong>Переглядів</strong></div><div><?= (int)($row['lookup_count'] ?? 0) ?></div>
  <div><strong>Остання перевірка</strong></div><div><?= $row['last_lookup_at'] ? htmlspecialchars($row['last_lookup_at']) : '—' ?></div>
    </div>
    <hr class="my-18">
    <div class="actions-row">
      <?php if(!$row['revoked_at']): ?>
        <form id="revokeForm" method="post" action="/api/revoke.php" class="flex flex-wrap gap-6 align-center">
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="cid" value="<?= htmlspecialchars($row['cid']) ?>">
          <input type="text" name="reason" placeholder="Причина відкликання" maxlength="120" class="flex-1 minw-200">
          <button class="btn btn-danger btn-sm" type="submit">Відкликати</button>
        </form>
      <?php else: ?>
        <form id="unrevokeForm" method="post" action="/api/unrevoke.php" class="flex gap-6 align-center">
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="cid" value="<?= htmlspecialchars($row['cid']) ?>">
          <button class="btn btn-light btn-sm" type="submit">Відновити</button>
        </form>
      <?php endif; ?>
      <form id="deleteForm" method="post" action="/api/delete_token.php" class="d-inline">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="cid" value="<?= htmlspecialchars($row['cid']) ?>">
        <button class="btn btn-sm btn-ghost-danger" type="submit">Видалити</button>
      </form>
    </div>
    <p class="fs-11 text-muted mt-14">Видалення безповоротне. Сервер не зберігає ПІБ, тому повторно привʼязати особу буде неможливо.</p>
    <p class="mt-10 flex gap-8 flex-wrap">
      <a href="/tokens.php" class="btn btn-sm">← До списку</a>
    </p>
  </div>
</section>
<script src="/assets/js/token_page.js"></script>
<?php require_once __DIR__.'/footer.php'; ?>
