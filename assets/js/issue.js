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
  const btnJpg = document.getElementById('downloadJpg');
  const resetBtn = document.getElementById('resetBtn');
  const canvas = document.getElementById('certCanvas');
  const ctx = canvas.getContext('2d');
  const coords = window.__CERT_COORDS || {};
  const VERSION = 1;

  function normName(s){
    return s.normalize('NFC')
      .replace(/[\u2019'`’]/g,'')
      .replace(/\s+/g,' ')
      .trim()
      .toUpperCase();
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
  function drawCertificate(bgLoaded, data){
    ctx.clearRect(0,0,canvas.width,canvas.height);
    if(bgLoaded){ ctx.drawImage(bgLoaded,0,0,canvas.width,canvas.height); }
    ctx.fillStyle = '#000';
    ctx.font = '28px sans-serif';
    // fallback coordinates if not in config
    const cName = coords.name || {x:600,y:420};
    const cId   = coords.id   || {x:600,y:445};
    const cScore= coords.score|| {x:600,y:470};
    const cCourse=coords.course||{x:600,y:520};
    const cDate = coords.date || {x:600,y:570};
    const cQR   = coords.qr   || {x:150,y:420,size:220};
    ctx.fillText(data.pib, cName.x, cName.y);
    ctx.font = '20px sans-serif'; ctx.fillText(data.cid, cId.x, cId.y);
    ctx.font = '24px sans-serif'; ctx.fillText('Оцінка: '+data.grade, cScore.x, cScore.y);
    ctx.fillText('Курс: '+data.course, cCourse.x, cCourse.y);
    ctx.fillText('Дата: '+data.date, cDate.x, cDate.y);
    // Draw QR once loaded
    const img = new Image();
    img.onload = ()=>{ ctx.drawImage(img, cQR.x, cQR.y, cQR.size, cQR.size); };
    img.src = qrImg.src;
  }
  async function handleSubmit(e){
    e.preventDefault();
    btnJpg.disabled = true;
    const pibRaw = form.pib.value;
    const course = form.course.value.trim();
    const grade  = form.grade.value.trim();
    const date   = form.date.value; // YYYY-MM-DD
    if(!pibRaw||!course||!grade||!date){ return; }
    const pibNorm = normName(pibRaw);
    const salt = crypto.getRandomValues(new Uint8Array(32));
    const canonical = `v${VERSION}|${pibNorm}|${course}|${grade}|${date}`;
    const sig = await hmacSha256(salt, canonical);
    const h = toHex(sig);
    const cid = genCid();
    // Register (no PII)
    const res = await fetch('/api/register.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({cid:cid, v:VERSION, h:h, course:course, grade:grade, date:date})});
    if(!res.ok){
      alert('Помилка реєстрації: '+res.status);
      return;
    }
    const js = await res.json();
    if(!js.ok){ alert('Не вдалося створити запис'); return; }
    // Build QR payload
    const payloadObj = {v:VERSION,cid:cid,s:b64url(salt),course:course,grade:grade,date:date};
    const payloadStr = JSON.stringify(payloadObj);
    qrPayloadEl.textContent = payloadStr;
    const encoded = encodeURIComponent(payloadStr);
    qrImg.src = '/qr.php?data='+encoded;
    qrImg.onload = ()=>{
      drawCertificate(bgImage, {pib:pibRaw,cid:cid,grade:grade,course:course,date:date});
      btnJpg.disabled = false;
    };
    regMeta.innerHTML = `<strong>CID:</strong> ${cid}<br><strong>H:</strong> <span style="font-family:monospace">${h}</span>`;
    resultWrap.style.display = '';
  }
  let bgImage = new Image();
  bgImage.onload = ()=>{};
  bgImage.src = '/files/cert_template.jpg'; // may 404 if not present
  form.addEventListener('submit', handleSubmit);
  btnJpg.addEventListener('click', ()=>{
    const link = document.createElement('a');
    link.download = 'certificate.jpg';
    link.href = canvas.toDataURL('image/jpeg',0.92);
    link.click();
  });
  resetBtn.addEventListener('click', ()=>{ form.reset(); resultWrap.style.display='none'; btnJpg.disabled=true; });
})();
