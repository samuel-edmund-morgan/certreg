<?php
require_once __DIR__.'/auth.php';
require_admin();
require_csrf();
$isAdminPage = true;
$cfg = require __DIR__.'/config.php';
require_once __DIR__.'/header.php';
require_once __DIR__.'/db.php';

$q = trim($_GET['q'] ?? '');
$state = $_GET['state'] ?? '';
$sort = $_GET['sort'] ?? 'id';
$dir = $_GET['dir'] ?? 'desc';
$page = max(1,(int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page-1)*$perPage;

$allowedSorts = ['id', 'cid', 'version', 'course', 'grade', 'issued_date', 'created_at', 'status', 'lookup_count'];
if(!in_array($sort, $allowedSorts, true)) {
    $sort = 'id';
}
$dir = strtolower($dir) === 'asc' ? 'asc' : 'desc';

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

$orderBy = "ORDER BY {$sort} {$dir}";
if ($sort === 'status') {
    $orderBy = "ORDER BY revoked_at IS NOT NULL {$dir}, id {$dir}";
}

$totalSt = $pdo->prepare("SELECT COUNT(*) FROM tokens $where");
foreach($params as $k=>$v) $totalSt->bindValue($k,$v);
$totalSt->execute();
$total = (int)$totalSt->fetchColumn();

$st = $pdo->prepare("SELECT cid, version, course, grade, issued_date, revoked_at, revoke_reason, created_at, lookup_count, last_lookup_at FROM tokens $where {$orderBy} LIMIT :lim OFFSET :off");
foreach($params as $k=>$v) $st->bindValue($k,$v);
$st->bindValue(':lim',$perPage,PDO::PARAM_INT);
$st->bindValue(':off',$offset,PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll();
$pages = max(1,(int)ceil($total/$perPage));
$csrf = csrf_token();

function sort_arrow($column, $currentSort, $currentDir) {
    if ($column === $currentSort) {
        return $currentDir === 'asc' ? ' asc' : ' desc';
    }
    return '';
}
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
  <div class="table-wrap" id="tokensTableWrap">
    <form id="bulkForm" class="bulk-form" onsubmit="return false;" data-total="<?= (int)$total ?>" data-page="<?= (int)$page ?>" data-pages="<?= (int)$pages ?>">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
    <div id="bulkBar" class="bulk-bar d-none">
      <div class="bulk-bar__inner flex gap-8 flex-wrap align-center">
  <strong class="fs-13" id="selSummary">Вибрано: <span id="selCount">0</span></strong>
        <select id="bulkAction" class="fs-13">
          <option value="">Дія...</option>
          <option value="revoke">Відкликати</option>
          <option value="unrevoke">Відновити</option>
          <option value="delete">Видалити</option>
        </select>
  <input type="text" id="bulkReason" class="fs-13 hidden-slot" placeholder="Причина (для відкликання)" maxlength="255" autocomplete="off">
  <button type="button" id="bulkExecute" class="btn btn-sm btn-primary">Виконати</button>
  <button type="button" id="bulkCancel" class="btn btn-sm btn-light">Скасувати</button>
        <span id="bulkProgress" class="fs-12 text-muted"></span>
        <span id="bulkStatus" class="fs-12"></span>
      </div>
    </div>
    <table class="table">
      <thead>
        <tr>
          <th><input type="checkbox" id="chkAll"></th>
          <th><a href="#" class="sort<?= sort_arrow('cid', $sort, $dir) ?>" data-sort="cid">CID</a></th>
          <th><a href="#" class="sort<?= sort_arrow('version', $sort, $dir) ?>" data-sort="version">Версія</a></th>
          <th><a href="#" class="sort<?= sort_arrow('course', $sort, $dir) ?>" data-sort="course">Курс</a></th>
          <th><a href="#" class="sort<?= sort_arrow('grade', $sort, $dir) ?>" data-sort="grade">Оцінка</a></th>
          <th><a href="#" class="sort<?= sort_arrow('issued_date', $sort, $dir) ?>" data-sort="issued_date">Дата</a></th>
          <th><a href="#" class="sort<?= sort_arrow('created_at', $sort, $dir) ?>" data-sort="created_at">Створено</a></th>
          <th><a href="#" class="sort<?= sort_arrow('status', $sort, $dir) ?>" data-sort="status">Статус</a></th>
          <th title="К-сть перевірок / остання"><a href="#" class="sort<?= sort_arrow('lookup_count', $sort, $dir) ?>" data-sort="lookup_count">Переглядів</a></th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($rows as $r): ?>
  <tr class="<?= $r['revoked_at'] ? 'row-revoked':'' ?>" data-cid="<?= htmlspecialchars($r['cid']) ?>" data-created="<?= htmlspecialchars($r['created_at']) ?>" data-status="<?= $r['revoked_at'] ? 'revoked':'active' ?>">
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
              <form class="unrevoke-form mt-4" method="post" action="/api/unrevoke.php">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="cid" value="<?= htmlspecialchars($r['cid']) ?>">
                <button type="submit" class="btn btn-xs btn-light">Відновити</button>
              </form>
            <?php else: ?>
              <span class="badge badge-success">Активний</span>
              <form class="revoke-form mt-4" method="post" action="/api/revoke.php">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="cid" value="<?= htmlspecialchars($r['cid']) ?>">
                <input type="text" name="reason" maxlength="120" class="fs-11 mb-4" placeholder="Причина" autocomplete="off">
                <button type="submit" class="btn btn-xs btn-danger">Відкликати</button>
              </form>
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
  <?php
    // Helper function for smart pagination
    function render_pagination($currentPage, $totalPages, $baseQuery) {
        $delta = 2; // Number of pages to show around the current page
        $range = [];
        for ($i = 1; $i <= $totalPages; $i++) {
            if ($i == 1 || $i == $totalPages || ($i >= $currentPage - $delta && $i <= $currentPage + $delta)) {
                $range[] = $i;
            }
        }

        $withDots = [];
        $last = 0;
        foreach ($range as $page) {
            if (($page - $last) > 1) {
                $withDots[] = '...';
            }
            $withDots[] = $page;
            $last = $page;
        }

        echo '<nav class="pagination">';
        foreach ($withDots as $p) {
            if ($p === '...') {
                echo '<span class="page-dots">...</span>';
            } else {
                $query = http_build_query(array_merge($baseQuery, ['page' => $p]));
                $activeClass = ($p == $currentPage) ? 'active' : '';
                echo "<a class=\"page {$activeClass}\" href=\"?{$query}\">{$p}</a>";
            }
        }
        echo '</nav>';
    }
    ?>
  <?php if($pages > 1):
    render_pagination($page, $pages, ['q' => $q, 'state' => $state, 'sort' => $sort, 'dir' => $dir]);
  ?>
  <?php endif; ?>
</section>
<script src="/assets/js/tokens_page.js"></script>
<?php require_once __DIR__.'/footer.php'; ?>
