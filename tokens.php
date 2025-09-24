<?php
require_once __DIR__.'/auth.php';
// Viewable by both admins and operators; destructive actions remain admin-only via API guards
require_login();
require_csrf();
$isAdminPage = true;
$cfg = require __DIR__.'/config.php';
require_once __DIR__.'/header.php';
require_once __DIR__.'/common_pagination.php';
require_once __DIR__.'/db.php';

$q = trim($_GET['q'] ?? '');
$state = $_GET['state'] ?? '';
$tplFilter = isset($_GET['tpl']) ? trim($_GET['tpl']) : '';
$sort = $_GET['sort'] ?? 'id';
$dir = $_GET['dir'] ?? 'desc';
$page = max(1,(int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page-1)*$perPage;

// Detect actual columns of tokens table once to guard against schema differences
$tokensCols = [];
try {
  $colsStmt = $pdo->query('SHOW COLUMNS FROM tokens');
  foreach(($colsStmt ? $colsStmt->fetchAll(PDO::FETCH_ASSOC) : []) as $cRow){ $tokensCols[strtolower($cRow['Field'])] = true; }
} catch (Throwable $e) { $tokensCols = []; }
$hasCol = function(string $c) use ($tokensCols): bool { return isset($tokensCols[strtolower($c)]); };

// Build allowed sorts dynamically based on existing columns; "status" is virtual
$allowedSorts = ['cid', 'status'];
if($hasCol('id')) $allowedSorts[] = 'id';
if($hasCol('extra_info')) $allowedSorts[] = 'extra_info';
if($hasCol('issued_date')) $allowedSorts[] = 'issued_date';
if($hasCol('created_at')) $allowedSorts[] = 'created_at';
if($hasCol('lookup_count')) $allowedSorts[] = 'lookup_count';
if(!in_array($sort, $allowedSorts, true)) {
  // Prefer created_at if exists, else cid
  $sort = $hasCol('created_at') ? 'created_at' : 'cid';
}
$dir = strtolower($dir) === 'asc' ? 'asc' : 'desc';

$where = '';$params=[];$conds=[];
// Detect optional schema: tokens.template_id, templates table, organizations table, tokens.id
$hasTplIdCol = false; $hasTemplatesTable = false; $hasOrgCol = false; $hasOrgTable = false; $hasIdCol = false; $tplHasName=false; $tplHasCode=false;
try { $c=$pdo->query("SHOW COLUMNS FROM tokens LIKE 'template_id'"); if($c && $c->fetch()) $hasTplIdCol=true; } catch(Throwable $e){}
try { $t=$pdo->query("SHOW TABLES LIKE 'templates'"); if($t && $t->fetch()) $hasTemplatesTable=true; } catch(Throwable $e){}
try { $co=$pdo->query("SHOW COLUMNS FROM tokens LIKE 'org_id'"); if($co && $co->fetch()) $hasOrgCol=true; } catch(Throwable $e){}
try { $to=$pdo->query("SHOW TABLES LIKE 'organizations'"); if($to && $to->fetch()) $hasOrgTable=true; } catch(Throwable $e){}
try { $ci=$pdo->query("SHOW COLUMNS FROM tokens LIKE 'id'"); if($ci && $ci->fetch()) $hasIdCol=true; } catch(Throwable $e){}
// Detect templates columns if templates table exists
if($hasTemplatesTable){
  try { $cn=$pdo->query("SHOW COLUMNS FROM templates LIKE 'name'"); if($cn && $cn->fetch()) $tplHasName=true; } catch(Throwable $e){}
  try { $cc=$pdo->query("SHOW COLUMNS FROM templates LIKE 'code'"); if($cc && $cc->fetch()) $tplHasCode=true; } catch(Throwable $e){}
}
if($q!==''){
  $qConds = ["cid LIKE :q"];
  if($hasCol('extra_info')){ $qConds[] = "extra_info LIKE :q"; }
  $conds[] = '('.implode(' OR ',$qConds).')';
  $params[':q'] = "%$q%";
}
if($state==='active' && $hasCol('revoked_at')){
  $conds[] = "revoked_at IS NULL";
} elseif($state==='revoked' && $hasCol('revoked_at')) {
  $conds[] = "revoked_at IS NOT NULL";
}
if($conds){
  $where = 'WHERE '.implode(' AND ',$conds);
}

// Template filter (if supported)
if($hasTplIdCol && $tplFilter!==''){
  $where .= ($where ? ' AND ' : 'WHERE ').' template_id = :tpl';
  $params[':tpl'] = (int)$tplFilter;
}

// ORDER BY handling with fallbacks; always qualify with tokens. to avoid ambiguity after joins
if ($sort === 'status') {
  $secondary = $hasIdCol ? 'tokens.id' : ($hasCol('created_at') ? 'tokens.created_at' : 'tokens.cid');
  $orderBy = $hasCol('revoked_at')
    ? "ORDER BY tokens.revoked_at IS NOT NULL {$dir}, {$secondary} {$dir}"
    : "ORDER BY {$secondary} {$dir}";
} elseif ($sort === 'id' && !$hasIdCol) {
  // Fallback if tokens.id does not exist
  $fallback = $hasCol('created_at') ? 'tokens.created_at' : 'tokens.cid';
  $orderBy = "ORDER BY {$fallback} {$dir}";
} else {
  // Map known columns to qualified names
  $map = [
    'id' => 'tokens.id',
    'cid' => 'tokens.cid',
    'extra_info' => 'tokens.extra_info',
    'issued_date' => 'tokens.issued_date',
    'created_at' => 'tokens.created_at',
    'lookup_count' => 'tokens.lookup_count',
  ];
  $col = $map[$sort] ?? 'tokens.cid';
  $orderBy = "ORDER BY {$col} {$dir}";
}

// Join templates table to display template label if available
$join = '';
$selTpl = '';
if($hasTplIdCol && $hasTemplatesTable){
  $join = ' LEFT JOIN templates t ON t.id = tokens.template_id';
  // Also join organizations for org_code if available
  if($hasOrgTable){
    $join .= ' LEFT JOIN organizations o ON o.id = t.org_id';
    $selTpl = ', t.id AS tpl_id'
      . ($tplHasName ? ', t.name AS tpl_name' : ', NULL AS tpl_name')
      . ($tplHasCode ? ', t.code AS tpl_code' : ', NULL AS tpl_code')
      . ', o.code AS tpl_org_code';
  } else {
    $selTpl = ', t.id AS tpl_id'
      . ($tplHasName ? ', t.name AS tpl_name' : ', NULL AS tpl_name')
      . ($tplHasCode ? ', t.code AS tpl_code' : ', NULL AS tpl_code')
      . ', NULL AS tpl_org_code';
  }
}

// Build SELECT list based on available columns and provide NULL aliases for missing ones
$selectParts = [
  'tokens.cid'
];
if($hasCol('extra_info')) $selectParts[] = 'tokens.extra_info'; else $selectParts[] = 'NULL AS extra_info';
if($hasCol('issued_date')) $selectParts[] = 'tokens.issued_date'; else $selectParts[] = 'NULL AS issued_date';
if($hasCol('revoked_at')) $selectParts[] = 'tokens.revoked_at'; else $selectParts[] = 'NULL AS revoked_at';
if($hasCol('revoke_reason')) $selectParts[] = 'tokens.revoke_reason'; else $selectParts[] = 'NULL AS revoke_reason';
if($hasCol('created_at')) $selectParts[] = 'tokens.created_at'; else $selectParts[] = 'NULL AS created_at';
if($hasCol('lookup_count')) $selectParts[] = 'tokens.lookup_count'; else $selectParts[] = '0 AS lookup_count';
if($hasCol('last_lookup_at')) $selectParts[] = 'tokens.last_lookup_at'; else $selectParts[] = 'NULL AS last_lookup_at';
$selCore = implode(', ',$selectParts);
$rows = [];
$total = 0;
$queryError = null;
try {
  $totalSt = $pdo->prepare("SELECT COUNT(*) FROM tokens $where");
  foreach($params as $k=>$v) $totalSt->bindValue($k,$v);
  $totalSt->execute();
  $total = (int)$totalSt->fetchColumn();

  $st = $pdo->prepare("SELECT {$selCore}{$selTpl} FROM tokens{$join} $where {$orderBy} LIMIT :lim OFFSET :off");
  foreach($params as $k=>$v) $st->bindValue($k,$v);
  $st->bindValue(':lim',$perPage,PDO::PARAM_INT);
  $st->bindValue(':off',$offset,PDO::PARAM_INT);
  $st->execute();
  $rows = $st->fetchAll();
} catch (Throwable $e) {
  $queryError = $e->getMessage();
  error_log('tokens.php query error: '.$queryError);
  // Fallback: try again without template/organization joins and without extra select fields
  try {
    $st2 = $pdo->prepare("SELECT {$selCore} FROM tokens $where {$orderBy} LIMIT :lim OFFSET :off");
    foreach($params as $k=>$v) $st2->bindValue($k,$v);
    $st2->bindValue(':lim',$perPage,PDO::PARAM_INT);
    $st2->bindValue(':off',$offset,PDO::PARAM_INT);
    $st2->execute();
    $rows = $st2->fetchAll();
    // If fallback returned rows, suppress the alert for better UX
    if (is_array($rows)) { $queryError = null; }
  } catch (Throwable $e2) {
    error_log('tokens.php fallback query error: '.$e2->getMessage());
    // Final minimal fallback: select only CID with safest ORDER BY
    try {
      $safeOrder = 'ORDER BY tokens.cid '.($dir==='asc'?'asc':'desc');
      $st3 = $pdo->prepare("SELECT tokens.cid FROM tokens $where {$safeOrder} LIMIT :lim OFFSET :off");
      foreach($params as $k=>$v) $st3->bindValue($k,$v);
      $st3->bindValue(':lim',$perPage,PDO::PARAM_INT);
      $st3->bindValue(':off',$offset,PDO::PARAM_INT);
      $st3->execute();
      $rows = $st3->fetchAll();
      if (is_array($rows)) {
        // Normalize missing fields to defaults for rendering
        foreach($rows as &$rr){
          $rr += [
            'extra_info'=>null,
            'issued_date'=>null,
            'created_at'=>null,
            'revoked_at'=>null,
            'revoke_reason'=>null,
            'lookup_count'=>0,
            'last_lookup_at'=>null,
            'tpl_id'=>null,
            'tpl_name'=>null,
            'tpl_code'=>null,
            'tpl_org_code'=>null,
          ];
        }
        unset($rr);
        $queryError = null; // suppress alert
      }
    } catch (Throwable $e3) {
      error_log('tokens.php minimal fallback error: '.$e3->getMessage());
    }
  }
}
$pages = max(1,(int)ceil($total/$perPage));
$csrf = csrf_token();

function sort_arrow($column, $currentSort, $currentDir) {
    if ($column === $currentSort) {
        return $currentDir === 'asc' ? ' asc' : ' desc';
    }
    return '';
}
function render_sort_arrow($column, $sort, $dir) {
  // Use attribute-sized SVGs (no inline style) to avoid CSP issues
  if ($sort !== $column) {
    // Neutral (both directions) icon in gray
    return '<svg class="sort-icon" width="12" height="12" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><path d="M8 9l4-4 4 4M8 15l4 4 4-4" stroke="#9ca3af" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  }
  if ($dir === 'asc') {
    // Up arrow filled
    return '<svg class="sort-icon" width="12" height="12" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><path fill="#2563eb" fill-rule="evenodd" d="M14.707 12.707a1 1 0 01-1.414 0L10 9.414l-3.293 3.293a1 1 0 01-1.414-1.414l4-4a1 1 0 011.414 0l4 4a1 1 0 010 1.414z" clip-rule="evenodd"/></svg>';
  }
  // Down arrow filled
  return '<svg class="sort-icon" width="12" height="12" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><path fill="#2563eb" fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>';
}
?>
<section class="section">
  <h2 class="mt-0">Нагороди (анонімна модель)</h2>
  <form id="filterForm" class="form filter-form" method="get">
  <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Пошук CID / дод. інфо">
    <select name="state">
      <option value="" <?= $state===''?'selected':'' ?>>Усі</option>
      <option value="active" <?= $state==='active'?'selected':'' ?>>Активні</option>
      <option value="revoked" <?= $state==='revoked'?'selected':'' ?>>Відкликані</option>
    </select>
    <?php
    // Template filter select (active templates only)
    if($hasTplIdCol && $hasTemplatesTable){
      $tplOpts = [];
      try {
        if($hasOrgTable){
          $cols = 't.id'
            . ($tplHasName ? ', t.name' : ', NULL AS name')
            . ($tplHasCode ? ', t.code' : ', NULL AS code')
            . ', o.code AS org_code';
          $s = $pdo->query("SELECT {$cols} FROM templates t LEFT JOIN organizations o ON o.id=t.org_id WHERE t.status='active' ORDER BY t.id DESC LIMIT 400");
        } else {
          $cols = 't.id'
            . ($tplHasName ? ', t.name' : ', NULL AS name')
            . ($tplHasCode ? ', t.code' : ', NULL AS code')
            . ', NULL AS org_code';
          $s = $pdo->query("SELECT {$cols} FROM templates t WHERE t.status='active' ORDER BY t.id DESC LIMIT 400");
        }
        $tplOpts = $s ? $s->fetchAll(PDO::FETCH_ASSOC) : [];
      } catch(Throwable $e){ $tplOpts = []; }
      echo '<select name="tpl"><option value=""'.($tplFilter===''?' selected':'').'>Шаблон: Усі</option>';
      foreach($tplOpts as $opt){
        $label = (trim($opt['name'] ?? '') !== '' ? $opt['name'] : ($opt['code'] ?? ('T'.$opt['id'])));
        if(!empty($opt['org_code'])) $label .= ' ['.$opt['org_code'].']';
        $sel = ($tplFilter!=='' && (int)$tplFilter===(int)$opt['id']) ? ' selected' : '';
        echo '<option value="'.(int)$opt['id'].'"'.$sel.'>'.htmlspecialchars($label).'</option>';
      }
      echo '</select>';
    }
    ?>
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
          <th><a href="#" class="sort<?= sort_arrow('cid', $sort, $dir) ?>" data-sort="cid">CID <?= render_sort_arrow('cid', $sort, $dir) ?></a></th>
          <th><a href="#" class="sort<?= sort_arrow('extra_info', $sort, $dir) ?>" data-sort="extra_info">Дод. інформація <?= render_sort_arrow('extra_info', $sort, $dir) ?></a></th>
          <th><a href="#" class="sort<?= sort_arrow('issued_date', $sort, $dir) ?>" data-sort="issued_date">Дата <?= render_sort_arrow('issued_date', $sort, $dir) ?></a></th>
          <th><a href="#" class="sort<?= sort_arrow('created_at', $sort, $dir) ?>" data-sort="created_at">Створено <?= render_sort_arrow('created_at', $sort, $dir) ?></a></th>
          <?php if($hasTplIdCol && $hasTemplatesTable): ?><th>Шаблон</th><?php endif; ?>
          <th><a href="#" class="sort<?= sort_arrow('status', $sort, $dir) ?>" data-sort="status">Статус <?= render_sort_arrow('status', $sort, $dir) ?></a></th>
          <th title="Кількість перевірок / остання"><a href="#" class="sort<?= sort_arrow('lookup_count', $sort, $dir) ?>" data-sort="lookup_count">Перевірок <?= render_sort_arrow('lookup_count', $sort, $dir) ?></a></th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($rows as $r): ?>
  <tr class="<?= $r['revoked_at'] ? 'row-revoked':'' ?>" data-cid="<?= htmlspecialchars($r['cid']) ?>" data-created="<?= htmlspecialchars($r['created_at']) ?>" data-status="<?= $r['revoked_at'] ? 'revoked':'active' ?>">
          <td><input type="checkbox" class="rowChk" value="<?= htmlspecialchars($r['cid']) ?>"></td>
          <td class="mono fs-12"><a class="link-plain" href="/token.php?cid=<?= urlencode($r['cid']) ?>"><?= htmlspecialchars($r['cid']) ?></a></td>
          <td><?= htmlspecialchars($r['extra_info'] ?? '') ?></td>
          <td><?= htmlspecialchars($r['issued_date'] ?? '') ?></td>
          <td><?= htmlspecialchars($r['created_at']) ?></td>
          <?php if($hasTplIdCol && $hasTemplatesTable): ?>
            <td class="fs-12">
              <?php if(!empty($r['tpl_id'])): ?>
                <?php $tplLabel = trim($r['tpl_name'] ?? '') !== '' ? $r['tpl_name'] : ($r['tpl_code'] ?? ('T'.(int)$r['tpl_id']));
                if(!empty($r['tpl_org_code'])) $tplLabel .= ' ['.$r['tpl_org_code'].']'; ?>
                <a class="link-plain" href="/template.php?id=<?= (int)$r['tpl_id'] ?>" title="Перейти до шаблону"><?= htmlspecialchars($tplLabel) ?></a>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
          <?php endif; ?>
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
  <?php if(!$queryError && $total===0): ?>
    <div class="mt-16">
      <div class="alert alert-info">
        <strong>Порожньо.</strong> Нічого не знайдено за заданими умовами.
        <?php if($q!=='' || $state!=='' || ($hasTplIdCol && $tplFilter!=='') ): ?>
          <div class="mt-6"><a class="btn btn-light" href="/tokens.php">Скинути фільтри</a></div>
        <?php else: ?>
          <div class="mt-6 fs-13 text-muted">Спробуйте створити перші нагороди на сторінці «Видача нагород».</div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
  <?php // Pagination now provided by common_pagination.php ?>
  <?php if($pages > 1):
    render_pagination($page, $pages, ['q' => $q, 'state' => $state, 'tpl' => $tplFilter, 'sort' => $sort, 'dir' => $dir]);
  ?>
  <?php endif; ?>
  <?php if($queryError): ?>
    <div class="alert alert-error mt-12">Помилка завантаження списку нагород. Перевірте налаштування БД або схему.
      <?php if(function_exists('is_admin') && is_admin()): ?>
        <br><small class="mono"><?= htmlspecialchars($queryError) ?></small>
      <?php else: ?>
        <small class="mono">(деталі у журналі сервера)</small>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</section>
<script src="/assets/js/tokens_page.js"></script>
<?php require_once __DIR__.'/footer.php'; ?>
