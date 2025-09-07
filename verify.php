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
  const intOut = document.getElementById('intOut');
        cidOut.textContent = payload.cid;
        verOut.textContent = payload.v;
        saltOut.textContent = payload.s;
        courseOut.textContent = payload.course || '';
        gradeOut.textContent = payload.grade || '';
        dateOut.textContent = payload.date || '';
  function normName(s){return s.normalize('NFC').replace(/[\u2019'`’\u02BC]/g,'').replace(/\s+/g,' ').trim().toUpperCase();}
  const HOMO_LATIN=/[TOCtoc]/; const CYR=/[\u0400-\u04FF]/;
  function hasHomoglyphRisk(raw){ if(!HOMO_LATIN.test(raw)) return false; if(!CYR.test(raw)) return false; for(const ch of raw){ if('TOCtoc'.includes(ch) && ch.charCodeAt(0)<128) return true; } return false; }
  const mismatchAttempts=[]; // локальний журнал невдалих спроб (не відправляється)
        function b64urlToBytes(b64){b64=b64.replace(/-/g,'+').replace(/_/g,'/');const pad=b64.length%4;if(pad)b64+='='.repeat(4-pad);const bin=atob(b64);const out=new Uint8Array(bin.length);for(let i=0;i<bin.length;i++)out[i]=bin.charCodeAt(i);return out;}
        async function hmac(keyBytes,msg){const key=await crypto.subtle.importKey('raw',keyBytes,{name:'HMAC',hash:'SHA-256'},false,['sign']);const sig=await crypto.subtle.sign('HMAC',key,new TextEncoder().encode(msg));return new Uint8Array(sig);} 
        function toHex(bytes){return Array.from(bytes).map(b=>b.toString(16).padStart(2,'0')).join('');}
        fetch(statusUrl).then(r=>r.json()).then(js=>{
          if(!js.exists){ existBox.className='alert alert-error'; existBox.textContent='Реєстраційний номер не знайдено.'; return; }
          hashOut.textContent = js.h;
          const shortCode = js.h.slice(0,10).toUpperCase().replace(/(.{5})(.{5})/,'$1-$2');
          intOut.textContent = shortCode;
          if(js.revoked){
            existBox.className='alert alert-error';
            function escapeHtml(str){ return String(str).replace(/[&<>"']/g, s=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[s])); }
            function fmtDate(str){
              if(!str) return '';
              const m = str.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
              if(!m) return escapeHtml(str);
              return `${m[3]}.${m[2]}.${m[1]}, ${m[4]}:${m[5]}`;
            }
            const dateLine = js.revoked_at ? `<p><strong>Дата відкликання:</strong> ${fmtDate(js.revoked_at)}</p>` : '';
            const reasonLine = `<p><strong>Причина:</strong> ${js.revoke_reason ? escapeHtml(js.revoke_reason) : '<em>(не вказано)</em>'}</p>`;
            existBox.innerHTML = `<p>Сертифікат існує, але <strong style="color:#b91c1c">ВІДКЛИКАНО</strong>.</p>${dateLine}${reasonLine}`;
            ownForm.style.display='none';
          }
          else {
            existBox.className='alert';
            existBox.style.background='#ecfdf5';
            existBox.style.border='1px solid #6ee7b7';
            existBox.textContent='Реєстраційний номер існує, сертифікат чинний. INT '+shortCode;
            ownForm.style.display='block';
          }
          ownForm.addEventListener('submit', async ev=>{
            ev.preventDefault(); ownResult.textContent='';
            const pib = normName(ownForm.pib.value); if(!pib) return;
            const canonical = `v${payload.v}|${pib}|${payload.course}|${payload.grade}|${payload.date}`;
            try {
              const calc = await hmac(b64urlToBytes(payload.s), canonical);
              const cmp = toHex(calc);
              if(hasHomoglyphRisk(ownForm.pib.value)){
                ownResult.innerHTML='<div class="alert alert-error">Можливі латинські символи T/O/C у поєднанні з кирилицею. Перевірте, що використано кириличні Т/О/С.</div>';
                return;
              }
              if(cmp===js.h){ ownResult.innerHTML='<div class="alert" style="background:#ecfdf5;border:1px solid #6ee7b7">Так, сертифікат належить зазначеній особі.</div>'; }
              else {
                mismatchAttempts.push({raw:ownForm.pib.value,time:Date.now(),norm: pib});
                ownResult.innerHTML='<div class="alert alert-error">Не збігається. Імʼя/формат не відповідає сертифікату.<br><small>Нормалізований варіант: <code>'+pib+'</code></small></div>';
              }
            } catch(e){ ownResult.innerHTML='<div class="alert alert-error">Помилка обчислення.</div>'; }
          });
        }).catch(()=>{ existBox.className='alert alert-error'; existBox.textContent='Помилка запиту.'; });
      </script>
    <?php endif; ?>
  </div>
</section>
<?php require __DIR__.'/footer.php'; ?>
