<?php
require_once __DIR__.'/header.php';
require_once __DIR__.'/db.php';
$cfg = require __DIR__.'/config.php';

$hash = $_GET['hash'] ?? '';
// Валідація параметра hash: очікуємо рівно 64 hex-символи (SHA-256 HMAC)
if ($hash === '' || !preg_match('/^[a-f0-9]{64}$/i', $hash)) {
  echo '<section class="centered"><div class="card"><div class="alert">Невірний або відсутній параметр hash.</div></div></section>';
  require_once __DIR__.'/footer.php'; exit;
}

$st = $pdo->prepare("SELECT * FROM data WHERE hash=?");
$st->execute([$hash]);
$row = $st->fetch();

if ($row) {
    // Перерахунок і звірка детермінованого HMAC
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
            // Provide download link if the generated certificate file exists
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
    <p>Невірний або прострочений хеш.</p>
  </div>
</section>
<?php require_once __DIR__.'/footer.php'; ?>
