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
  // Перевірка на відкликання
  if (!empty($row['revoked_at'])) {
    // Форматувати дату у вигляді: 06.09.2025, 18:55:21
    $revRaw = $row['revoked_at'];
    $revFmt = $revRaw;
    $ts = strtotime($revRaw);
    if ($ts) {
      $revFmt = date('d.m.Y, H:i:s', $ts);
    }
    echo '<section class="centered"><div class="card"><h1>Сертифікат відкликано</h1><p><strong>Причина:</strong> '.htmlspecialchars($row['revoke_reason'] ?? '—').'</p><p><strong>Дата відкликання:</strong> '.htmlspecialchars($revFmt).'</p></div></section>';
    require_once __DIR__.'/footer.php'; exit;
  }
    // 3) Перерахунок і звірка детермінованого HMAC (тільки після підтвердження id)
  $version = isset($row['hash_version']) ? (int)$row['hash_version'] : 1;
  switch ($version) {
    /* =============================================================
     *  HASH VERSION DISPATCH (VERIFICATION SIDE)
     *  -------------------------------------------------------------
     *  Дзеркальний блок до generate_cert.php. Будь-яку нову версію
     *  (case 2, 3, ...) треба додати тут з ТОЧНО ТИМ ЖЕ форматом
     *  canonical рядка, що використано при генерації.
     *
     *  Процедура додавання нової версії v2 (нагадування):
     *    1. Додати стовпці/поля, що потрібні (issuer, valid_until ...).
     *    2. Додати case 2 в generate_cert.php і тут.
     *    3. Підняти 'hash_version' у config.php до 2.
     *    4. НЕ змінювати case 1 — старі сертифікати мають лишитись валідними.
     *    5. Перевірити, що HMAC сходиться для тестового сертифіката v2.
     *
     *  Порада: у нових версіях додайте явний префікс (v2| ... ) для
     *  прозорості та спрощення аудиту/логування.
     *
     *  Пошук цього блоку: "HASH VERSION DISPATCH (VERIFICATION SIDE)".
     * ============================================================= */
    case 1:
    default:
      $dataString = implode('|', [$row['name'],$row['score'],$row['course'],$row['date']]);
      break;
  }
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
