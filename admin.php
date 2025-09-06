<?php
$isAdminPage = true;
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

if (!is_admin_logged()):
  ?>
  <section class="centered">
    <div class="card card--narrow">
      <div class="card__logo">
        <div class="logo" style="background-image:url('<?= htmlspecialchars($cfg['logo_path']) ?>')"></div>
      </div>
      <h1 class="card__title">Вхід адміністратора</h1>
      <?php if (!empty($_GET['err'])): ?>
        <div class="alert alert-error">Невірні облікові дані або порожні поля.</div>
      <?php endif; ?>
      <form class="form" method="post" action="/login.php" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
        <label>Логін
          <input type="text" name="username" required>
        </label>
        <label>Пароль
          <input type="password" name="password" required>
        </label>
        <button class="btn btn-primary" type="submit">Увійти</button>
      </form>
    </div>
  </section>
  <?php
  require_once __DIR__ . '/footer.php';
  exit;
endif;

// CSRF токен для захисту форм
$csrf = csrf_token();

// --- після логіну: форма додавання + таблиця з пошуком/сортуванням/пагінацією
$q = trim($_GET['q'] ?? '');
$sort = $_GET['sort'] ?? 'id';
$dir = strtolower($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

// allowed sort keys (note: no 'date' because date values are free-form strings)
$allowedSort = ['id', 'name', 'score', 'course'];
if (!in_array($sort, $allowedSort, true)) {
  $sort = 'id';
}

$where = '';
$params = [];
if ($q !== '') {
  $where = "WHERE (name LIKE :q OR score LIKE :q OR course LIKE :q OR date LIKE :q)";
  $params[':q'] = "%$q%";
}

$total = $pdo->prepare("SELECT COUNT(*) FROM data $where");
$total->execute($params);
$totalRows = (int) $total->fetchColumn();

$sql = "SELECT id,name,score,course,date,hash,revoked_at,revoke_reason FROM data $where ORDER BY $sort $dir LIMIT :lim OFFSET :off";
$st = $pdo->prepare($sql);
foreach ($params as $k => $v)
  $st->bindValue($k, $v);
$st->bindValue(':lim', $perPage, PDO::PARAM_INT);
$st->bindValue(':off', $offset, PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll();
?>
<section class="section">
  <h2 style="margin-top:0">Додати сертифікат</h2>
  <form class="form form-inline" method="post" action="/add_record.php">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
    <input type="text" name="name" placeholder="Ім'я" required>
    <input type="text" name="score" placeholder="Оцінка" required>
    <input type="text" name="course" placeholder="Курс" required>
    <input type="text" name="date" placeholder="Дата" required>
    <button class="btn btn-success" type="submit">Додати</button>
  </form>
</section>

<section class="section">
  <h2>Записи</h2>

  <form class="form form-inline" method="get">
    <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Пошук...">
    <select name="sort">
    <?php foreach ($allowedSort as $s):
      $label = $s === 'id' ? 'Реєстраційний номер' : ($s === 'name' ? "Ім'я" : ($s === 'score' ? 'Оцінка' : 'Курс'));
    ?>
      <option value="<?= $s ?>" <?= $sort === $s ? 'selected' : '' ?>>Сортувати за: <?= $label ?></option>
      <?php endforeach; ?>
    </select>
    <select name="dir">
      <option value="asc" <?= $dir === 'asc' ? 'selected' : '' ?>>↑ зростання</option>
      <option value="desc" <?= $dir === 'desc' ? 'selected' : '' ?>>↓ спадання</option>
    </select>
    <button class="btn" type="submit">Застосувати</button>
  </form>

  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>Реєстраційний<br>номер</th>
          <th>Ім'я</th>
          <th>Оцінка</th>
          <th>Курс</th>
          <th>Дата</th>
          <th>Статус</th>
          <th>Дії</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr class="<?= $r['revoked_at'] ? 'row-revoked' : '' ?>">
            <td><?= (int)$r['id'] ?></td>
            <td><?= htmlspecialchars($r['name']) ?></td>
            <td><?= htmlspecialchars($r['score']) ?></td>
            <td><?= htmlspecialchars($r['course']) ?></td>
            <td><?= htmlspecialchars($r['date']) ?></td>
            <td>
              <?php if ($r['revoked_at']): ?>
                <span class="badge badge-danger" title="<?= htmlspecialchars($r['revoke_reason'] ?? '') ?>">Відкликано</span>
              <?php else: ?>
                <span class="badge badge-success">Активний</span>
              <?php endif; ?>
            </td>
            <td class="text-right"><a class="btn btn-primary btn-sm" href="/record.php?id=<?= (int)$r['id'] ?>">Перегляд</a></td>

          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php
  $pages = max(1, (int) ceil($totalRows / $perPage));
  if ($pages > 1):
    ?>
    <nav class="pagination">
      <?php for ($p = 1; $p <= $pages; $p++): ?>
        <a class="page <?= $p === $page ? 'active' : '' ?>"
          href="?<?= http_build_query(['q' => $q, 'sort' => $sort, 'dir' => $dir, 'page' => $p]) ?>"><?= $p ?></a>
      <?php endfor; ?>
    </nav>
  <?php endif; ?>
</section>

<?php require_once __DIR__ . '/footer.php'; ?>