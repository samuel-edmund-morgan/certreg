<?php
require_once __DIR__.'/auth.php';
require_admin();
require_once __DIR__.'/db.php';
$isAdminPage = true;
$cfg = require __DIR__.'/config.php';

$cid = trim($_GET['cid'] ?? '');
$page = max(1,(int)($_GET['page'] ?? 1));
$perPage = 50; $offset = ($page-1)*$perPage;

$params = [];
$where = '';
if($cid !== ''){ $where = 'WHERE cid = :cid'; $params[':cid'] = $cid; }

$totalSt = $pdo->prepare("SELECT COUNT(*) FROM token_events $where");
foreach($params as $k=>$v) $totalSt->bindValue($k,$v);
$totalSt->execute();
$total = (int)$totalSt->fetchColumn();
$pages = max(1,(int)ceil($total/$perPage));

$st = $pdo->prepare("SELECT id,cid,event_type,reason,admin_user,prev_revoked_at,prev_revoke_reason,created_at FROM token_events $where ORDER BY id DESC LIMIT :lim OFFSET :off");
foreach($params as $k=>$v) $st->bindValue($k,$v);
$st->bindValue(':lim',$perPage,PDO::PARAM_INT);
$st->bindValue(':off',$offset,PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__.'/header.php';
?>
<section class="section">
  <h2 style="margin-top:0">Аудит подій токенів</h2>
  <form class="form form-inline" method="get" style="margin-bottom:12px">
    <input type="text" name="cid" placeholder="CID" value="<?= htmlspecialchars($cid) ?>" style="min-width:280px">
    <button class="btn" type="submit">Фільтр</button>
    <?php if($cid!==''): ?><a class="btn" href="/events.php" style="margin-left:6px">Скинути</a><?php endif; ?>
    <a class="btn" href="/tokens.php" style="margin-left:6px">← Токени</a>
  </form>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>ID</th>
          <th>CID</th>
          <th>Подія</th>
          <th>Причина</th>
          <th>Адмін</th>
          <th>Попереднє revoked_at</th>
          <th>Попередня причина</th>
          <th>Час</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($rows as $r): ?>
        <tr>
          <td><?= (int)$r['id'] ?></td>
          <td style="font-family:monospace;font-size:12px"><a href="/token.php?cid=<?= urlencode($r['cid']) ?>" style="text-decoration:none;"><?= htmlspecialchars($r['cid']) ?></a></td>
          <td><?= htmlspecialchars($r['event_type']) ?></td>
          <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis" title="<?= htmlspecialchars($r['reason'] ?? '') ?>"><?= htmlspecialchars($r['reason'] ?? '') ?></td>
          <td><?= htmlspecialchars($r['admin_user'] ?? '') ?></td>
          <td><?= htmlspecialchars($r['prev_revoked_at'] ?? '') ?></td>
          <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis" title="<?= htmlspecialchars($r['prev_revoke_reason'] ?? '') ?>"><?= htmlspecialchars($r['prev_revoke_reason'] ?? '') ?></td>
          <td><?= htmlspecialchars($r['created_at']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if($pages>1): ?>
    <nav class="pagination">
      <?php for($p=1;$p<=$pages;$p++): ?>
        <a class="page <?= $p===$page?'active':'' ?>" href="?<?= http_build_query(['cid'=>$cid,'page'=>$p]) ?>"><?= $p ?></a>
      <?php endfor; ?>
    </nav>
  <?php endif; ?>
</section>
<?php require_once __DIR__.'/footer.php'; ?>