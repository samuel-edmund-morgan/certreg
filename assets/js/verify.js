(function(){
  const payloadScriptData = (function(){
    // Extract payload from PHP-rendered JSON embedded as data attribute if added later.
    // Fallback: parse from URL parameter p here (defensive) to avoid inline JSON.
    const params = new URLSearchParams(location.search);
    const p = params.get('p');
    if(!p) return null;
    function base64url_decode(d){
      d = d.replace(/-/g,'+').replace(/_/g,'/');
      const pad = d.length % 4; if(pad) d += '='.repeat(4-pad);
      try { return JSON.parse(atob(d)); } catch(e){ return null; }
    }
    return base64url_decode(p);
  })();
  if(!payloadScriptData) return; // page already shows error server-side if needed
  const payload = payloadScriptData;
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
  function text(el, v){ if(el) el.textContent = v; }
  text(cidOut, payload.cid);
  text(verOut, payload.v);
  text(saltOut, payload.s);
  text(courseOut, payload.course || '');
  text(gradeOut, payload.grade || '');
  text(dateOut, payload.date || '');
  function normName(s){return s.normalize('NFC').replace(/[\u2019'`’\u02BC]/g,'').replace(/\s+/g,' ').trim().toUpperCase();}
  const HOMO_LATIN=/[TOCtoc]/; const CYR=/[\u0400-\u04FF]/;
  function hasHomoglyphRisk(raw){ if(!HOMO_LATIN.test(raw)) return false; if(!CYR.test(raw)) return false; for(const ch of raw){ if('TOCtoc'.includes(ch) && ch.charCodeAt(0)<128) return true; } return false; }
  const mismatchAttempts=[];
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
      function escapeHtml(str){ return String(str).replace(/[&<>"']/g, s=>({"&":"&amp;","<":"&lt;","\">":"&gt;","\"":"&quot;","'":"&#39;"}[s])); }
      function fmtDate(str){
        if(!str) return '';
        const m = str.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
        if(!m) return escapeHtml(str);
        return `${m[3]}.${m[2]}.${m[1]}, ${m[4]}:${m[5]}`;
      }
      const dateLine = js.revoked_at ? `<p><strong>Дата відкликання:</strong> ${fmtDate(js.revoked_at)}</p>` : '';
      const reasonLine = `<p><strong>Причина:</strong> ${js.revoke_reason ? escapeHtml(js.revoke_reason) : '<em>(не вказано)</em>'}</p>`;
      existBox.innerHTML = `<p>Сертифікат існує, але <strong style="color:#b91c1c">ВІДКЛИКАНО</strong>.</p>${dateLine}${reasonLine}`;
      if(ownForm) ownForm.style.display='none';
    } else {
      existBox.className='alert';
      existBox.style.background='#ecfdf5';
      existBox.style.border='1px solid #6ee7b7';
      existBox.textContent='Реєстраційний номер існує, сертифікат чинний. INT '+shortCode;
      if(ownForm) ownForm.style.display='block';
    }
    if(ownForm){
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
    }
  }).catch(()=>{ existBox.className='alert alert-error'; existBox.textContent='Помилка запиту.'; });
})();
