<?php
require_once __DIR__.'/header.php';
require_once __DIR__.'/db.php';
$cfg = require __DIR__.'/config.php';

// Новий режим: потрібні обидва параметри id та hash
$hash = $_GET['hash'] ?? '';
$id   = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Базова валідація
if ($id <= 0) {
  echo '<section class="centered"><div class="card"><div class="alert">Невірний або відсутній параметр id.</div></div></section>';
  require_once __DIR__.'/footer.php'; exit;
}
if ($hash === '' || !preg_match('/^[a-f0-9]{64}$/i', $hash)) {
  echo '<section class="centered"><div class="card"><div class="alert">Невірний або відсутній параметр hash.</div></div></section>';
  require_once __DIR__.'/footer.php'; exit;
}

// 1) Шукаємо рядок по hash (як і раніше)
$st = $pdo->prepare("SELECT * FROM data WHERE hash=?");
$st->execute([$hash]);
$row = $st->fetch();

// 2) Перевіряємо, що id з параметра збігається з id знайденого запису
if ($row && (int)$row['id'] === $id) {
    // 3) Перерахунок і звірка детермінованого HMAC (тільки після підтвердження id)
    $dataString = implode('|', [$row['name'],$row['score'],$row['course'],$row['date']]);
    $calc = hash_hmac('sha256', $dataString, $cfg['hash_salt']);

    if (hash_equals($hash, $calc)) {
        ?>
        <section class="centered">
          <div class="card">
            <h1>Сертифікат валідний</h1>
            <p><strong>Ім'я:</strong> <?= htmlspecialchars($row['name']) ?></p>
            <p><strong>Реєстраційний номер:</strong> <?= (int)$row['id'] ?></p>
            <p><strong>Оцінка:</strong> <?= htmlspecialchars($row['score']) ?></p>
            <p><strong>Курс:</strong> <?= htmlspecialchars($row['course']) ?></p>
            <p><strong>Дата:</strong> <?= htmlspecialchars($row['date']) ?></p>
            <?php
            $fileName = sprintf('cert_%d_%s.jpg', (int)$row['id'], $row['hash']);
            $filePath = rtrim($cfg['output_dir'], '/') . '/' . $fileName;
            $fileUrl = '/files/certs/' . rawurlencode($fileName);
            if (is_file($filePath)):
            ?>
              <p class="text-center"><a class="btn btn-primary" href="<?= htmlspecialchars($fileUrl) ?>" download>Завантажити сертифікат</a></p>
            <?php else: ?>
              <p class="card__meta text-center">Сертифікат ще не згенеровано.</p>
            <?php endif; ?>
          </div>
        </section>
        <?php
        require_once __DIR__.'/footer.php'; exit;
    }
}

?>
<section class="centered">
  <div class="card">
    <h1>Сертифікат не підтверджено</h1>
  <p>Невірні параметри або сертифікат не підтверджено.</p>
  </div>
</section>
<?php require_once __DIR__.'/footer.php'; ?>
