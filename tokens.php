<?php
require_once __DIR__.'/auth.php';
require_admin();
$isAdminPage = true;
$cfg = require __DIR__.'/config.php';
require_once __DIR__.'/header.php';
require_once __DIR__.'/db.php';

$q = trim($_GET['q'] ?? '');
$page = max(1,(int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page-1)*$perPage;

$where = '';$params=[];
if($q!==''){
  $where = "WHERE (cid LIKE :q OR course LIKE :q OR grade LIKE :q)";
  $params[':q'] = "%$q%";
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
  <form class="form form-inline" method="get" style="margin-bottom:12px">
    <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Пошук CID / курс / оцінка">
    <button class="btn" type="submit">Пошук</button>
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
          <th>Дії</th>
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
          <td>
            <?php if(!$r['revoked_at']): ?>
              <form class="revoke-form" method="post" action="/api/revoke.php" style="display:flex;gap:4px;align-items:center">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="cid" value="<?= htmlspecialchars($r['cid']) ?>">
                <input type="text" name="reason" placeholder="Причина" style="width:140px" maxlength="120">
                <button class="btn btn-danger btn-sm" type="submit">Відкликати</button>
              </form>
            <?php else: ?>
              <div style="display:flex;flex-direction:column;gap:4px">
                <small style="font-size:11px;opacity:.7;max-width:160px;word-break:break-word"><?= htmlspecialchars($r['revoke_reason'] ?? '') ?></small>
                <form class="unrevoke-form" method="post" action="/api/unrevoke.php" style="display:flex;gap:4px;align-items:center">
                  <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                  <input type="hidden" name="cid" value="<?= htmlspecialchars($r['cid']) ?>">
                  <button class="btn btn-light btn-sm" type="submit" title="Скасувати відкликання">Відновити</button>
                </form>
              </div>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if($pages>1): ?>
    <nav class="pagination">
      <?php for($p=1;$p<=$pages;$p++): ?>
        <a class="page <?= $p===$page?'active':'' ?>" href="?<?= http_build_query(['q'=>$q,'page'=>$p]) ?>"><?= $p ?></a>
      <?php endfor; ?>
    </nav>
  <?php endif; ?>
</section>
<script>
function bindAjax(formSelector, confirmText){
  document.querySelectorAll(formSelector).forEach(f=>{
    f.addEventListener('submit', async e=>{
      e.preventDefault();
      if(confirmText && !confirm(confirmText)) return;
      const fd = new FormData(f);
      const res = await fetch(f.action,{method:'POST',body:fd});
      if(!res.ok){ alert('Помилка запиту'); return; }
      const js = await res.json();
      if(js.ok){ location.reload(); } else { alert('Не вдалося: '+(js.error||'??')); }
    });
  });
}
bindAjax('.revoke-form','Відкликати цей токен?');
bindAjax('.unrevoke-form','Скасувати відкликання і зробити активним?');
</script>
<?php require_once __DIR__.'/footer.php'; ?>