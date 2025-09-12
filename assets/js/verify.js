;(function(){
  // v3-only verifier. Decodes QR payload, fetches server hash, and recomputes HMAC locally.
  function escapeHtml(str){ return String(str).replace(/[&<>"']/g, s=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[s])); }
  function decodePayloadParam(){
    const p = new URLSearchParams(location.search).get('p');
    if(!p) return null;
    let b64 = p.replace(/-/g,'+').replace(/_/g,'/');
    const pad = b64.length % 4; if(pad) b64 += '='.repeat(4-pad);
    try {
      const bin = atob(b64);
      const bytes = new Uint8Array(bin.length);
      for(let i=0;i<bin.length;i++) bytes[i] = bin.charCodeAt(i);
      const jsonStr = (typeof TextDecoder!=='undefined') ? new TextDecoder('utf-8').decode(bytes) : decodeURIComponent(escape(bin));
      return JSON.parse(jsonStr);
    } catch(_){ return null; }
  }
  const payload = decodePayloadParam();
  if(!payload || payload.v !== 3) return;
  const existBox = document.getElementById('existBox');
  const ownForm = document.getElementById('ownForm');
  const ownResult = document.getElementById('ownResult');
  const cidOut = document.getElementById('cidOut');
  const verOut = document.getElementById('verOut');
  const saltOut = document.getElementById('saltOut');
  const dateOut = document.getElementById('dateOut');
  const hashOut = document.getElementById('hashOut');
  const intOut = document.getElementById('intOut');
  const orgOut = document.getElementById('orgOut');
  const canonOut = document.getElementById('canonOut');
  const extraOut = document.getElementById('extraOut');
  function text(el,v){ if(el) el.textContent = v; }
  text(cidOut, payload.cid || '');
  text(verOut, payload.v);
  text(saltOut, payload.s || '');
  text(dateOut, payload.date || '');
  text(canonOut, payload.canon || '');
  text(extraOut, payload.extra || '');
  const ORG = (document.body && document.body.dataset && document.body.dataset.org) ? document.body.dataset.org : 'ORG-CERT';
  const INFINITE_SENTINEL = (document.body && document.body.dataset && document.body.dataset.inf) ? document.body.dataset.inf : '4000-01-01';
  if(orgOut) orgOut.textContent = (payload.org || ORG);
  const tech = document.getElementById('techData');
  if(!payload.canon){ const info=document.createElement('div'); info.className='alert alert-warn fs-12'; info.textContent='Відсутнє поле canon у QR.'; if(tech) tech.prepend(info); }
  function normName(s){ return String(s||'').normalize('NFC').replace(/[\u2019'`’\u02BC]/g,'').replace(/\s+/g,' ').trim().toUpperCase(); }
  const HOMO_LATIN=/[ABCEHIKMOPTXYOabcehikmoptxyo]/; const CYR=/[\u0400-\u04FF]/; const RISK_SET=new Set('ABCEHIKMOPTXYOabcehikmoptxyo'.split(''));
  function homoglyphLatinLetters(raw){ const seen=new Set(); for(const ch of String(raw||'')){ if(ch.charCodeAt(0)<128 && RISK_SET.has(ch)) seen.add(ch.toUpperCase()); } return Array.from(seen).sort(); }
  function hasHomoglyphRisk(raw){ if(!HOMO_LATIN.test(raw)) return false; if(!CYR.test(raw)) return false; return homoglyphLatinLetters(raw).length>0; }
  function b64urlToBytes(b64){ b64=b64.replace(/-/g,'+').replace(/_/g,'/'); const pad=b64.length%4; if(pad) b64+='='.repeat(4-pad); const bin=atob(b64); const out=new Uint8Array(bin.length); for(let i=0;i<bin.length;i++) out[i]=bin.charCodeAt(i); return out; }
  async function hmac(keyBytes,msg){ const key=await crypto.subtle.importKey('raw',keyBytes,{name:'HMAC',hash:'SHA-256'},false,['sign']); const sig=await crypto.subtle.sign('HMAC',key,new TextEncoder().encode(msg)); return new Uint8Array(sig); }
  function toHex(bytes){ return Array.from(bytes).map(b=>b.toString(16).padStart(2,'0')).join(''); }
  function fmtDate(str){ if(!str) return ''; const m=str.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/); return m ? `${m[3]}.${m[2]}.${m[1]}, ${m[4]}:${m[5]}` : str; }
  const statusUrl = '/api/status.php?cid=' + encodeURIComponent(payload.cid);
  fetch(statusUrl).then(r=>r.json()).then(js=>{
    if(!js.exists){ existBox.className='alert alert-error'; existBox.textContent='Реєстраційний номер не знайдено.'; return; }
    hashOut.textContent = js.h;
    const short = js.h.slice(0,10).toUpperCase().replace(/(.{5})(.{5})/,'$1-$2');
    intOut.textContent = short;
    let expired=false;
    if(js.valid_until && js.valid_until !== INFINITE_SENTINEL){ const today=new Date().toISOString().slice(0,10); if(js.valid_until < today) expired=true; }
    if(js.revoked){
      existBox.className='alert alert-error';
      const dateLine = js.revoked_at ? `<p><strong>Дата відкликання:</strong> ${fmtDate(js.revoked_at)}</p>` : '';
      const reasonLine = `<p><strong>Причина:</strong> ${js.revoke_reason ? escapeHtml(js.revoke_reason) : '<em>(не вказано)</em>'}</p>`;
      existBox.innerHTML = `<p>Нагорода існує, але <strong class="text-danger">ВІДКЛИКАНА</strong>.</p>${dateLine}${reasonLine}`;
      if(ownForm) ownForm.style.display='none';
      return;
    }
    if(expired){
      existBox.className='alert alert-error';
      existBox.textContent='Реєстраційний номер існує, але строк дії минув.';
      if(ownForm) ownForm.style.display='block';
    } else {
      existBox.className='alert';
      existBox.textContent='Реєстраційний номер існує, нагорода чинна. INT '+short + (js.valid_until ? (js.valid_until===INFINITE_SENTINEL ? ' (безстрокова)' : ' (чинна до '+js.valid_until+')') : '');
      if(ownForm) ownForm.style.display='block';
    }
    if(!ownForm) return;
    ownForm.classList.remove('d-none');
    ownForm.addEventListener('submit', async ev=>{
      ev.preventDefault(); ownResult.textContent='';
      const raw = ownForm.pib.value||''; const pib = normName(raw); if(!pib) return;
      function toNameCaseUk(rawName){ let s=String(rawName||'').normalize('NFC').replace(/\s+/g,' ').trim(); if(!s) return s; s=s.toLocaleLowerCase('uk'); return s.split(' ').map(w=>w.split('-').map(seg=>seg.replace(/^\p{L}/u, ch=>ch.toLocaleUpperCase('uk'))).join('-')).join(' '); }
      const vu = payload.valid_until || INFINITE_SENTINEL;
      const canonUrl = payload.canon || (window.location.origin + '/verify.php');
      const orgCandidates = []; if(payload.org) orgCandidates.push(payload.org); if(!payload.org || payload.org !== ORG) orgCandidates.push(ORG);
      let matched=false;
      for(const orgCandidate of orgCandidates){
        const can = `v3|${pib}|${orgCandidate}|${payload.cid}|${payload.date}|${vu}|${canonUrl}|${payload.extra||''}`;
        try { const calc = await hmac(b64urlToBytes(payload.s), can); if(toHex(calc)===js.h){ matched=true; break; } } catch(_){ }
      }
      if(hasHomoglyphRisk(raw)){
        const risk = homoglyphLatinLetters(raw).join(', ');
        ownResult.innerHTML = '<div class="alert alert-error">Можливі латинські символи: '+risk+' разом із кирилицею. Переконайтесь, що ці літери введені кирилицею (А, В, С, Е, Н, І, К, М, О, Р, Т, Х, У).</div>';
        return;
      }
      if(matched){ const displayName = toNameCaseUk(raw); ownResult.innerHTML='<div class="alert alert-ok" data-verdict="match">Нагорода дійсно належить '+escapeHtml(displayName)+'.</div>'; }
      else { ownResult.innerHTML='<div class="alert alert-error verify-fail" data-verdict="mismatch">Не збігається. Ім’я/формат не відповідає нагороді.</div>'; }
    });
  }).catch(()=>{ existBox.className='alert alert-error'; existBox.textContent='Помилка запиту.'; });
})();
