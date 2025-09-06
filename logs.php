<?php
require_once __DIR__.'/auth.php';
require_admin();
require_once __DIR__.'/db.php';
$cfg = require __DIR__.'/config.php';
$isAdminPage = true;

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 100;
$offset = ($page-1)*$perPage;
// Sorting
$sort = $_GET['sort'] ?? 'id';
$dir  = strtolower($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$allowedSort = ['id','requested_id','data_id','status','created_at'];
if (!in_array($sort,$allowedSort,true)) { $sort='id'; }
// Mapping for display labels
$sortLabels = [
  'id'=>'ID',
  'requested_id'=>'ID запиту',
  'data_id'=>'ID запису',
  'status'=>'Статус',
  'created_at'=>'Час'
];

$filter = trim($_GET['q'] ?? '');
$where = '';
$params = [];
if ($filter !== '') {
  $where = "WHERE (requested_hash LIKE :q OR status LIKE :q OR remote_ip LIKE :q)";
  $params[':q'] = "%$filter%";
}

$total = $pdo->prepare("SELECT COUNT(*) FROM verification_logs $where");
$total->execute($params);
$totalRows = (int)$total->fetchColumn();

$order = "$sort $dir";
$st = $pdo->prepare("SELECT * FROM verification_logs $where ORDER BY $order LIMIT :lim OFFSET :off");
foreach ($params as $k=>$v) $st->bindValue($k,$v);
$st->bindValue(':lim',$perPage, PDO::PARAM_INT);
$st->bindValue(':off',$offset, PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll();

require_once __DIR__.'/header.php';
?>
<section class="section" style="display:flex;flex-direction:column;gap:12px">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
  <h2 style="margin:0">Журнал перевірок сертифікатів</h2>
    <a class="btn" href="/admin.php">← Повернутись</a>
  </div>
  <form method="get" class="form form-inline" style="margin-bottom:12px;gap:6px;align-items:flex-end">
    <input type="text" name="q" value="<?= htmlspecialchars($filter) ?>" placeholder="Пошук (hash / IP / status)">
    <label style="display:flex;flex-direction:column;font-size:12px;gap:2px">Стовпець
      <select name="sort">
        <?php foreach ($allowedSort as $s): ?>
          <option value="<?= $s ?>" <?= $sort===$s?'selected':'' ?>><?= $sortLabels[$s] ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label style="display:flex;flex-direction:column;font-size:12px;gap:2px">Напрямок
      <select name="dir">
        <option value="desc" <?= $dir==='desc'?'selected':'' ?>>спадання</option>
        <option value="asc" <?= $dir==='asc'?'selected':'' ?>>зростання</option>
      </select>
    </label>
    <button class="btn" type="submit">Застосувати</button>
  </form>
  <div class="table-wrap">
  <table class="table">
      <thead>
        <tr>
  <th><a title="ID запису журналу – унікальний номер" href="?<?= htmlspecialchars(http_build_query(['q'=>$filter,'sort'=>'id','dir'=>$sort==='id'&&$dir==='asc'?'desc':'asc','page'=>$page])) ?>">ID</a></th>
  <th><a title="ID запиту – значення параметра id із URL перевірки (що ввів користувач)" href="?<?= htmlspecialchars(http_build_query(['q'=>$filter,'sort'=>'requested_id','dir'=>$sort==='requested_id'&&$dir==='asc'?'desc':'asc','page'=>$page])) ?>">ID запиту</a></th>
  <th title="Хеш запиту – hash із URL, який перевірявся">Хеш запиту</th>
  <th><a title="ID запису – реальний ID сертифіката в таблиці data (якщо знайдений)" href="?<?= htmlspecialchars(http_build_query(['q'=>$filter,'sort'=>'data_id','dir'=>$sort==='data_id'&&$dir==='asc'?'desc':'asc','page'=>$page])) ?>">ID запису</a></th>
  <th><a title="Статус перевірки (success, bad_hash, not_found, bad_id, revoked)" href="?<?= htmlspecialchars(http_build_query(['q'=>$filter,'sort'=>'status','dir'=>$sort==='status'&&$dir==='asc'?'desc':'asc','page'=>$page])) ?>">Статус</a></th>
  <th title="Чи був сертифікат відкликаний на момент перевірки">Відкликано</th>
  <th title="IP адреса клієнта">IP</th>
  <th title="User-Agent браузера / клієнта">Агент</th>
  <th><a title="Час фіксації події" href="?<?= htmlspecialchars(http_build_query(['q'=>$filter,'sort'=>'created_at','dir'=>$sort==='created_at'&&$dir==='asc'?'desc':'asc','page'=>$page])) ?>">Час</a></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= (int)$r['id'] ?></td>
            <td><?= htmlspecialchars($r['requested_id']) ?></td>
            <td style="font-family:monospace;font-size:12px;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= htmlspecialchars($r['requested_hash']) ?>"><?= htmlspecialchars($r['requested_hash']) ?></td>
            <td><?= htmlspecialchars($r['data_id']) ?></td>
            <td><?= htmlspecialchars($r['status']) ?></td>
            <td><?= $r['revoked'] ? 'yes' : 'no' ?></td>
            <td><?= htmlspecialchars($r['remote_ip']) ?></td>
            <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis" title="<?= htmlspecialchars($r['user_agent']) ?>"><?= htmlspecialchars($r['user_agent']) ?></td>
            <td><?= htmlspecialchars($r['created_at']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php $pages = max(1,(int)ceil($totalRows/$perPage)); if ($pages>1): ?>
    <nav class="pagination">
      <?php for ($p=1;$p<=$pages;$p++): ?>
        <a class="page <?= $p===$page?'active':'' ?>" href="?<?= http_build_query(['q'=>$filter,'page'=>$p]) ?>"><?= $p ?></a>
      <?php endfor; ?>
    </nav>
  <?php endif; ?>
</section>
<?php require_once __DIR__.'/footer.php'; ?>
