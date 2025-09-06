<?php
require_once __DIR__.'/auth.php';
$isAdminPage = true; // приховати публічний alert banner як у admin.php
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
<?php
// Журнал перевірок саме для цього сертифіката
$logLimit = 50; // останні 50
$sort = $_GET['lsort'] ?? 'id';
$dir  = strtolower($_GET['ldir'] ?? 'desc') === 'asc' ? 'asc':'desc';
$allowedSortL = ['id','requested_id','status','created_at'];
if (!in_array($sort,$allowedSortL,true)) { $sort='id'; }
$sortLabelsL = [
  'id'=>'ID',
  'requested_id'=>'ID запиту',
  'status'=>'Статус',
  'created_at'=>'Час'
];
$orderL = "$sort $dir";
$sqlLogs = "SELECT id, requested_id, requested_hash, success, status, revoked, remote_ip, user_agent, created_at FROM verification_logs WHERE data_id=? ORDER BY $orderL LIMIT ?";
$logSt = $pdo->prepare($sqlLogs);
$logSt->bindValue(1, $row['id'], PDO::PARAM_INT);
$logSt->bindValue(2, $logLimit, PDO::PARAM_INT);
try { $logSt->execute(); $logRows = $logSt->fetchAll(); } catch (Throwable $e) { $logRows = []; }
if ($logRows): ?>
<section class="section">
  <h3>Останні перевірки (<?= count($logRows) ?>)</h3>
  <form method="get" class="form form-inline" style="margin-bottom:10px;gap:6px">
    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
    <label style="display:flex;flex-direction:column;font-size:12px;gap:2px">Стовпець
      <select name="lsort">
        <?php foreach ($allowedSortL as $s): ?>
          <option value="<?= $s ?>" <?= $sort===$s?'selected':'' ?>><?= $sortLabelsL[$s] ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label style="display:flex;flex-direction:column;font-size:12px;gap:2px">Напрямок
      <select name="ldir">
        <option value="desc" <?= $dir==='desc'?'selected':'' ?>>спадання</option>
        <option value="asc" <?= $dir==='asc'?'selected':'' ?>>зростання</option>
      </select>
    </label>
    <button class="btn" type="submit">OK</button>
  </form>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th><a title="ID запису журналу – унікальний номер" href="?<?= htmlspecialchars(http_build_query(['id'=>$row['id'],'lsort'=>'id','ldir'=>$sort==='id'&&$dir==='asc'?'desc':'asc'])) ?>">ID</a></th>
          <th><a title="ID запиту – параметр id із URL перевірки" href="?<?= htmlspecialchars(http_build_query(['id'=>$row['id'],'lsort'=>'requested_id','ldir'=>$sort==='requested_id'&&$dir==='asc'?'desc':'asc'])) ?>">ID запиту</a></th>
          <th title="Скорочене відображення хешу запиту (перші 20 символів)">Хеш (скор.)</th>
          <th><a title="Статус перевірки (success, bad_hash, not_found, bad_id, revoked)" href="?<?= htmlspecialchars(http_build_query(['id'=>$row['id'],'lsort'=>'status','ldir'=>$sort==='status'&&$dir==='asc'?'desc':'asc'])) ?>">Статус</a></th>
          <th title="1 – перевірка валідна; 0 – невдала / невалідна">Успіх</th>
          <th title="Чи був сертифікат відкликаний на момент перевірки">Відкликано</th>
          <th title="IP адреса клієнта">IP</th>
          <th><a title="Час фіксації події" href="?<?= htmlspecialchars(http_build_query(['id'=>$row['id'],'lsort'=>'created_at','ldir'=>$sort==='created_at'&&$dir==='asc'?'desc':'asc'])) ?>">Час</a></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($logRows as $lr): ?>
          <tr>
            <td><?= (int)$lr['id'] ?></td>
            <td><?= htmlspecialchars($lr['requested_id']) ?></td>
            <td style="font-family:monospace;font-size:12px;max-width:150px;overflow:hidden;text-overflow:ellipsis" title="<?= htmlspecialchars($lr['requested_hash']) ?>"><?= htmlspecialchars(substr($lr['requested_hash'],0,20)) ?></td>
            <td><?= htmlspecialchars($lr['status']) ?></td>
            <td><?= $lr['success'] ? '1' : '0' ?></td>
            <td><?= $lr['revoked'] ? '1' : '0' ?></td>
            <td><?= htmlspecialchars($lr['remote_ip']) ?></td>
            <td><?= htmlspecialchars($lr['created_at']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php else: ?>
<section class="section"><p>Перевірок поки немає.</p></section>
<?php endif; ?>
<?php require_once __DIR__.'/footer.php'; ?>
