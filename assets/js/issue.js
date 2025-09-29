// Client-side issuance (v3-only, privacy-first)
// 1. Normalize name (NFC, trim, uppercase, remove apostrophes)
// 2. Generate per-certificate salt
// 3. Build canonical string v3|PIB|ORG|CID|DATE|VALID_UNTIL|CANON_URL|EXTRA
// 4. HMAC-SHA256(salt, canonical) -> hex h
// 5. Generate cid
// 6. POST /api/register (cid, v:3, h, date, valid_until, extra_info)
// 7. Build QR payload JSON {v:3,cid,s,org,date,valid_until,canon,extra}
// 8. Request server QR /qr.php?data=...
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
  const awardDisplay = document.getElementById('awardTitleDisplay');
  function escapeHtml(str){
    return String(str==null?'':str).replace(/[&<>"']/g, s=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[s]));
  }
  function normalizeDimension(value){
    const num = Number(value);
    return Number.isFinite(num) && num > 0 ? num : null;
  }
  const metaSeed = (typeof window !== 'undefined' && window.__ACTIVE_TEMPLATE_META && typeof window.__ACTIVE_TEMPLATE_META === 'object') ? window.__ACTIVE_TEMPLATE_META : {};
  let activeTemplateMeta = {
    width: normalizeDimension(metaSeed.width),
    height: normalizeDimension(metaSeed.height)
  };
  function deriveInitialAwardTitle(){
    const winVal = (typeof window !== 'undefined' && typeof window.__ACTIVE_AWARD_TITLE === 'string') ? window.__ACTIVE_AWARD_TITLE.trim() : '';
    if(winVal) return winVal;
    if(awardDisplay){
      const dataVal = typeof awardDisplay.dataset?.awardTitle === 'string' ? awardDisplay.dataset.awardTitle.trim() : '';
      if(dataVal) return dataVal;
      const textVal = awardDisplay.textContent ? awardDisplay.textContent.trim() : '';
      if(textVal) return textVal;
    }
    return 'Нагорода';
  }
  let activeAwardTitle = deriveInitialAwardTitle();
  if(typeof window !== 'undefined'){ window.__ACTIVE_AWARD_TITLE = activeAwardTitle; }
  function getTemplateBaseSize(){
    const width = normalizeDimension(activeTemplateMeta.width) || normalizeDimension(bgImage && bgImage.naturalWidth) || canvas.width;
    const height = normalizeDimension(activeTemplateMeta.height) || normalizeDimension(bgImage && bgImage.naturalHeight) || canvas.height;
    return { width, height };
  }
  function resolveActiveCoords(){
    if(typeof window !== 'undefined'){
      try {
        if(window.__SINGLE_TEMPLATE_COORDS && typeof window.__SINGLE_TEMPLATE_COORDS === 'object'){
          return window.__SINGLE_TEMPLATE_COORDS;
        }
      } catch(_e){}
      try {
        if(window.__ACTIVE_TEMPLATE_COORDS && typeof window.__ACTIVE_TEMPLATE_COORDS === 'object'){
          return window.__ACTIVE_TEMPLATE_COORDS;
        }
      } catch(_e){}
      try {
        if(window.__CERT_COORDS && typeof window.__CERT_COORDS === 'object'){
          return window.__CERT_COORDS;
        }
      } catch(_e){}
    }
    return {};
  }
  let activeCoords = resolveActiveCoords();
  const TEST_MODE = (
    (!!(typeof window!=="undefined" && window.__TEST_MODE)) ||
    ((document.body && document.body.dataset && document.body.dataset.test)==='1') ||
    (typeof navigator!=='undefined' && navigator.webdriver===true)
  );
  const ORG_RAW = (document.body && document.body.dataset && document.body.dataset.org) ? document.body.dataset.org : (window.__ORG_CODE || 'ORG-CERT');
  const ORG = TEST_MODE ? 'ORG-CERT' : ORG_RAW;
  const CANON_URL = (document.body && document.body.dataset.canon) ? document.body.dataset.canon : (window.location.origin + '/verify.php');
  const INFINITE_SENTINEL = window.__INFINITE_SENTINEL || '4000-01-01';

  function formatDisplayDate(value){
    if(!value || typeof value !== 'string') return null;
    const iso = value.trim();
    if(!iso) return null;
    const datePart = iso.split('T')[0];
    const parts = datePart.split('-');
    if(parts.length !== 3) return null;
    const [year, month, day] = parts;
    if(year.length !== 4 || month.length !== 2 || day.length !== 2) return null;
    return `${day}.${month}.${year}`;
  }

  function normName(s){
    return s.normalize('NFC')
      .replace(/[\u2019'`’\u02BC]/g,'') // видаляємо різні апострофи включно з U+02BC
      .replace(/\s+/g,' ')
      .trim()
      .toUpperCase();
  }
  function normAwardTitle(s){
    return String(s||'')
      .normalize('NFC')
      .replace(/\s+/g,' ')
      .trim();
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
  function ensureCanvasMatchesTemplate(meta, img){
    const imgWidth = img ? normalizeDimension(img.naturalWidth) : null;
    const imgHeight = img ? normalizeDimension(img.naturalHeight) : null;
    const width = normalizeDimension(meta && meta.width) || imgWidth;
    const height = normalizeDimension(meta && meta.height) || imgHeight;
    if(width && height && (canvas.width !== width || canvas.height !== height)){
      canvas.width = width;
      canvas.height = height;
    }
  }
  function renderAll(){
    if(!currentData) return;
    const baseSize = getTemplateBaseSize();
    const scaleX = baseSize.width ? (canvas.width / baseSize.width) : 1;
    const scaleY = baseSize.height ? (canvas.height / baseSize.height) : 1;
    ctx.clearRect(0,0,canvas.width,canvas.height);
    if(bgImage.complete){ ctx.drawImage(bgImage,0,0,canvas.width,canvas.height); }
    const coords = activeCoords || {};
    ctx.save();
    ctx.scale(scaleX || 1, scaleY || 1);
    ctx.fillStyle = '#000';
    function resolveAlign(raw){
      const val = typeof raw === 'string' ? raw.toLowerCase() : '';
      if(val === 'center' || val === 'middle') return 'center';
      if(val === 'right' || val === 'end') return 'right';
      if(val === 'justify') return 'center';
      return 'left';
    }
    function buildFont(cfg, fallbackSize, fallbackFamily){
      const size = Number.isFinite(cfg?.size) ? cfg.size : fallbackSize;
      const family = (typeof cfg?.font === 'string' && cfg.font.trim()) ? cfg.font : (fallbackFamily || 'sans-serif');
      const weight = cfg?.bold ? '600' : '400';
      const italic = cfg?.italic ? 'italic ' : '';
      return { css: `${italic}${weight} ${size}px ${family}`, size };
    }
    function normalizeText(value, cfg){
      if(value === null || value === undefined) return '';
      let out = String(value);
      if(cfg?.uppercase) out = out.toUpperCase();
      return out;
    }
    function drawTextBlock(rawText, cfg, defaults){
      if(!cfg) return;
      const text = normalizeText(rawText, cfg);
      if(!text) return;
      const font = buildFont(cfg, defaults.size, defaults.family);
      const align = resolveAlign(cfg.align);
      const angle = Number.isFinite(cfg.angle) ? cfg.angle : 0;
      const color = (typeof cfg.color === 'string' && cfg.color.trim()) ? cfg.color : (defaults.color || '#000');
      ctx.save();
      ctx.font = font.css;
      ctx.fillStyle = color;
      ctx.textAlign = align;
      ctx.textBaseline = 'alphabetic';
      if(angle !== 0){
        ctx.translate(cfg.x, cfg.y);
        ctx.rotate((angle * Math.PI) / 180);
        ctx.fillText(text, 0, 0);
      } else {
        ctx.fillText(text, cfg.x, cfg.y);
      }
      ctx.restore();
    }
    const fallback = {
      award: {x: (baseSize.width||1000) * 0.6, y: (baseSize.height||700) * 0.52, size: 32, align: 'left', angle: 0},
      name: {x: (baseSize.width||1000) * 0.6, y: (baseSize.height||700) * 0.6, size: 28, align: 'left', angle: 0},
      id: {x: (baseSize.width||1000) * 0.6, y: (baseSize.height||700) * 0.635, size: 20, align: 'left', angle: 0},
      extra: {x: (baseSize.width||1000) * 0.6, y: (baseSize.height||700) * 0.74, size: 24, align: 'left', angle: 0},
      date: {x: (baseSize.width||1000) * 0.6, y: (baseSize.height||700) * 0.81, size: 24, align: 'left', angle: 0},
      expires: {x: (baseSize.width||1000) * 0.6, y: (baseSize.height||700) * 0.86, size: 20, align: 'left', angle: 0},
      qr: {x: (baseSize.width||1000) * 0.15, y: (baseSize.height||700) * 0.6, size: 220},
      int: {x: (baseSize.width||1000) - 180, y: (baseSize.height||700) - 30, size: 14, align: 'left', angle: 0}
    };
    const cAward = coords.award || fallback.award;
    const cName = coords.name || fallback.name;
    const cId   = coords.id   || fallback.id;
    const cExtra = coords.extra || fallback.extra;
    const cDate = coords.date || fallback.date;
    const cExp  = coords.expires || fallback.expires;
    const cQR   = coords.qr   || fallback.qr;
    if(currentData.award_title){
      drawTextBlock(currentData.award_title, cAward, { size: 32, family: 'sans-serif', color: '#000' });
    }
    drawTextBlock(currentData.pib, cName, { size: 28, family: 'sans-serif', color: '#000' });
    drawTextBlock(currentData.cid, cId, { size: 20, family: 'sans-serif', color: '#000' });
    if(currentData.extra){ drawTextBlock(String(currentData.extra), cExtra, { size: 24, family: 'sans-serif', color: '#000' }); }
  const dateDisplay = currentData.dateDisplay || currentData.date;
  drawTextBlock('Дата: '+dateDisplay, cDate, { size: 24, family: 'sans-serif', color: '#000' });
  const expLabel = currentData.valid_until===INFINITE_SENTINEL ? 'Безтерміновий' : (currentData.validUntilDisplay || currentData.valid_until);
    drawTextBlock('Термін дії до: '+expLabel, cExp, { size: 20, family: 'sans-serif', color: '#000' });
    if(qrImg.complete){
      ctx.drawImage(qrImg, cQR.x, cQR.y, cQR.size, cQR.size);
    }
    if(currentData.h){
      const short = (currentData.h.slice(0,10).toUpperCase()).replace(/(.{5})(.{5})/, '$1-$2');
      const cInt = coords.int || fallback.int;
      drawTextBlock('INT '+short, Object.assign({ color: '#111', font: 'monospace' }, cInt), { size: 14, family: 'monospace', color: '#111' });
    }
    ctx.restore();
  }
  async function handleSubmit(e){
    e.preventDefault();
  // disable generate button to avoid duplicates until finished
  const genBtn = document.getElementById('generateBtn');
  if(genBtn) genBtn.disabled = true;
    const pibRaw = form.pib.value;
  const extra  = (form.extra && form.extra.value ? form.extra.value.trim() : '');
  const date   = form.date.value; // issued_date YYYY-MM-DD
  const infinite = form.infinite.checked;
  let validUntil = form.valid_until.value;
  if(infinite){ validUntil = INFINITE_SENTINEL; }
  if(!infinite && !validUntil){ alert('Вкажіть дату "Дійсний до" або позначте Безтерміновий.'); return; }
  if(!infinite && validUntil < date){ alert('Дата закінчення не може бути раніше дати проходження.'); return; }
    if(!pibRaw || !date){ if(genBtn) genBtn.disabled=false; return; }
    if(hasHomoglyphRisk(pibRaw)){
      const risk = homoglyphLatinLetters(pibRaw).join(', ');
      alert('У ПІБ виявлено можливі латинські символи: '+risk+' разом із кирилицею. Замініть їх кириличними аналогами (А, В, С, Е, Н, І, К, М, О, Р, Т, Х, У).');
      return;
    }
  const pibNorm = normName(pibRaw); // normalized (uppercase) used in canonical
    let awardTitle = normAwardTitle(activeAwardTitle);
    if(!awardTitle){ awardTitle = 'Нагорода'; }
  activeAwardTitle = awardTitle;
  if(typeof window !== 'undefined'){ window.__ACTIVE_AWARD_TITLE = awardTitle; }
    if(awardDisplay){
      awardDisplay.textContent = awardTitle;
      awardDisplay.dataset.awardTitle = awardTitle;
    }
    if(awardTitle.length > 160){ alert('Назва нагороди занадто довга (максимум 160 символів).'); if(genBtn) genBtn.disabled=false; return; }
    const salt = crypto.getRandomValues(new Uint8Array(32));
  const cid = genCid();
  const version = 4;
  const canonical = `v4|${pibNorm}|${ORG}|${awardTitle}|${cid}|${date}|${validUntil}|${CANON_URL}|${extra}`;
  const sig = await hmacSha256(salt, canonical);
  const h = toHex(sig);
    // Prepare render data early
  currentData = {
    award_title: awardTitle,
    pib: pibNorm,
    cid,
    extra,
    date,
    dateDisplay: formatDisplayDate(date) || date,
    h,
    valid_until: validUntil,
    validUntilDisplay: validUntil===INFINITE_SENTINEL ? null : (formatDisplayDate(validUntil) || validUntil)
  };
    // In test mode: trigger immediate download within user gesture (without waiting for QR load)
    if(TEST_MODE){
      try { generatePdfFromCanvas(); } catch(_e){}
    }
    // Register (no PII)
  const csrf = window.__CSRF_TOKEN || document.querySelector('meta[name="csrf"]')?.content || '';
  // Передаємо опціональний template_id якщо обрано
  const tplSelect = document.getElementById('templateSelect');
  const templateId = (tplSelect && tplSelect.value) ? Number(tplSelect.value) : null;
  const payload = {cid:cid, v:4, h:h, date:date, valid_until:validUntil, extra_info: extra || null, org_code: ORG, award_title: awardTitle};
  if(templateId){ payload.template_id = templateId; }
  const res = await fetch('/api/register.php', {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','X-CSRF-Token':csrf}, body: JSON.stringify(payload)});
    if(!res.ok){
      alert('Помилка реєстрації: '+res.status);
      return;
    }
    const js = await res.json();
    if(!js.ok){ alert('Не вдалося створити запис'); return; }
  // Build QR payload (JSON) then embed as base64url in verification URL
  const saltB64 = b64url(salt);
  let payloadObj;
  // v4 QR payload includes award_title
  payloadObj = {v:4,cid:cid,s:saltB64,org:ORG,award_title:awardTitle,date:date,valid_until:validUntil,canon:CANON_URL,extra:extra};
    const payloadStr = JSON.stringify(payloadObj);
    function b64urlStr(str){
      return btoa(unescape(encodeURIComponent(str))).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'');
    }
    const packed = b64urlStr(payloadStr);
  const verifyUrl = (CANON_URL || (window.location.origin + '/verify.php')) + '?p=' + packed;
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
  if(TEST_MODE){ ensureDownloadButtons(); }
  const shortCode = h.slice(0,10).toUpperCase().replace(/(.{5})(.{5})/,'$1-$2');
  const expiresDisplay = validUntil===INFINITE_SENTINEL ? '∞' : (formatDisplayDate(validUntil) || validUntil);
  regMeta.innerHTML = `<strong>CID:</strong> ${cid}<br><strong>Організація:</strong> ${ORG}<br><strong>Назва нагороди:</strong> ${escapeHtml(awardTitle)}<br><strong>Версія:</strong> v${version}<br><strong>H:</strong> <span class="mono">${h}</span><br><strong>INT:</strong> <span class="mono">${shortCode}</span><br><strong>Expires:</strong> ${expiresDisplay}<br><strong>URL:</strong> <a href="${verifyUrl}" target="_blank" rel="noopener">відкрити перевірку</a>`;
    // Expose cryptographic data for automated test recomputation (non-PII: normalized name not stored server-side)
    try {
      regMeta.dataset.h = h;
      regMeta.dataset.int = shortCode;
      regMeta.dataset.cid = cid;
      regMeta.dataset.salt = saltB64;
      regMeta.dataset.nameNorm = pibNorm; // normalized name used in canonical
  regMeta.dataset.date = date;
  regMeta.dataset.validUntil = validUntil;
  regMeta.dataset.version = String(version);
  if(extra) regMeta.dataset.extra = extra;
      regMeta.dataset.org = ORG;
    regMeta.dataset.awardTitle = awardTitle;
      // Expose exact canonical base URL used in the HMAC canonical string
      regMeta.dataset.canon = CANON_URL;
    } catch(_){}
  summary.innerHTML = `<div class=\"alert alert-ok mb-12\">Нагороду створено. CID <strong>${cid}</strong>. PDF-файл нагороди автоматично згенеровано та завантажено. Збережіть файл – ПІБ не відновлюється з бази.</div><div class=\"fs-13 flex align-center gap-8 flex-wrap\">Перевірка: <a href=\"${verifyUrl}\" target=\"_blank\" rel=\"noopener\">Відкрити сторінку перевірки</a><button type=\"button\" class=\"btn btn-sm\" id=\"copyLinkBtn\">Копіювати URL</button><span id=\"copyLinkStatus\" class=\"fs-11 text-success d-none\">Скопійовано</span></div>`;
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
  bgImage.onload = ()=>{ ensureCanvasMatchesTemplate(activeTemplateMeta, bgImage); renderAll(); };
  // Use configurable template path exposed via <body data-template> or override (falls back to default)
  (function(){
    try {
      const override = (typeof window!=='undefined' && window.__ISSUE_TEMPLATE_OVERRIDE) ? window.__ISSUE_TEMPLATE_OVERRIDE : null;
      const tpl = override || ((document.body && document.body.dataset && document.body.dataset.template) ? document.body.dataset.template : '/files/cert_template.jpg');
      bgImage.src = tpl || '/files/cert_template.jpg';
    } catch(_e){ bgImage.src = '/files/cert_template.jpg'; }
  })();
  // Реакція на зміну шаблону (подія від issue_templates.js)
  document.addEventListener('cert-template-change', function(ev){
    if(ev.detail){
      if(Object.prototype.hasOwnProperty.call(ev.detail, 'width') || Object.prototype.hasOwnProperty.call(ev.detail, 'height')){
        activeTemplateMeta = {
          width: normalizeDimension(ev.detail.width),
          height: normalizeDimension(ev.detail.height)
        };
        if(typeof window!=='undefined'){ window.__ACTIVE_TEMPLATE_META = activeTemplateMeta; }
        ensureCanvasMatchesTemplate(activeTemplateMeta, bgImage);
      }
      if(ev.detail.path){
        bgImage.src = ev.detail.path;
      }
      if(Object.prototype.hasOwnProperty.call(ev.detail,'award_title')){
        const incoming = normAwardTitle(ev.detail.award_title);
        activeAwardTitle = incoming || 'Нагорода';
        if(awardDisplay){
          awardDisplay.textContent = activeAwardTitle;
          awardDisplay.dataset.awardTitle = activeAwardTitle;
        }
        if(typeof window !== 'undefined'){ window.__ACTIVE_AWARD_TITLE = activeAwardTitle; }
        if(currentData){
          currentData.award_title = activeAwardTitle;
          try { renderAll(); } catch(_e){}
        }
      }
      if(Object.prototype.hasOwnProperty.call(ev.detail,'coords')){
        activeCoords = resolveActiveCoords();
        try { renderAll(); } catch(_e){}
      }
      if(!ev.detail.path){ ensureCanvasMatchesTemplate(activeTemplateMeta, bgImage); if(currentData){ try{ renderAll(); }catch(_e){} } }
    }
  });
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
    if(TEST_MODE){
      // Deterministic: upload bytes first, then trigger GET so Playwright captures download instantly
      try {
        const filename = 'certificate_'+cid+'.pdf';
        const ticket = 't_'+cid+'_'+Math.random().toString(36).slice(2);
        await fetch('/test_download.php?kind=pdf&tm=1&ticket='+encodeURIComponent(ticket), {method:'POST', body: blob});
        const a = document.createElement('a');
        a.href = '/test_download.php?kind=pdf&tm=1&ticket='+encodeURIComponent(ticket)+'&name='+encodeURIComponent(filename)+'&wait=5';
        a.setAttribute('download', filename);
        // Keep anchor in DOM briefly to avoid early GC cancel in some browsers/automation
        document.body.appendChild(a); a.click(); setTimeout(()=>{ try{ a.remove(); }catch(_){} }, 1500);
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
  // No course/grade in v3-only model
})();
