<?php
require_once __DIR__.'/auth.php';
require_admin();
require_once __DIR__.'/db.php';
$cfg = require __DIR__.'/config.php';
require_once __DIR__.'/lib/phpqrcode.php'; // PHP QR Code

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('Bad id'); }

$st = $pdo->prepare("SELECT * FROM data WHERE id=?");
$st->execute([$id]);
$row = $st->fetch();
if (!$row) { http_response_code(404); exit('Не знайдено'); }

// Детермінований хеш (HMAC-SHA256 із сіллю), щоб checkCert міг перевірити:
// Побудова canonical string залежно від версії алгоритму
$hashVersion = (int)($cfg['hash_version'] ?? 1);
switch ($hashVersion) {
  /* =============================================================
   *  HASH VERSION DISPATCH
   *  -------------------------------------------------------------
   *  Тут визначається canonical string (рядок, який іде у HMAC) для 
   *  поточної версії алгоритму. КОЖНОГО разу, коли ви плануєте 
   *  змінити склад полів або формат (наприклад, додати issuer,
   *  valid_until, id, префікс v2| тощо) — ДОДАЙТЕ НОВИЙ case
   *  (НЕ редагуйте існуючий case 1, щоб не зламати старі сертифікати).
   *
   *  Швидкий чекліст для додавання нової версії (припустимо v2):
   *    1. ДОДАЙТЕ стовпці у таблицю (якщо потрібні: issuer, valid_until ...).
   *    2. ОНОВІТЬ форму вводу / адмінку відповідними полями.
   *    3. ДОДАЙТЕ case 2 нижче з побудовою НОВОГО canonical рядка.
   *    4. В `config.php` підніміть 'hash_version' => 2.
   *    5. У `checkCert.php` теж додайте аналогічний case 2 (ідентичний формат!).
   *    6. Перегенеруйте (за потреби) нові сертифікати — старі лишаються валідними,
   *       бо їх hash_version = 1 і перевірка виконується за старим шаблоном.
   *
   *  ВАЖЛИВО: Canonical рядок має бути суворо детермінований: порядок полів,
   *  регістр, роздільники. Не додавайте пробіли «для краси». 
   *  Рекомендація — явно вставляти префікс версії у нові формати:
   *    v2|name=...|score=...|course=...|date=...|issuer=...|valid_until=...
   *  (префікс полегшує зовнішній аудит і ручне дебагування).
   *
   *  Пошук цього блоку у майбутньому: шукайте ключові слова
   *  "HASH VERSION DISPATCH" або "canonical string".
   * ============================================================= */
  case 1:
  default:
    $dataString = implode('|', [
      (string)$row['name'],
      (string)$row['score'],
      (string)$row['course'],
      (string)$row['date'],
    ]);
    break;
}
$hash = hash_hmac('sha256', $dataString, $cfg['hash_salt']);

// URL для QR (тепер вимагаємо і id, і hash)
$url = "https://{$cfg['site_domain']}/checkCert?id={$id}&hash=".$hash;

// Підготовка зображення-сертифіката
$template = $cfg['template_path'];
if (!is_file($template)) { http_response_code(500); exit('Відсутній шаблон JPG'); }
if (!is_dir($cfg['output_dir'])) { @mkdir($cfg['output_dir'], 0775, true); }

$im = imagecreatefromjpeg($template);
if (!$im) { http_response_code(500); exit('Неможливо відкрити шаблон'); }
$black = imagecolorallocate($im, 0, 0, 0);

// Текст
$coords = $cfg['coords'];
$font = $cfg['font_path'];
if (!is_file($font)) { imagedestroy($im); http_response_code(500); exit('Відсутній шрифт'); }

imagettftext($im, $coords['name']['size'],   $coords['name']['angle'],   $coords['name']['x'],   $coords['name']['y'],   $black, $font, $row['name']);
// Render registration id on the certificate using coords from config
if (isset($coords['id'])) {
  imagettftext($im, $coords['id']['size'], $coords['id']['angle'], $coords['id']['x'], $coords['id']['y'], $black, $font, (string)$id);
}
imagettftext($im, $coords['score']['size'],  $coords['score']['angle'],  $coords['score']['x'],  $coords['score']['y'],  $black, $font, "Оцінка: ".$row['score']);
imagettftext($im, $coords['course']['size'], $coords['course']['angle'], $coords['course']['x'], $coords['course']['y'], $black, $font, "Курс: ".$row['course']);
imagettftext($im, $coords['date']['size'],   $coords['date']['angle'],   $coords['date']['x'],   $coords['date']['y'],   $black, $font, "Дата: ".$row['date']);

// Генерація QR у тимчасовий PNG
$tmpPng = tempnam(sys_get_temp_dir(), 'qr_').'.png';
\QRcode::png($url, $tmpPng, QR_ECLEVEL_M, 6, 2); // масштаб 6, відступ 2

$qrImg = imagecreatefrompng($tmpPng);
if ($qrImg) {
    // масштабувати QR до заданого розміру
    $qrSize = $coords['qr']['size'];
    $qrW = imagesx($qrImg);
    $qrH = imagesy($qrImg);
    $dst = imagecreatetruecolor($qrSize, $qrSize);
    imagealphablending($dst, true);
    imagesavealpha($dst, true);
    imagecopyresampled($dst, $qrImg, 0,0,0,0, $qrSize,$qrSize, $qrW,$qrH);
    imagecopy($im, $dst, $coords['qr']['x'], $coords['qr']['y'], 0,0, $qrSize,$qrSize);
    imagedestroy($dst);
    imagedestroy($qrImg);
}
@unlink($tmpPng);

// Зберегти JPG і оновити hash в БД
$outFile = rtrim($cfg['output_dir'],'/')."/cert_{$id}_{$hash}.jpg";
imagejpeg($im, $outFile, 92);
// Обмежити права на збережений файл (читання власнику та групі web, без решти)
@chmod($outFile, 0640);
imagedestroy($im);

try {
  $up = $pdo->prepare("UPDATE data SET hash=?, hash_version=? WHERE id=?");
  $up->execute([$hash, $hashVersion, $id]);
} catch (PDOException $e) {
  // 23000 = integrity constraint violation (duplicate key, etc.)
  if ($e->getCode() === '23000') {
    http_response_code(409);
    exit('Конфлікт: унікальний хеш вже існує (інший сертифікат із таким самим набором полів).');
  }
  throw $e; // неочікувана помилка
}

// Віддати файл на завантаження
header('Content-Type: image/jpeg');
header('Content-Disposition: attachment; filename="'.basename($outFile).'"');
readfile($outFile);
