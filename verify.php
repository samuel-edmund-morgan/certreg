<?php
$cfg = require __DIR__.'/config.php';
if (!defined('ALLOW_DB_FAIL_SOFT')) { define('ALLOW_DB_FAIL_SOFT', true); }
$p = $_GET['p'] ?? '';
require_once __DIR__.'/db.php';
// We will attempt to detect organization code either from payload canonical fields or fallback to config org_code
// and set $forced_org_id before including header.php for per-org branding.
$forced_org_id = null;
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
      else {
        // Try to parse ORG code: if payload includes 'org' or 'ORG' or inside canonical string 'ORG=' pattern.
        $orgCode = null;
        if(isset($payload['org']) && is_string($payload['org'])) $orgCode = $payload['org'];
        elseif(isset($payload['ORG']) && is_string($payload['ORG'])) $orgCode = $payload['ORG'];
        elseif(isset($payload['canon']) && is_string($payload['canon'])){
          if(preg_match('/\bORG=([A-Z0-9\-_.]+)/i',$payload['canon'],$m)){ $orgCode = $m[1]; }
        }
        // Fallback to config org_code if nothing extracted
        if(!$orgCode) $orgCode = $cfg['org_code'] ?? null;
        if($orgCode){
          try {
            $stOrg = $pdo->prepare('SELECT id FROM organizations WHERE code=? AND is_active=1 LIMIT 1');
            $stOrg->execute([$orgCode]);
            $oid = $stOrg->fetchColumn();
            if($oid){ $forced_org_id = (int)$oid; }
          } catch(Throwable $ie){ /* ignore lookup failures */ }
        }
      }
  }
} else { $err='Відсутній параметр.'; }
require __DIR__.'/header.php';
?>
<section class="centered centered--top">
  <div class="card card--narrow">
  <h1 class="card__title mt-0 fs-22">Перевірка нагороди</h1>
    <?php if($err): ?>
      <div class="alert alert-error mt-4 mb-0"><?= htmlspecialchars($err) ?></div>
    <?php else: ?>
      <div id="existBox" class="alert alert-info">Перевірка реєстраційного номера…</div>
      <form id="ownForm" class="form d-none mt-14" autocomplete="off">
        <label class="mb-12">ПІБ для підтвердження
          <input type="text" name="pib" placeholder="Введіть ПІБ як на нагороді" required autocomplete="off">
        </label>
        <div class="text-center mb-8"><button class="btn btn-primary" type="submit">Перевірити належність</button></div>
      </form>
      <div id="ownResult" class="mt-14"></div>
      <details class="mt-18">
        <summary class="pointer fw-600">Технічні дані</summary>
  <div class="fs-13 lh-14 mt-8" id="techData">
          <strong>CID:</strong> <span id="cidOut"></span><br>
          <strong>Версія:</strong> <span id="verOut"></span><br>
          <strong>Назва нагороди:</strong> <span id="awardOut"></span><br>
          <strong>Організація:</strong> <span id="orgOut"></span><br>
          <strong>Сіль (base64url):</strong> <code class="code-box mt-2" id="saltOut"></code>
          <strong>Дата:</strong> <span id="dateOut"></span><br>
          <strong>Канон (URL):</strong> <span id="canonOut"></span><br>
          <strong>Додаткова інформація:</strong> <span id="extraOut"></span><br>
          <strong>H (з сервера):</strong> <code class="code-box mt-2" id="hashOut"></code>
          <strong>Integrity (INT):</strong> <span id="intOut" class="mono"></span>
        </div>
      </details>
  <script src="/assets/js/verify.js"></script>
    <?php endif; ?>
  </div>
</section>
<?php require __DIR__.'/footer.php'; ?>
