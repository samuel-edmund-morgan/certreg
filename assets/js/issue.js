// Minimal client-side issuance logic (Variant A Step 2)
// 1. Normalize name
// 2. Generate per-certificate salt
// 3. Build canonical string v1|NAME|COURSE|GRADE|DATE
// 4. HMAC-SHA256(salt, canonical) -> hex h
// 5. Generate cid
// 6. POST /api/register (cid, v, h, course, grade, date)
// 7. Build QR payload JSON {v,cid,s,course,grade,date} (base64url salt)
// 8. Request server QR (non-PII) /qr.php?data=...
// 9. Render certificate preview on canvas

(function(){
  const form = document.getElementById('issueForm');
  if(!form) return;
  const resultWrap = document.getElementById('result');
  const regMeta = document.getElementById('regMeta');
  const qrPayloadEl = document.getElementById('qrPayload');
  const qrImg = document.getElementById('qrImg');
  const btnJpg = null; // removed explicit download button (auto + link)
  const toggleDetails = document.getElementById('toggleDetails');
  const advancedBlock = document.getElementById('advanced');
  const summary = document.getElementById('summary');
  const resetBtn = document.getElementById('resetBtn');
  const canvas = document.getElementById('certCanvas');
  const ctx = canvas.getContext('2d');
  const coords = window.__CERT_COORDS || {};
  const VERSION = 2; // canonical v2 with expiry & org
  const ORG = window.__ORG_CODE || 'ORG-CERT';
  const INFINITE_SENTINEL = window.__INFINITE_SENTINEL || '4000-01-01';

  function normName(s){
    return s.normalize('NFC')
      .replace(/[\u2019'`’\u02BC]/g,'') // видаляємо різні апострофи включно з U+02BC
      .replace(/\s+/g,' ')
      .trim()
      .toUpperCase();
  }
  // Розширений набір латинських символів, що мають візуальних «двійників» у кирилиці
  // A B C E H I K M O P T X Y (та відповідні малі: a c e h i k m o p t x y) + попередні T/O/C
  const HOMO_LATIN = /[ABCEHIKMOPTXYOabcehikmoptxyo]/; 
  const CYRILLIC_LETTER = /[\u0400-\u04FF]/; // базовий діапазон кирилиці
  const RISK_SET = new Set('ABCEHIKMOPTXYOabcehikmoptxyo'.split(''));
  function homoglyphLatinLetters(raw){
    const seen = new Set();
    for(const ch of raw){
      if(ch.charCodeAt(0) < 128 && RISK_SET.has(ch)) seen.add(ch.toUpperCase());
    }
    return Array.from(seen).sort();
  }
  function hasHomoglyphRisk(raw){
    if(!HOMO_LATIN.test(raw)) return false;
    if(!CYRILLIC_LETTER.test(raw)) return false; // потрібне змішування
    return homoglyphLatinLetters(raw).length>0;
  }
  function toHex(bytes){ return Array.from(bytes).map(b=>b.toString(16).padStart(2,'0')).join(''); }
  function b64url(bytes){
    return btoa(String.fromCharCode.apply(null, bytes))
      .replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'');
  }
  async function hmacSha256(keyBytes, msg){
    const key = await crypto.subtle.importKey('raw', keyBytes, {name:'HMAC', hash:'SHA-256'}, false, ['sign']);
    const sig = await crypto.subtle.sign('HMAC', key, new TextEncoder().encode(msg));
    return new Uint8Array(sig);
  }
  function genCid(){
    // C<base36 timestamp>-<4 hex>
    const ts = Date.now().toString(36);
    const rnd = crypto.getRandomValues(new Uint8Array(2));
    return 'C'+ts+'-'+toHex(rnd);
  }
  let currentData = null;
  function renderAll(){
    if(!currentData) return;
    ctx.clearRect(0,0,canvas.width,canvas.height);
    if(bgImage.complete){ ctx.drawImage(bgImage,0,0,canvas.width,canvas.height); }
    ctx.fillStyle = '#000';
    ctx.font = '28px sans-serif';
    const cName = coords.name || {x:600,y:420};
    const cId   = coords.id   || {x:600,y:445};
    const cScore= coords.score|| {x:600,y:470};
    const cCourse=coords.course||{x:600,y:520};
    const cDate = coords.date || {x:600,y:570};
    const cQR   = coords.qr   || {x:150,y:420,size:220};
    ctx.fillText(currentData.pib, cName.x, cName.y);
    ctx.font = '20px sans-serif'; ctx.fillText(currentData.cid, cId.x, cId.y);
    ctx.font = '24px sans-serif'; ctx.fillText('Оцінка: '+currentData.grade, cScore.x, cScore.y);
    ctx.fillText('Курс: '+currentData.course, cCourse.x, cCourse.y);
    ctx.fillText('Дата: '+currentData.date, cDate.x, cDate.y);
    if(qrImg.complete){
      ctx.drawImage(qrImg, cQR.x, cQR.y, cQR.size, cQR.size);
    }
    // Integrity short code (first 10 hex chars of H) if available
    if(currentData.h){
      const short = (currentData.h.slice(0,10).toUpperCase()).replace(/(.{5})(.{5})/, '$1-$2');
      const cInt = coords.int || {x: canvas.width - 180, y: canvas.height - 30, size:14};
      ctx.save();
      ctx.font = (cInt.size||14) + 'px monospace';
      ctx.fillStyle = '#111';
      if(cInt.angle){ ctx.translate(cInt.x, cInt.y); ctx.rotate(cInt.angle * Math.PI/180); ctx.fillText('INT '+short, 0, 0); }
      else { ctx.fillText('INT '+short, cInt.x, cInt.y); }
      ctx.restore();
    }
  }
  async function handleSubmit(e){
    e.preventDefault();
  // disable generate button to avoid duplicates until finished
  const genBtn = document.getElementById('generateBtn');
  if(genBtn) genBtn.disabled = true;
    const pibRaw = form.pib.value;
  const course = form.course.value.trim();
  const grade  = form.grade.value.trim();
  const date   = form.date.value; // issued_date YYYY-MM-DD
  const infinite = form.infinite.checked;
  let validUntil = form.valid_until.value;
  if(infinite){ validUntil = INFINITE_SENTINEL; }
  if(!infinite && !validUntil){ alert('Вкажіть дату "Дійсний до" або позначте Безтерміновий.'); return; }
  if(!infinite && validUntil < date){ alert('Дата закінчення не може бути раніше дати проходження.'); return; }
    if(!pibRaw||!course||!grade||!date){ return; }
    if(hasHomoglyphRisk(pibRaw)){
      const risk = homoglyphLatinLetters(pibRaw).join(', ');
      alert('У ПІБ виявлено можливі латинські символи: '+risk+' разом із кирилицею. Замініть їх кириличними аналогами (А, В, С, Е, Н, І, К, М, О, Р, Т, Х, У).');
      return;
    }
  const pibNorm = normName(pibRaw); // normalized (uppercase) used in canonical
    const salt = crypto.getRandomValues(new Uint8Array(32));
  // canonical v2: v2|NAME|ORG|CID|COURSE|GRADE|ISSUED_DATE|VALID_UNTIL built after CID known
  const cid = genCid();
  const canonical = `v${VERSION}|${pibNorm}|${ORG}|${cid}|${course}|${grade}|${date}|${validUntil}`;
  const sig = await hmacSha256(salt, canonical);
  const h = toHex(sig);
    // Register (no PII)
  const res = await fetch('/api/register.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({cid:cid, v:VERSION, h:h, course:course, grade:grade, date:date, valid_until:validUntil})});
    if(!res.ok){
      alert('Помилка реєстрації: '+res.status);
      return;
    }
    const js = await res.json();
    if(!js.ok){ alert('Не вдалося створити запис'); return; }
    // Build QR payload (JSON) then embed as base64url in verification URL
  const payloadObj = {v:VERSION,cid:cid,s:b64url(salt),course:course,grade:grade,date:date,valid_until:validUntil};
    const payloadStr = JSON.stringify(payloadObj);
    function b64urlStr(str){
      return btoa(unescape(encodeURIComponent(str))).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'');
    }
    const packed = b64urlStr(payloadStr);
    const verifyUrl = window.location.origin + '/verify.php?p=' + packed;
  qrPayloadEl.textContent = verifyUrl + "\n\n" + payloadStr;
    // Ensure onload handler is set BEFORE changing src to avoid race (cache instant load)
  currentData = {pib:pibNorm,cid:cid,grade:grade,course:course,date:date,h:h,valid_until:validUntil}; // draw normalized
  qrImg.onload = ()=>{ renderAll(); autoDownload(); }; 
    qrImg.src = '/qr.php?data='+encodeURIComponent(verifyUrl);
    // If image was cached and already complete, trigger manually
    if(qrImg.complete){
      // Use microtask to keep async behavior consistent
      setTimeout(()=>{ if(qrImg.onload) qrImg.onload(); },0);
    }
  const shortCode = h.slice(0,10).toUpperCase().replace(/(.{5})(.{5})/,'$1-$2');
  regMeta.innerHTML = `<strong>CID:</strong> ${cid}<br><strong>H:</strong> <span class="mono">${h}</span><br><strong>INT:</strong> <span class="mono">${shortCode}</span><br><strong>Expires:</strong> ${validUntil===INFINITE_SENTINEL?'∞':validUntil}<br><strong>URL:</strong> <a href="${verifyUrl}" target="_blank" rel="noopener">відкрити перевірку</a>`;
  summary.innerHTML = `<div class=\"alert alert-ok\" style=\"margin:0 0 12px\">Сертифікат створено. CID <strong>${cid}</strong>. Зображення автоматично завантажено. <a href=\"#\" id=\"reDownloadLink\">Повторно завантажити JPG</a>. Збережіть файл – ПІБ не відновлюється з бази.</div><div class=\"fs-13 flex align-center gap-8 flex-wrap\">Перевірка: <a href=\"${verifyUrl}\" target=\"_blank\" rel=\"noopener\">Відкрити сторінку перевірки</a><button type=\"button\" class=\"btn btn-sm\" id=\"copyLinkBtn\">Копіювати URL</button><span id=\"copyLinkStatus\" class=\"fs-11 text-success d-none\">Скопійовано</span></div>`;
  const rd = document.getElementById('reDownloadLink');
  if(rd){ rd.addEventListener('click', ev=>{ ev.preventDefault(); manualDownload(); }); }
  const copyBtn = document.getElementById('copyLinkBtn');
  const copyStatus = document.getElementById('copyLinkStatus');
  if(copyBtn){
    copyBtn.addEventListener('click', async ()=>{
      try{
        if(navigator.clipboard && navigator.clipboard.writeText){
          await navigator.clipboard.writeText(verifyUrl);
        } else {
          const ta=document.createElement('textarea'); ta.value=verifyUrl; ta.style.position='fixed'; ta.style.opacity='0'; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove();
        }
        if(copyStatus){ copyStatus.style.display='inline'; setTimeout(()=>{copyStatus.style.display='none';},1800); }
      }catch(e){ alert('Не вдалося скопіювати'); }
    });
  }
  if(genBtn) genBtn.disabled = false;
  toggleDetails.classList.remove('d-none');
  resultWrap.classList.remove('d-none');
  }
  let bgImage = new Image();
  bgImage.onload = ()=>{ renderAll(); };
  bgImage.src = '/files/cert_template.jpg'; // may 404 if not present
  form.addEventListener('submit', handleSubmit);
    // Toggle expiry input
    const infiniteCb = form.querySelector('input[name="infinite"]');
    const validUntilInput = form.querySelector('input[name="valid_until"]');
    if(infiniteCb && validUntilInput){
      infiniteCb.addEventListener('change', ()=>{
        if(infiniteCb.checked){
          validUntilInput.disabled = true; validUntilInput.value='';
        } else { validUntilInput.disabled = false; }
      });
    }
  function manualDownload(){
    if(!canvas) return;
    const link = document.createElement('a');
    link.download = 'certificate.jpg';
    link.href = canvas.toDataURL('image/jpeg',0.92);
    document.body.appendChild(link);
    link.click();
    setTimeout(()=>{ if(link.parentNode) link.parentNode.removeChild(link); }, 100);
  }
  function autoDownload(){
    // Avoid multiple triggers if user regenerates quickly
    if(!canvas) return;
  manualDownload();
  }
  toggleDetails && toggleDetails.addEventListener('click',()=>{
    if(advancedBlock.classList.contains('d-none')){
      advancedBlock.classList.remove('d-none');
      toggleDetails.textContent='Сховати технічні деталі';
    } else {
      advancedBlock.classList.add('d-none');
      toggleDetails.textContent='Показати технічні деталі';
    }
  });
  resetBtn.addEventListener('click', ()=>{ form.reset(); resultWrap.classList.add('d-none'); advancedBlock.classList.add('d-none'); toggleDetails.classList.add('d-none'); toggleDetails.textContent='Показати технічні деталі'; });
})();
