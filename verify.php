<?php
$cfg = require __DIR__.'/config.php';
$p = $_GET['p'] ?? '';
function base64url_decode($d){
  $d = strtr($d,'-_','+/');
  $pad = strlen($d)%4; if($pad){ $d .= str_repeat('=',4-$pad);} return base64_decode($d,true);
}
$payloadJson = null; $payload = null; $err=null;
if($p){
  $payloadJson = base64url_decode($p);
  if($payloadJson===false){ $err='Некоректний параметр.'; }
  else{
    $payload = json_decode($payloadJson,true);
    if(!is_array($payload)||!isset($payload['cid'],$payload['s'],$payload['v'])){ $err='Пошкоджені дані.'; }
  }
} else { $err='Відсутній параметр.'; }
require __DIR__.'/header.php';
?>
<section class="centered centered--top">
  <div class="card card--narrow">
  <h1 class="card__title mt-0 fs-22">Перевірка сертифіката</h1>
    <?php if($err): ?>
      <div class="alert alert-error mt-4 mb-0"><?= htmlspecialchars($err) ?></div>
    <?php else: ?>
      <div id="existBox" class="alert alert-info">Перевірка реєстраційного номера…</div>
      <form id="ownForm" class="form d-none mt-14" autocomplete="off">
        <label class="mb-12">ПІБ для підтвердження
          <input type="text" name="pib" placeholder="Введіть ПІБ як на сертифікаті" required autocomplete="off">
        </label>
        <div class="text-center mb-8"><button class="btn btn-primary" type="submit">Перевірити належність</button></div>
      </form>
      <div id="ownResult" class="mt-14"></div>
      <details class="mt-18">
        <summary class="pointer fw-600">Технічні дані</summary>
        <div class="fs-13 lh-14 mt-8">
          <strong>CID:</strong> <span id="cidOut"></span><br>
          <strong>Версія:</strong> <span id="verOut"></span><br>
          <strong>Сіль (base64url):</strong> <code class="code-box mt-2" id="saltOut"></code>
          <strong>Курс:</strong> <span id="courseOut"></span><br>
          <strong>Оцінка:</strong> <span id="gradeOut"></span><br>
          <strong>Дата:</strong> <span id="dateOut"></span><br>
          <strong>H (з сервера):</strong> <code class="code-box mt-2" id="hashOut"></code>
          <strong>Integrity (INT):</strong> <span id="intOut" class="mono"></span>
        </div>
      </details>
  <script src="/assets/js/verify.js"></script>
    <?php endif; ?>
  </div>
</section>
<?php require __DIR__.'/footer.php'; ?>
