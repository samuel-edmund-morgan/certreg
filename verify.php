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
<section class="centered">
  <div class="card card--narrow">
    <h1 class="card__title" style="margin:0 0 8px;font-size:22px">Перевірка сертифіката</h1>
    <?php if($err): ?>
      <div class="alert alert-error" style="margin-top:4px;margin-bottom:0"><?= htmlspecialchars($err) ?></div>
    <?php else: ?>
      <div id="existBox" class="alert" style="background:#f1f5f9;border:1px solid #e2e8f0">Перевірка реєстраційного номера…</div>
      <form id="ownForm" class="form" autocomplete="off" style="display:none;margin-top:14px">
        <label style="margin-bottom:12px">ПІБ для підтвердження
          <input type="text" name="pib" placeholder="Введіть ПІБ як на сертифікаті" required autocomplete="off">
        </label>
        <div class="text-right"><button class="btn btn-primary" type="submit">Перевірити належність</button></div>
      </form>
      <div id="ownResult" style="margin-top:12px"></div>
      <details style="margin-top:18px">
        <summary style="cursor:pointer;font-weight:600">Технічні дані</summary>
        <div style="font-size:13px;line-height:1.4;margin-top:.5rem">
          <strong>CID:</strong> <span id="cidOut"></span><br>
          <strong>Версія:</strong> <span id="verOut"></span><br>
          <strong>Сіль (base64url):</strong> <code style="font-size:11px;word-break:break-all;display:block;margin-top:2px" id="saltOut"></code>
          <strong>Курс:</strong> <span id="courseOut"></span><br>
          <strong>Оцінка:</strong> <span id="gradeOut"></span><br>
          <strong>Дата:</strong> <span id="dateOut"></span><br>
          <strong>H (з сервера):</strong> <code style="font-size:11px;word-break:break-all;display:block;margin-top:2px" id="hashOut"></code>
          <strong>Integrity (INT):</strong> <span id="intOut" style="font-family:monospace"></span>
        </div>
      </details>
  <script src="/assets/js/verify.js"></script>
    <?php endif; ?>
  </div>
</section>
<?php require __DIR__.'/footer.php'; ?>
