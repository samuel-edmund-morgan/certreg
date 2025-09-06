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
?><!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8">
  <title>Перевірка сертифіката</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="/assets/css/styles.css">
  <style>
    body{max-width:720px;margin:0 auto;padding:1rem}
    .status-box{padding:.75rem 1rem;border-radius:10px;margin-top:1rem;font-size:14px}
    .status-ok{background:#ecfdf5;border:1px solid #6ee7b7}
    .status-bad{background:#fee2e2;border:1px solid #fecaca}
    code.small{font-size:12px;word-break:break-all;display:block;margin-top:4px}
    form.verify-name{margin-top:1.25rem;display:flex;gap:.5rem;flex-wrap:wrap}
    form.verify-name input{flex:1;min-width:240px}
  </style>
</head>
<body>
  <h1 style="margin-top:0">Перевірка сертифіката</h1>
  <?php if($err): ?>
    <div class="status-box status-bad"><?= htmlspecialchars($err) ?></div>
  <?php else: ?>
    <div id="existBox" class="status-box">Перевірка реєстраційного номера…</div>
    <form id="ownForm" class="verify-name" autocomplete="off" style="display:none">
      <input type="text" name="pib" placeholder="Введіть ПІБ як на сертифікаті" required autocomplete="off">
      <button class="btn" type="submit">Перевірити належність</button>
    </form>
    <div id="ownResult"></div>
    <details style="margin-top:1.5rem">
      <summary style="cursor:pointer">Технічні дані</summary>
      <div style="font-size:13px;line-height:1.4;margin-top:.5rem">
        <strong>CID:</strong> <span id="cidOut"></span><br>
        <strong>Версія:</strong> <span id="verOut"></span><br>
        <strong>Сіль (base64url):</strong> <code class="small" id="saltOut"></code><br>
        <strong>Курс:</strong> <span id="courseOut"></span><br>
        <strong>Оцінка:</strong> <span id="gradeOut"></span><br>
        <strong>Дата:</strong> <span id="dateOut"></span><br>
        <strong>H (з сервера):</strong> <code class="small" id="hashOut"></code><br>
      </div>
    </details>
    <script>
      const payload = <?= json_encode($payload, JSON_UNESCAPED_UNICODE) ?>;
      const statusUrl = '/api/status.php?cid=' + encodeURIComponent(payload.cid);
      const existBox = document.getElementById('existBox');
      const ownForm = document.getElementById('ownForm');
      const ownResult = document.getElementById('ownResult');
      const cidOut = document.getElementById('cidOut');
      const verOut = document.getElementById('verOut');
      const saltOut = document.getElementById('saltOut');
      const courseOut = document.getElementById('courseOut');
      const gradeOut = document.getElementById('gradeOut');
      const dateOut = document.getElementById('dateOut');
      const hashOut = document.getElementById('hashOut');
      cidOut.textContent = payload.cid;
      verOut.textContent = payload.v;
      saltOut.textContent = payload.s;
      courseOut.textContent = payload.course || '';
      gradeOut.textContent = payload.grade || '';
      dateOut.textContent = payload.date || '';
      function normName(s){return s.normalize('NFC').replace(/[\u2019'`’]/g,'').replace(/\s+/g,' ').trim().toUpperCase();}
      function b64urlToBytes(b64){b64=b64.replace(/-/g,'+').replace(/_/g,'/');const pad=b64.length%4;if(pad)b64+='='.repeat(4-pad);const bin=atob(b64);const out=new Uint8Array(bin.length);for(let i=0;i<bin.length;i++)out[i]=bin.charCodeAt(i);return out;}
      async function hmac(keyBytes,msg){const key=await crypto.subtle.importKey('raw',keyBytes,{name:'HMAC',hash:'SHA-256'},false,['sign']);const sig=await crypto.subtle.sign('HMAC',key,new TextEncoder().encode(msg));return new Uint8Array(sig);} 
      function toHex(bytes){return Array.from(bytes).map(b=>b.toString(16).padStart(2,'0')).join('');}
      fetch(statusUrl).then(r=>r.json()).then(js=>{
        if(!js.exists){ existBox.className='status-box status-bad'; existBox.textContent='Реєстраційний номер не знайдено.'; return; }
        hashOut.textContent = js.h;
        if(js.revoked){ existBox.className='status-box status-bad'; existBox.textContent='Запис існує, але СЕРТИФІКАТ ВІДКЛИКАНО.'; }
        else { existBox.className='status-box status-ok'; existBox.textContent='Реєстраційний номер існує, сертифікат чинний.'; }
        ownForm.style.display='flex';
        ownForm.addEventListener('submit', async ev=>{
          ev.preventDefault(); ownResult.textContent='';
          const pib = normName(ownForm.pib.value); if(!pib) return;
          const canonical = `v${payload.v}|${pib}|${payload.course}|${payload.grade}|${payload.date}`;
          try {
            const calc = await hmac(b64urlToBytes(payload.s), canonical);
            const cmp = toHex(calc);
            if(cmp===js.h){ ownResult.innerHTML='<div class="status-box status-ok">Так, сертифікат належить зазначеній особі.</div>'; }
            else { ownResult.innerHTML='<div class="status-box status-bad">Не збігається. Імʼя/формат не відповідає сертифікату.</div>'; }
          } catch(e){ ownResult.innerHTML='<div class="status-box status-bad">Помилка обчислення.</div>'; }
        });
      }).catch(()=>{ existBox.className='status-box status-bad'; existBox.textContent='Помилка запиту.'; });
    </script>
  <?php endif; ?>
</body>
</html>
