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

$st = $pdo->prepare("SELECT cid, version, course, grade, issued_date, revoked_at, revoke_reason, created_at, lookup_count, last_lookup_at FROM tokens $where ORDER BY id DESC LIMIT :lim OFFSET :off");
foreach($params as $k=>$v) $st->bindValue($k,$v);
$st->bindValue(':lim',$perPage,PDO::PARAM_INT);
$st->bindValue(':off',$offset,PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll();
$pages = max(1,(int)ceil($total/$perPage));
$csrf = csrf_token();
?>
<section class="section">
  <h2 class="mt-0">Токени (анонімні сертифікати)</h2>
  <form id="filterForm" class="form filter-form" method="get">
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
    <form id="bulkForm" class="bulk-form" onsubmit="return false;">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
    <div id="bulkBar" class="bulk-bar d-none">
      <div class="flex gap-8 flex-wrap align-center">
        <strong class="fs-13">Вибрано: <span id="selCount">0</span></strong>
        <select id="bulkAction" class="fs-13">
          <option value="">Дія...</option>
          <option value="revoke">Відкликати</option>
          <option value="unrevoke">Відновити</option>
          <option value="delete">Видалити</option>
        </select>
        <input type="text" id="bulkReason" class="fs-13" placeholder="Причина (для відкликання)" maxlength="255" style="min-width:220px;display:none">
        <button type="button" id="bulkExecute" class="btn btn-sm">Виконати</button>
        <button type="button" id="bulkCancel" class="btn btn-sm btn-light">Скасувати</button>
        <span id="bulkStatus" class="fs-12"></span>
      </div>
    </div>
    <table class="table">
      <thead>
        <tr>
          <th><input type="checkbox" id="chkAll"></th>
          <th>CID</th>
          <th>Версія</th>
          <th>Курс</th>
          <th>Оцінка</th>
          <th>Дата</th>
          <th>Створено</th>
          <th>Статус</th>
          <th title="К-сть перевірок / остання">Переглядів</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($rows as $r): ?>
        <tr class="<?= $r['revoked_at'] ? 'row-revoked':'' ?>" data-cid="<?= htmlspecialchars($r['cid']) ?>">
          <td><input type="checkbox" class="rowChk" value="<?= htmlspecialchars($r['cid']) ?>"></td>
          <td class="mono fs-12"><a class="link-plain" href="/token.php?cid=<?= urlencode($r['cid']) ?>"><?= htmlspecialchars($r['cid']) ?></a></td>
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
          <td class="fs-11 nowrap">
            <?= (int)($r['lookup_count'] ?? 0) ?>
            <?php if(!empty($r['last_lookup_at'])): ?>
              <br><span class="text-muted" title="Остання перевірка (UTC)"><?= htmlspecialchars(substr($r['last_lookup_at'],0,19)) ?></span>
            <?php else: ?>
              <br><span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td><a class="btn btn-light btn-sm" href="/token.php?cid=<?= urlencode($r['cid']) ?>">Деталі</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
  </table>
  </form>
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