<?php
require_once __DIR__.'/auth.php';
require_admin();
$isAdminPage = true;
$cfg = require __DIR__.'/config.php';
require_once __DIR__.'/header.php';
require_once __DIR__.'/db.php';

$q = trim($_GET['q'] ?? '');
$state = $_GET['state'] ?? '';
$page = max(1,(int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page-1)*$perPage;

$where = '';$params=[];$conds=[];
if($q!==''){
  $conds[] = "(cid LIKE :q OR course LIKE :q OR grade LIKE :q)";
  $params[':q'] = "%$q%";
}
if($state==='active'){
  $conds[] = "revoked_at IS NULL";
} elseif($state==='revoked') {
  $conds[] = "revoked_at IS NOT NULL";
}
if($conds){
  $where = 'WHERE '.implode(' AND ',$conds);
}

$totalSt = $pdo->prepare("SELECT COUNT(*) FROM tokens $where");
foreach($params as $k=>$v) $totalSt->bindValue($k,$v);
$totalSt->execute();
$total = (int)$totalSt->fetchColumn();

$st = $pdo->prepare("SELECT cid, version, course, grade, issued_date, revoked_at, revoke_reason, created_at FROM tokens $where ORDER BY id DESC LIMIT :lim OFFSET :off");
foreach($params as $k=>$v) $st->bindValue($k,$v);
$st->bindValue(':lim',$perPage,PDO::PARAM_INT);
$st->bindValue(':off',$offset,PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll();
$pages = max(1,(int)ceil($total/$perPage));
$csrf = csrf_token();
?>
<section class="section">
  <h2 style="margin-top:0">Токени (анонімні сертифікати)</h2>
  <form id="filterForm" class="form form-inline" method="get" style="margin-bottom:12px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Пошук CID / курс / оцінка">
    <select name="state">
      <option value="" <?= $state===''?'selected':'' ?>>Усі</option>
      <option value="active" <?= $state==='active'?'selected':'' ?>>Активні</option>
      <option value="revoked" <?= $state==='revoked'?'selected':'' ?>>Відкликані</option>
    </select>
    <button class="btn" type="submit">Фільтр</button>
    <?php if($q!=='' || $state!==''): ?>
      <a class="btn btn-light" href="/tokens.php">Скинути</a>
    <?php endif; ?>
  </form>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>CID</th>
          <th>Версія</th>
          <th>Курс</th>
          <th>Оцінка</th>
          <th>Дата</th>
          <th>Створено</th>
          <th>Статус</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($rows as $r): ?>
        <tr class="<?= $r['revoked_at'] ? 'row-revoked':'' ?>">
          <td style="font-family:monospace;font-size:12px"><a href="/token.php?cid=<?= urlencode($r['cid']) ?>" style="text-decoration:none;"><?= htmlspecialchars($r['cid']) ?></a></td>
          <td><?= (int)$r['version'] ?></td>
          <td><?= htmlspecialchars($r['course'] ?? '') ?></td>
          <td><?= htmlspecialchars($r['grade'] ?? '') ?></td>
          <td><?= htmlspecialchars($r['issued_date'] ?? '') ?></td>
          <td><?= htmlspecialchars($r['created_at']) ?></td>
          <td>
            <?php if($r['revoked_at']): ?>
              <span class="badge badge-danger" title="<?= htmlspecialchars($r['revoke_reason'] ?? '') ?>">Відкликано</span>
            <?php else: ?>
              <span class="badge badge-success">Активний</span>
            <?php endif; ?>
          </td>
          <td><a class="btn btn-light btn-sm" href="/token.php?cid=<?= urlencode($r['cid']) ?>">Деталі</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if($pages>1): ?>
    <nav class="pagination">
      <?php for($p=1;$p<=$pages;$p++): ?>
  <a class="page <?= $p===$page?'active':'' ?>" href="?<?= http_build_query(['q'=>$q,'state'=>$state,'page'=>$p]) ?>"><?= $p ?></a>
      <?php endfor; ?>
    </nav>
  <?php endif; ?>
</section>
<script src="/assets/js/tokens_page.js"></script>
<?php require_once __DIR__.'/footer.php'; ?>