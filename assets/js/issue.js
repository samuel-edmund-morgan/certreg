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
  const cExp  = coords.expires || {x:600,y:600};
    const cQR   = coords.qr   || {x:150,y:420,size:220};
    ctx.fillText(currentData.pib, cName.x, cName.y);
    ctx.font = '20px sans-serif'; ctx.fillText(currentData.cid, cId.x, cId.y);
    ctx.font = '24px sans-serif'; ctx.fillText('Оцінка: '+currentData.grade, cScore.x, cScore.y);
    ctx.fillText('Курс: '+currentData.course, cCourse.x, cCourse.y);
    ctx.fillText('Дата: '+currentData.date, cDate.x, cDate.y);
    // Expiry line (uses sentinel for infinite)
    const expLabel = currentData.valid_until===INFINITE_SENTINEL ? 'Безтерміновий' : currentData.valid_until;
    ctx.font = (cExp.size?cExp.size:20)+'px sans-serif';
    if(cExp.angle){
      ctx.save(); ctx.translate(cExp.x, cExp.y); ctx.rotate((cExp.angle*Math.PI)/180);
      ctx.fillText('Термін дії до: '+expLabel, 0, 0);
      ctx.restore();
    } else {
      ctx.fillText('Термін дії до: '+expLabel, cExp.x, cExp.y);
    }
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
    // Prepare render data early
  currentData = {pib:pibNorm,cid:cid,grade:grade,course:course,date:date,h:h,valid_until:validUntil};
    // In test mode: trigger immediate download within user gesture (without waiting for QR load)
    if(window.__TEST_MODE){
      try { generatePdfFromCanvas(); } catch(_e){}
    }
    // Register (no PII)
  const csrf = window.__CSRF_TOKEN || document.querySelector('meta[name="csrf"]')?.content || '';
  const res = await fetch('/api/register.php', {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','X-CSRF-Token':csrf}, body: JSON.stringify({cid:cid, v:VERSION, h:h, course:course, grade:grade, date:date, valid_until:validUntil})});
    if(!res.ok){
      alert('Помилка реєстрації: '+res.status);
      return;
    }
    const js = await res.json();
    if(!js.ok){ alert('Не вдалося створити запис'); return; }
  // Build QR payload (JSON) then embed as base64url in verification URL
  const saltB64 = b64url(salt);
  const payloadObj = {v:VERSION,cid:cid,s:saltB64,org:ORG,course:course,grade:grade,date:date,valid_until:validUntil};
    const payloadStr = JSON.stringify(payloadObj);
    function b64urlStr(str){
      return btoa(unescape(encodeURIComponent(str))).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'');
    }
    const packed = b64urlStr(payloadStr);
    const verifyUrl = window.location.origin + '/verify.php?p=' + packed;
  qrPayloadEl.textContent = verifyUrl + "\n\n" + payloadStr;
    // Ensure onload handler is set BEFORE changing src to avoid race (cache instant load)
  // currentData already set above
  qrImg.onload = ()=>{
    renderAll();
    if(!window.__TEST_MODE){
      setTimeout(()=>{ try{ autoDownload(); }catch(e){ console.warn('PDF auto generation failed', e); ensureManualPdfBtn(); } },120);
    }
  };
  qrImg.onerror = ()=>{
    // Ensure UI controls are available even if QR fails
    try { renderAll(); } catch(_e){}
    ensureDownloadButtons();
  };
    qrImg.src = '/qr.php?data='+encodeURIComponent(verifyUrl);
  // If image was cached and already complete, trigger manually
    if(qrImg.complete){
      // Use microtask to keep async behavior consistent
      setTimeout(()=>{ if(qrImg.onload) qrImg.onload(); },0);
    }
  // In test mode (where we might intercept/404 QR), ensure manual buttons appear promptly
  if(window.__TEST_MODE){ ensureDownloadButtons(); }
  const shortCode = h.slice(0,10).toUpperCase().replace(/(.{5})(.{5})/,'$1-$2');
    regMeta.innerHTML = `<strong>CID:</strong> ${cid}<br><strong>ORG:</strong> ${ORG}<br><strong>H:</strong> <span class="mono">${h}</span><br><strong>INT:</strong> <span class="mono">${shortCode}</span><br><strong>Expires:</strong> ${validUntil===INFINITE_SENTINEL?'∞':validUntil}<br><strong>URL:</strong> <a href="${verifyUrl}" target="_blank" rel="noopener">відкрити перевірку</a>`;
    // Expose cryptographic data for automated test recomputation (non-PII: normalized name not stored server-side)
    try {
      regMeta.dataset.h = h;
      regMeta.dataset.int = shortCode;
      regMeta.dataset.cid = cid;
      regMeta.dataset.salt = saltB64;
      regMeta.dataset.nameNorm = pibNorm; // normalized name used in canonical
      regMeta.dataset.course = course;
      regMeta.dataset.grade = grade;
      regMeta.dataset.date = date;
      regMeta.dataset.validUntil = validUntil;
      regMeta.dataset.org = ORG;
    } catch(_){}
  summary.innerHTML = `<div class=\"alert alert-ok mb-12\">Сертифікат створено. CID <strong>${cid}</strong>. PDF-файл сертифіката автоматично згенеровано та завантажено. Збережіть файл – ПІБ не відновлюється з бази.</div><div class=\"fs-13 flex align-center gap-8 flex-wrap\">Перевірка: <a href=\"${verifyUrl}\" target=\"_blank\" rel=\"noopener\">Відкрити сторінку перевірки</a><button type=\"button\" class=\"btn btn-sm\" id=\"copyLinkBtn\">Копіювати URL</button><span id=\"copyLinkStatus\" class=\"fs-11 text-success d-none\">Скопійовано</span></div>`;
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
  // Always add manual download buttons (PDF & JPG) – some browsers block auto download
  ensureDownloadButtons();
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
    const validWrap = document.getElementById('validUntilWrap');
  function syncExpiryVisibility(){
      if(!infiniteCb || !validUntilInput) return;
      if(infiniteCb.checked){
    if(validWrap){ validWrap.classList.add('hidden-slot'); }
        validUntilInput.disabled = true; validUntilInput.value='';
      } else {
    if(validWrap){ validWrap.classList.remove('hidden-slot'); }
        validUntilInput.disabled = false;
        validUntilInput.focus();
      }
    }
    if(infiniteCb){ infiniteCb.addEventListener('change', syncExpiryVisibility); syncExpiryVisibility(); }
  // Minimal PDF generator embedding the rendered JPEG (client-side, no PII leaves browser)
  async function generatePdfFromCanvas(){
    if(!canvas) return;
    if(canvas.width===0||canvas.height===0){ console.warn('Canvas size zero'); return; }
    try { renderAll(); } catch(_e){}
    const jpegDataUrl = canvas.toDataURL('image/jpeg',0.92); // includes QR + text
    const b64 = jpegDataUrl.split(',')[1];
    const bytes = Uint8Array.from(atob(b64), c=>c.charCodeAt(0));
    const W = canvas.width; const H = canvas.height; // points (1:1)
    // Content stream drawing image at full page
    const contentStream = `q\n${W} 0 0 ${H} 0 0 cm\n/Im0 Do\nQ`;
    const enc = (s)=> new TextEncoder().encode(s);
    // Build objects incrementally collecting offsets
    let parts = [];
    const offsets = []; let position = 0;
    function push(strOrBytes){
      if(typeof strOrBytes === 'string') { const b = enc(strOrBytes); parts.push(b); position += b.length; }
      else { parts.push(strOrBytes); position += strOrBytes.length; }
    }
    const header = '%PDF-1.4\n%âãÏÓ\n';
    push(header);
    function obj(n, body){ offsets[n]=position; push(`${n} 0 obj\n${body}\nendobj\n`); }
    // Will insert image & content separately due to binary streams
    obj(1,'<< /Type /Catalog /Pages 2 0 R >>');
    obj(2,'<< /Type /Pages /Kids [3 0 R] /Count 1 >>');
    obj(3,`<< /Type /Page /Parent 2 0 R /Resources << /XObject << /Im0 4 0 R >> /ProcSet [/PDF /ImageC] >> /MediaBox [0 0 ${W} ${H}] /Contents 5 0 R >>`);
    // Image object (4)
    offsets[4]=position;
    push(`4 0 obj\n<< /Type /XObject /Subtype /Image /Width ${W} /Height ${H} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ${bytes.length} >>\nstream\n`);
    push(bytes);
    push(`\nendstream\nendobj\n`);
    // Content object (5)
    const contentBytes = enc(contentStream);
    obj(5,`<< /Length ${contentBytes.length} >>\nstream\n${contentStream}\nendstream`);
    // Xref table
    const xrefOffset = position;
    const objCount = 6; // 0..5
    let xref = 'xref\n0 '+objCount+'\n';
    xref += '0000000000 65535 f \n';
    for(let i=1;i<objCount;i++){
      const off = (offsets[i]||0).toString().padStart(10,'0');
      xref += `${off} 00000 n \n`;
    }
    push(xref);
    push(`trailer\n<< /Size ${objCount} /Root 1 0 R >>\nstartxref\n${xrefOffset}\n%%EOF`);
    // Concatenate
    let totalLen = parts.reduce((a,b)=>a+b.length,0);
    const out = new Uint8Array(totalLen); let o=0; for(const p of parts){ out.set(p,o); o+=p.length; }
    const blob = new Blob([out], {type:'application/pdf'});
    const cid = (currentData?currentData.cid:'');
    if(window.__TEST_MODE){
      // Deterministic: upload bytes first, then trigger GET so Playwright captures download instantly
      try {
        const filename = 'certificate_'+cid+'.pdf';
        await fetch('/test_download.php?kind=pdf&cid='+encodeURIComponent('certificate_'+cid), {method:'POST', body: blob});
        const a = document.createElement('a');
        a.href = '/test_download.php?kind=pdf&cid='+encodeURIComponent('certificate_'+cid)+'&name='+encodeURIComponent(filename);
        a.download = filename;
        document.body.appendChild(a); a.click(); a.remove();
        return;
      } catch(_e){}
    }
    const link = document.createElement('a');
    link.download = 'certificate_'+cid+'.pdf';
    link.href = URL.createObjectURL(blob);
    document.body.appendChild(link); link.click();
    setTimeout(()=>{ URL.revokeObjectURL(link.href); link.remove(); }, 4000);
  }
  function autoDownload(){ try { generatePdfFromCanvas(); } catch(_e){} }
  function ensureManualPdfBtn(){
    if(document.getElementById('manualPdfBtn')) return;
    const wrap = summary;
    if(!wrap) return;
    const btn = document.createElement('button');
    btn.type='button'; btn.id='manualPdfBtn'; btn.className='btn btn-sm'; btn.textContent='Завантажити PDF';
    btn.addEventListener('click', ()=>{ try{ generatePdfFromCanvas(); }catch(e){ alert('Не вдалося згенерувати PDF'); } });
    wrap.appendChild(document.createTextNode(' '));
    wrap.appendChild(btn);
  }
  function ensureDownloadButtons(){
    if(!summary || document.getElementById('manualPdfBtn')){
      // If PDF button exists, still ensure JPG maybe
      if(!document.getElementById('manualJpgBtn')) addJpgBtn();
      return;
    }
    ensureManualPdfBtn();
    addJpgBtn();
  }
  function addJpgBtn(){
    if(document.getElementById('manualJpgBtn')) return;
    const btn = document.createElement('button');
    btn.type='button'; btn.id='manualJpgBtn'; btn.className='btn btn-sm'; btn.textContent='Завантажити JPG';
    btn.addEventListener('click', ()=>{
      try {
        const dataUrl = canvas.toDataURL('image/jpeg',0.92);
        const a = document.createElement('a');
        a.href = dataUrl; a.download = 'certificate_'+(currentData?currentData.cid:'')+'.jpg';
        document.body.appendChild(a); a.click(); a.remove();
      } catch(e){ alert('Не вдалося згенерувати JPG'); }
    });
    summary.appendChild(document.createTextNode(' '));
    summary.appendChild(btn);
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
