<?php
require_once __DIR__.'/auth.php';
require_admin();
require_once __DIR__.'/db.php';
$cfg = require __DIR__.'/config.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('Bad id'); }

$st = $pdo->prepare("SELECT * FROM data WHERE id=?");
$st->execute([$id]);
$row = $st->fetch();
if (!$row) { http_response_code(404); exit('Не знайдено'); }

$csrf = csrf_token();
require_once __DIR__.'/header.php';
?>
<section class="section">
  <h2>Сертифікат #<?= (int)$row['id'] ?></h2>
  <div class="card" style="max-width:760px">
    <p><strong>Ім'я:</strong> <?= htmlspecialchars($row['name']) ?></p>
    <p><strong>Оцінка:</strong> <?= htmlspecialchars($row['score']) ?></p>
    <p><strong>Курс:</strong> <?= htmlspecialchars($row['course']) ?></p>
    <p><strong>Дата:</strong> <?= htmlspecialchars($row['date']) ?></p>
    <p><strong>hash_version:</strong> <?= (int)($row['hash_version'] ?? 1) ?></p>
    <p><strong>Hash:</strong> <code><?= htmlspecialchars($row['hash'] ?? '') ?></code></p>
    <?php if (!empty($row['hash'])): ?>
      <p><strong>Публічне посилання перевірки:</strong><br>
      <a href="/checkCert?id=<?= (int)$row['id'] ?>&hash=<?= htmlspecialchars($row['hash']) ?>" target="_blank" rel="noopener">
        /checkCert?id=<?= (int)$row['id'] ?>&hash=<?= htmlspecialchars($row['hash']) ?>
      </a></p>
    <?php endif; ?>
    <p><strong>Статус:</strong>
      <?php if (!empty($row['revoked_at'])): ?>
        <span class="badge badge-danger">Відкликано</span>
        (<?= htmlspecialchars($row['revoke_reason'] ?? '') ?>, <?= htmlspecialchars($row['revoked_at']) ?>)
      <?php else: ?>
        <span class="badge badge-success">Активний</span>
      <?php endif; ?>
    </p>

    <div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:16px">
      <a class="btn btn-primary" href="/generate_cert.php?id=<?= (int)$row['id'] ?>">Згенерувати JPEG</a>
      <?php if (empty($row['revoked_at'])): ?>
        <form method="post" action="/revoke.php" class="inline" style="display:inline-flex;gap:6px;align-items:center">
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
            <input type="hidden" name="action" value="revoke">
            <input type="text" name="reason" placeholder="Причина" required style="padding:6px 8px;border:1px solid var(--border);border-radius:8px">
            <button class="btn btn-warning" type="submit">Відкликати</button>
        </form>
      <?php else: ?>
        <form method="post" action="/revoke.php" class="inline">
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
          <input type="hidden" name="action" value="restore">
          <button class="btn btn-secondary" type="submit">Відновити</button>
        </form>
      <?php endif; ?>
      <form method="post" action="/delete_record.php" onsubmit="return confirm('Видалити запис? Це незворотно.')" class="inline">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
        <button class="btn btn-danger" type="submit">Видалити</button>
      </form>
      <a class="btn" href="/admin.php">← Повернутись</a>
    </div>
  </div>
</section>
<?php require_once __DIR__.'/footer.php'; ?>
