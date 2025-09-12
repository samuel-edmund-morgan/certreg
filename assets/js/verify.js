(function(){
  // Robust UTF-8 aware base64url decode (original encoding used btoa(unescape(encodeURIComponent(str))))
  function escapeHtml(str){ return String(str).replace(/[&<>"']/g, s=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[s])); }
  function decodePayloadParam(){
    const params = new URLSearchParams(location.search);
    const p = params.get('p'); if(!p) return null;
    let b64 = p.replace(/-/g,'+').replace(/_/g,'/');
    const pad = b64.length % 4; if(pad) b64 += '='.repeat(4-pad);
    try {
      const bin = atob(b64);
      // Convert binary string to Uint8Array then UTF-8 decode
      const bytes = new Uint8Array(bin.length);
      for(let i=0;i<bin.length;i++) bytes[i] = bin.charCodeAt(i);
      // Support browsers without TextDecoder (very old) by fallback to legacy unescape path
      let jsonStr;
      if(typeof TextDecoder !== 'undefined'){
        jsonStr = new TextDecoder('utf-8').decode(bytes);
      } else {
        // Fallback reversing btoa(unescape(encodeURIComponent())) pipeline
        jsonStr = decodeURIComponent(escape(bin));
      }
      return JSON.parse(jsonStr);
    } catch(e){ return null; }
  }
  const payload = decodePayloadParam();
  if(!payload) return; // server HTML already shows error block
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
  const orgOut = document.getElementById('orgOut');
  function text(el, v){ if(el) el.textContent = v; }
  text(cidOut, payload.cid);
  text(verOut, payload.v);
  text(saltOut, payload.s);
  text(courseOut, payload.course || '');
  text(gradeOut, payload.grade || '');
  text(dateOut, payload.date || '');
  const ORG = document.body && document.body.dataset.org ? document.body.dataset.org : 'ORG-CERT';
  const INFINITE_SENTINEL = document.body && document.body.dataset.inf ? document.body.dataset.inf : '4000-01-01';
  // Show ORG (payload may start including org field in newer certificates)
  const resolvedOrg = payload.org || ORG;
  if(orgOut) orgOut.textContent = resolvedOrg;
  const tech = document.getElementById('techData');
  if(payload.v === 2){
    if(payload.org && payload.org !== ORG){
      const warn = document.createElement('div');
      warn.className='alert alert-error fs-12';
      warn.textContent='Попередження: ORG у QR не збігається із серверною конфігурацією.';
      if(tech) tech.prepend(warn);
    } else if(!payload.org){
      const info = document.createElement('div');
      info.className='alert alert-warn fs-12';
      info.textContent='ORG відсутній у QR (старіший генератор). Використано локальний ORG.';
      if(tech) tech.prepend(info);
    }
  }
  function normName(s){return s.normalize('NFC').replace(/[\u2019'`’\u02BC]/g,'').replace(/\s+/g,' ').trim().toUpperCase();}
  const HOMO_LATIN=/[ABCEHIKMOPTXYOabcehikmoptxyo]/; const CYR=/[\u0400-\u04FF]/;
  const RISK_SET=new Set('ABCEHIKMOPTXYOabcehikmoptxyo'.split(''));
  function homoglyphLatinLetters(raw){ const seen=new Set(); for(const ch of raw){ if(ch.charCodeAt(0)<128 && RISK_SET.has(ch)) seen.add(ch.toUpperCase()); } return Array.from(seen).sort(); }
  function hasHomoglyphRisk(raw){ if(!HOMO_LATIN.test(raw)) return false; if(!CYR.test(raw)) return false; return homoglyphLatinLetters(raw).length>0; }
  const mismatchAttempts=[];
  function b64urlToBytes(b64){b64=b64.replace(/-/g,'+').replace(/_/g,'/');const pad=b64.length%4;if(pad)b64+='='.repeat(4-pad);const bin=atob(b64);const out=new Uint8Array(bin.length);for(let i=0;i<bin.length;i++)out[i]=bin.charCodeAt(i);return out;}
  async function hmac(keyBytes,msg){const key=await crypto.subtle.importKey('raw',keyBytes,{name:'HMAC',hash:'SHA-256'},false,['sign']);const sig=await crypto.subtle.sign('HMAC',key,new TextEncoder().encode(msg));return new Uint8Array(sig);} 
  function toHex(bytes){return Array.from(bytes).map(b=>b.toString(16).padStart(2,'0')).join('');}
  fetch(statusUrl).then(r=>r.json()).then(js=>{
    if(!js.exists){ existBox.className='alert alert-error'; existBox.textContent='Реєстраційний номер не знайдено.'; return; }
    hashOut.textContent = js.h;
    const shortCode = js.h.slice(0,10).toUpperCase().replace(/(.{5})(.{5})/,'$1-$2');
    intOut.textContent = shortCode;
    let expired = false;
    if(js.version===2 && js.valid_until && js.valid_until !== INFINITE_SENTINEL){
      const today = new Date().toISOString().slice(0,10);
      if(js.valid_until < today) expired = true;
    }
    if(js.revoked){
      existBox.className='alert alert-error';
      function fmtDate(str){
        if(!str) return '';
        const m = str.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
        if(!m) return escapeHtml(str);
        return `${m[3]}.${m[2]}.${m[1]}, ${m[4]}:${m[5]}`;
      }
      const dateLine = js.revoked_at ? `<p><strong>Дата відкликання:</strong> ${fmtDate(js.revoked_at)}</p>` : '';
      const reasonLine = `<p><strong>Причина:</strong> ${js.revoke_reason ? escapeHtml(js.revoke_reason) : '<em>(не вказано)</em>'}</p>`;
    existBox.innerHTML = `<p>Сертифікат існує, але <strong class="text-danger">ВІДКЛИКАНО</strong>.</p>${dateLine}${reasonLine}`;
      if(ownForm) ownForm.style.display='none';
    } else if(expired){
      existBox.className='alert alert-error';
      existBox.textContent='Реєстраційний номер існує, але строк дії минув.';
      if(ownForm) ownForm.style.display='block';
    } else {
      existBox.className='alert';
      existBox.textContent='Реєстраційний номер існує, сертифікат чинний. INT '+shortCode + (js.version===2 && js.valid_until ? (js.valid_until===INFINITE_SENTINEL?' (безтерміновий)':' (дійсний до '+js.valid_until+')') : '');
      if(ownForm) ownForm.style.display='block';
    }
    if(ownForm){
      ownForm.addEventListener('submit', async ev=>{
        ev.preventDefault(); ownResult.textContent='';
        const pib = normName(ownForm.pib.value); if(!pib) return;
        // Pretty-print name for display only (does not affect HMAC normalization)
        function toNameCaseUk(raw){
          let s = String(raw || '').normalize('NFC').replace(/\s+/g,' ').trim();
          if(!s) return s;
          // Strategy: lowercase entire string (uk locale), then capitalize first letter of each word and each hyphenated part.
          // Do NOT uppercase letters after apostrophes to avoid forms like "Мар’Яна".
          s = s.toLocaleLowerCase('uk');
          return s.split(' ').map(word =>
            word.split('-').map(seg => {
              if(!seg) return seg;
              return seg.replace(/^\p{L}/u, ch => ch.toLocaleUpperCase('uk'));
            }).join('-')
          ).join(' ');
        }
        let canonical;
        if(payload.v===1){
          canonical = `v1|${pib}|${payload.course}|${payload.grade}|${payload.date}`;
          try {
            const calc = await hmac(b64urlToBytes(payload.s), canonical);
            const cmp = toHex(calc);
            if(hasHomoglyphRisk(ownForm.pib.value)){
              const risk = homoglyphLatinLetters(ownForm.pib.value).join(', ');
              ownResult.innerHTML='<div class="alert alert-error">Можливі латинські символи: '+risk+' разом із кирилицею. Переконайтесь, що ці літери введені кирилицею (А, В, С, Е, Н, І, К, М, О, Р, Т, Х, У).</div>';
              return;
            }
            if(cmp===js.h){ const displayName = toNameCaseUk(ownForm.pib.value); ownResult.innerHTML='<div class="alert alert-ok" data-verdict="match">Сертифікат дійсно належить '+escapeHtml(displayName)+'.</div>'; } else {
              mismatchAttempts.push({raw:ownForm.pib.value,time:Date.now(),norm: pib});
              ownResult.innerHTML='<div class="alert alert-error">Не збігається. Імʼя/формат не відповідає сертифікату.</div>';
            }
          } catch(e){ ownResult.innerHTML='<div class="alert alert-error">Помилка обчислення.</div>'; }
          return;
        } else if(payload.v===2){
          const vu = payload.valid_until || INFINITE_SENTINEL;
          // Try with possible org candidates (payload.org first if present, then server ORG if different)
          const orgCandidates = [];
          if(payload.org) orgCandidates.push(payload.org);
          if(!payload.org || payload.org !== ORG) orgCandidates.push(ORG);
          let matched = false;
          for(const orgCandidate of orgCandidates){
            const can = `v2|${pib}|${orgCandidate}|${payload.cid}|${payload.course}|${payload.grade}|${payload.date}|${vu}`;
            try {
              const calc = await hmac(b64urlToBytes(payload.s), can);
              const cmp = toHex(calc);
              if(cmp===js.h){ canonical = can; matched = true; break; }
            } catch(_){}
          }
          if(hasHomoglyphRisk(ownForm.pib.value)){
            const risk = homoglyphLatinLetters(ownForm.pib.value).join(', ');
            ownResult.innerHTML='<div class="alert alert-error">Можливі латинські символи: '+risk+' разом із кирилицею. Переконайтесь, що ці літери введені кирилицею (А, В, С, Е, Н, І, К, М, О, Р, Т, Х, У).</div>';
            return;
          }
          if(matched){
            const displayName = toNameCaseUk(ownForm.pib.value);
            ownResult.innerHTML='<div class="alert alert-ok" data-verdict="match">Сертифікат дійсно належить '+escapeHtml(displayName)+'.</div>';
          } else {
            mismatchAttempts.push({raw:ownForm.pib.value,time:Date.now(),norm: pib});
            ownResult.innerHTML='<div class="alert alert-error verify-fail" data-verdict="mismatch">Не збігається. Імʼя/формат не відповідає сертифікату.</div>';
          }
          return;
        } else {
          ownResult.innerHTML='<div class="alert alert-error">Невідома версія формату.</div>';
          return;
        }
      });
    }
  }).catch(()=>{ existBox.className='alert alert-error'; existBox.textContent='Помилка запиту.'; });
})();
