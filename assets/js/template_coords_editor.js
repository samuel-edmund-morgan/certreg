(function(){
  const hostSection = document.querySelector('section.section[data-template-id]');
  if(!hostSection) return;

  const templateId = Number(hostSection.dataset.templateId || '0');
  if(!templateId) return;

  const editorRoot = document.getElementById('coordsEditorRoot');
  const overlay = document.getElementById('coordsEditorOverlay');
  const bgImg = document.getElementById('coordsEditorBg');
  const stageInner = bgImg ? bgImg.parentElement : null;
  if(!editorRoot || !overlay || !bgImg || !stageInner) return;
  const templateOrgId = Number(hostSection.dataset.templateOrg || '0');

  function parseJsonSafe(str){
    if(!str) return null;
    try { return JSON.parse(str); } catch(_e){ return null; }
  }

  const coordsScript = document.getElementById('template-coords-data');
  const storedCoordsRaw = coordsScript ? coordsScript.textContent.trim() : '';
  const storedCoords = storedCoordsRaw ? parseJsonSafe(storedCoordsRaw) : null;
  const bodyCoords = document.body && document.body.dataset ? parseJsonSafe(document.body.dataset.coords || '') : null;

  const FIELD_ORDER = ['name','id','extra','date','expires','qr','int'];
  const FIELD_META = {
    name: { label: 'ПІБ', supportsAngle: true, supportsSize: true, supportsBox: true },
    id: { label: 'CID', supportsAngle: true, supportsSize: true, supportsBox: true },
    extra: { label: 'Додаткова', supportsAngle: true, supportsSize: true, supportsBox: true },
    date: { label: 'Дата', supportsAngle: true, supportsSize: true, supportsBox: true },
    expires: { label: 'Дійсний до', supportsAngle: true, supportsSize: true, supportsBox: true },
    qr: { label: 'QR', supportsAngle: false, supportsSize: true, isQr: true },
    int: { label: 'INT', supportsAngle: true, supportsSize: true, supportsBox: true }
  };

  const FIELD_HINTS = {
    name: 'Основний підпис (ПІБ). За потреби змініть розмір шрифту.',
    id: 'Ідентифікатор CID. Рекомендуємо залишати менший розмір.',
    extra: 'Додаткове поле (факультативно).',
    date: 'Дата видачі нагороди.',
    expires: 'Рядок "Дійсний до". За кут можна відповідає нахил тексту.',
    qr: 'Блок QR-коду. Розмір – ширина квадрату в пікселях.',
    int: 'Службовий код INT (короткий хеш).'
  };
  const FIELD_PLACEHOLDERS = {
    name: 'ОЛЕКСАНДР ОЛЕКСАНДРОВИЧ ОЛЕКСАНДРОВ',
    id: 'Cmg28rac7-c897',
    extra: 'Якась додаткова інформація',
    date: 'Дата: 25-09-28',
    expires: 'Термін дії до: Безтерміновий',
    int: 'INT 4167D-784D9'
  };
  const DEFAULT_FONT_FAMILY = 'Montserrat, "DejaVu Sans", sans-serif';
  const measureCanvas = document.createElement('canvas');
  const measureCtx = measureCanvas.getContext('2d');

  const NUM_PROPS = new Set(['x','y','size','width','height','angle','max_width','tracking','line_height','radius','scale','order']);
  const BOOL_PROPS = new Set(['uppercase','wrap','bold','italic']);
  const STRING_PROPS = new Set(['font','color','text']);
  const COLOR_RE = /^#[0-9a-f]{3,8}$/i;

  const tplWidth = Number(hostSection.dataset.templateWidth || '0');
  const tplHeight = Number(hostSection.dataset.templateHeight || '0');
  const tplOriginalUrl = hostSection.dataset.templateOriginal || '';

  function baseDefaults(width, height){
    const usableWidth = width || 1200;
    const nameFieldWidth = Math.max(420, Math.round(usableWidth * 0.46));
    const block = {
      name: { x: 600, y: 420, size: 28, angle: 0, width: nameFieldWidth, height: 48 },
      id: { x: 600, y: 445, size: 20, angle: 0, width: Math.max(280, Math.round(usableWidth * 0.32)), height: 32 },
      extra: { x: 600, y: 520, size: 24, angle: 0, width: nameFieldWidth, height: 44 },
      date: { x: 600, y: 570, size: 24, angle: 0, width: Math.max(260, Math.round(usableWidth * 0.28)), height: 36 },
      expires: { x: 600, y: 600, size: 20, angle: 0, width: Math.max(320, Math.round(usableWidth * 0.34)), height: 34 },
      qr: { x: 150, y: 420, size: 220 },
      int: { x: (width ? Math.max(40, width - 200) : 820), y: (height ? Math.max(40, height - 40) : 650), size: 14, angle: 0, width: Math.max(220, Math.round(usableWidth * 0.22)), height: 28 }
    };
    return block;
  }

  const defaultsSource = baseDefaults(tplWidth, tplHeight);

  function computeAutoBox(field, data){
    const fontUnits = Number.isFinite(data?.size) ? data.size : (defaultsSource[field]?.size || 24);
    const sample = FIELD_PLACEHOLDERS[field] || (FIELD_META[field]?.label || field.toUpperCase());
    const familyRaw = (typeof data?.font === 'string' && data.font.trim()) ? data.font : DEFAULT_FONT_FAMILY;
    const fontSizePx = Math.max(fontUnits, 1);
    const weight = data?.bold ? 600 : 500;
    const italic = data?.italic ? 'italic ' : '';
    let measuredWidth = fontSizePx * Math.max(sample.length * 0.55, 1);
    if(measureCtx){
      try {
        measureCtx.font = `${italic}${weight} ${fontSizePx}px ${familyRaw}`;
        const metrics = measureCtx.measureText(sample);
        if(metrics && Number.isFinite(metrics.width)){
          measuredWidth = Math.max(metrics.width, measuredWidth);
        }
      } catch(_e){
        // fallback keeps measuredWidth from heuristic
      }
    }
    const lineHeightFactor = Number.isFinite(data?.line_height) && data.line_height > 0 ? data.line_height : 1.2;
    const chromeBorder = 4; // 2px dashed border on each side
    const chromePaddingX = 12; // padding-left/right applied in CSS
    const chromePaddingY = 8; // padding-top/bottom applied in CSS
    const slackX = Math.max(fontSizePx * 0.25, 8);
    const slackY = Math.max(fontSizePx * 0.15, 6);
    const width = Math.max(measuredWidth + chromePaddingX + chromeBorder + slackX, fontSizePx + chromePaddingX + chromeBorder + slackX);
    const height = Math.max((fontSizePx * lineHeightFactor) + chromePaddingY + chromeBorder + slackY, (fontSizePx * 0.9) + chromePaddingY + chromeBorder + slackY);
    return { width, height, lineHeightFactor };
  }

  function mergeProps(target, source){
    if(!source || typeof source !== 'object') return;
    for(const [key,val] of Object.entries(source)){
      if(NUM_PROPS.has(key)){
        const num = Number(val);
        if(Number.isFinite(num)) target[key] = num;
      } else if(BOOL_PROPS.has(key)){
        target[key] = !!val;
      } else if(STRING_PROPS.has(key)){
        const str = String(val).trim();
        if(key === 'color'){
          if(str === '' || COLOR_RE.test(str)) target[key] = str.toLowerCase();
        } else {
          if(str !== '') target[key] = str;
        }
      }
    }
  }

  const bounds = {
    minX: -2000,
    maxX: (tplWidth || 2000) + 2000,
    minY: -2000,
    maxY: (tplHeight || 1400) + 2000,
    minSize: 1,
    maxSize: 5000,
    minAngle: -360,
    maxAngle: 360,
    minWidth: 20,
    maxWidth: (tplWidth || 2000) + 200,
    minHeight: 12,
    maxHeight: (tplHeight || 1400) + 200
  };

  function sanitizeField(field, value){
    const meta = FIELD_META[field] || {};
    const base = Object.assign({}, defaultsSource[field] || { x: 0, y: 0 });
    const target = Object.assign({}, base);
    mergeProps(target, value);
    if(!Number.isFinite(target.x)) target.x = base.x || 0;
    if(!Number.isFinite(target.y)) target.y = base.y || 0;
    target.x = clamp(target.x, bounds.minX, bounds.maxX);
    target.y = clamp(target.y, bounds.minY, bounds.maxY);
    if(meta.supportsSize !== false){
      if(!Number.isFinite(target.size)) target.size = base.size || 24;
      target.size = clamp(target.size, bounds.minSize, bounds.maxSize);
    } else {
      delete target.size;
    }
    if(meta.supportsAngle){
      if(Number.isFinite(target.angle)) target.angle = clamp(target.angle, bounds.minAngle, bounds.maxAngle);
      else delete target.angle;
    } else {
      delete target.angle;
    }
    delete target.align;
    if(meta.isQr){
      if(!Number.isFinite(target.size)) target.size = base.size || 200;
      target.size = clamp(target.size, 20, bounds.maxSize);
      delete target.align;
      delete target.angle;
      delete target.width;
      delete target.height;
    } else if(meta.supportsBox){
      const auto = computeAutoBox(field, target);
      const width = clamp(auto.width, bounds.minWidth, bounds.maxWidth);
      const height = clamp(auto.height, bounds.minHeight, bounds.maxHeight);
      target.width = width;
      target.height = height;
    } else {
      delete target.width;
      delete target.height;
    }
    return target;
  }

  function clamp(val, min, max){
    if(val < min) return min;
    if(val > max) return max;
    return val;
  }

  function composeCoords(opts){
    const useStored = !opts || opts.useStored !== false;
    const useGlobal = !opts || opts.useGlobal !== false;
    const map = {};
    for(const field of FIELD_ORDER){
      const stack = [];
      stack.push(defaultsSource[field] || {});
      if(useGlobal && bodyCoords && typeof bodyCoords === 'object' && bodyCoords[field]) stack.push(bodyCoords[field]);
      if(useStored && storedCoords && typeof storedCoords === 'object' && storedCoords[field]) stack.push(storedCoords[field]);
      const merged = {};
      stack.forEach(src => mergeProps(merged, src));
      map[field] = sanitizeField(field, merged);
    }
    return map;
  }

  function cloneCoords(map){
    const out = {};
    for(const key of FIELD_ORDER){
      if(map && map[key]) out[key] = Object.assign({}, map[key]);
    }
    return out;
  }

  function serializeForSave(map){
    const out = {};
    for(const field of FIELD_ORDER){
      const data = map[field];
      if(!data) continue;
      const meta = FIELD_META[field] || {};
      const obj = {};
      obj.x = round(data.x);
      obj.y = round(data.y);
      if(meta.supportsSize !== false && data.size !== undefined) obj.size = round(data.size);
      if(meta.supportsAngle && data.angle !== undefined) obj.angle = round(data.angle);
      if(meta.supportsAlign && data.align) obj.align = data.align;
      if(data.color) obj.color = data.color;
      if(data.font) obj.font = data.font;
      if(data.text) obj.text = data.text;
      if(data.max_width !== undefined) obj.max_width = round(data.max_width);
      if(data.tracking !== undefined) obj.tracking = round(data.tracking);
      if(data.line_height !== undefined) obj.line_height = round(data.line_height);
      if(data.radius !== undefined) obj.radius = round(data.radius);
      if(data.scale !== undefined) obj.scale = round(data.scale);
      if(data.width !== undefined) obj.width = round(data.width);
      if(data.height !== undefined) obj.height = round(data.height);
      if(data.order !== undefined) obj.order = Math.round(data.order);
      BOOL_PROPS.forEach(prop => { if(data[prop] !== undefined) obj[prop] = !!data[prop]; });
      out[field] = obj;
    }
    return out;
  }

  function round(value){
    return Math.round((Number(value) || 0) * 1000) / 1000;
  }

  const selectField = document.getElementById('coordsFieldSelect');
  const inputX = document.getElementById('coordsFieldX');
  const inputY = document.getElementById('coordsFieldY');
  const inputSize = document.getElementById('coordsFieldSize');
  const inputAngle = document.getElementById('coordsFieldAngle');
  const hintEl = document.getElementById('coordsEditorHint');
  const statusEl = document.getElementById('coordsStatus');
  const saveBtn = document.getElementById('coordsSaveBtn');
  const resetBtn = document.getElementById('coordsResetBtn');
  const defaultsBtn = document.getElementById('coordsDefaultsBtn');
  const summaryText = document.getElementById('coordsSummaryText');

  if(!selectField || !inputX || !inputY || !inputSize || !inputAngle || !saveBtn || !resetBtn || !defaultsBtn) return;

  const state = {
    coords: composeCoords(),
    storedSnapshot: null,
    savedSerialized: '',
    activeField: 'name',
    scale: 1,
    canvasWidth: tplWidth || 0,
    canvasHeight: tplHeight || 0,
    markers: {},
    resizing: false
  };

  function applyNativeDimensions(width, height){
    const w = Number(width);
    const h = Number(height);
    if(!Number.isFinite(w) || !Number.isFinite(h) || w<=0 || h<=0) return;
    let changed = false;
    if(state.canvasWidth !== w){ state.canvasWidth = w; changed = true; }
    if(state.canvasHeight !== h){ state.canvasHeight = h; changed = true; }
    if(changed){
      bounds.maxX = (state.canvasWidth || 2000) + 2000;
      bounds.maxY = (state.canvasHeight || 1400) + 2000;
      updateScale();
    }
  }

  state.storedSnapshot = cloneCoords(state.coords);
  state.savedSerialized = JSON.stringify(serializeForSave(state.coords));

  function setSummaryMessage(message, tone){
    if(!summaryText) return;
    summaryText.textContent = message;
    summaryText.classList.remove('text-muted','text-success','text-warning','text-danger');
    if(tone){ summaryText.classList.add('text-'+tone); }
  }

  function updateStatus(message, variant){
    if(!statusEl) return;
    statusEl.textContent = message || '';
    statusEl.classList.remove('text-success','text-danger','text-muted','text-warning');
    if(variant){ statusEl.classList.add('text-'+variant); }
  }

  function updateDirty(){
    const serialized = JSON.stringify(serializeForSave(state.coords));
    const dirty = serialized !== state.savedSerialized;
    saveBtn.disabled = !dirty;
    resetBtn.disabled = !dirty;
    if(!dirty){
      updateStatus('Усі зміни збережено', 'muted');
    } else {
      updateStatus('Є незбережені зміни', 'warning');
    }
  }

  function createMarkers(){
    overlay.innerHTML = '';
    for(const field of FIELD_ORDER){
      const meta = FIELD_META[field] || {};
      const marker = document.createElement('div');
      marker.className = 'coords-marker'+(meta.isQr ? ' coords-marker--qr' : ' coords-marker--text');
      marker.dataset.field = field;
      if(meta.supportsBox && !meta.isQr){
        const box = document.createElement('div');
        box.className = 'coords-marker__text-box';
        const placeholder = document.createElement('div');
        placeholder.className = 'coords-marker__placeholder';
        placeholder.textContent = FIELD_PLACEHOLDERS[field] || meta.label;
        box.appendChild(placeholder);
        marker.appendChild(box);
        marker.__placeholderEl = placeholder;
      }
      const label = document.createElement('span');
      label.className = 'coords-marker__label';
      label.textContent = meta.label;
      if(meta.isQr){
        const box = document.createElement('div');
        box.className = 'coords-marker__qr-box';
        marker.appendChild(box);
      }
      marker.appendChild(label);
      marker.addEventListener('pointerdown', event => startDrag(field, marker, event));
      marker.addEventListener('click', event => { event.preventDefault(); setActiveField(field, { focusInputs: true }); });
      overlay.appendChild(marker);
      state.markers[field] = marker;
    }
  }

  function updateScale(){
    const rect = stageInner.getBoundingClientRect();
    if(rect.width === 0){ return; }
    overlay.style.width = rect.width+'px';
    overlay.style.height = rect.height+'px';
    const baseWidth = state.canvasWidth || 0;
    const naturalWidth = baseWidth || bgImg.naturalWidth || rect.width;
    const naturalHeight = state.canvasHeight || bgImg.naturalHeight || rect.height;
    if(!state.canvasWidth && bgImg.naturalWidth){ state.canvasWidth = bgImg.naturalWidth; }
    if(!state.canvasHeight && bgImg.naturalHeight){ state.canvasHeight = bgImg.naturalHeight; }
    if(state.canvasWidth){
      state.scale = rect.width / state.canvasWidth;
    } else if(naturalWidth){
      state.scale = rect.width / naturalWidth;
      state.canvasWidth = naturalWidth;
    } else {
      state.scale = 1;
    }
    bounds.maxX = (state.canvasWidth || 2000) + 2000;
    bounds.maxY = (state.canvasHeight || 1400) + 2000;
    renderAllMarkers();
  }

  function renderAllMarkers(){
    for(const field of FIELD_ORDER){ renderMarker(field); }
  }

  function renderMarker(field){
    const marker = state.markers[field];
    if(!marker) return;
    const data = state.coords[field];
    if(!data) return;
    const meta = FIELD_META[field] || {};
  const baseLeft = data.x * state.scale;
  const baseTop = data.y * state.scale;
  const alignRaw = (typeof data.align === 'string' && data.align) ? data.align.toLowerCase() : 'left';
  const align = (alignRaw === 'center' || alignRaw === 'right' || alignRaw === 'justify') ? alignRaw : 'left';
    if(meta.isQr){
      marker.style.left = baseLeft+'px';
      marker.style.top = baseTop+'px';
      const box = marker.querySelector('.coords-marker__qr-box');
      if(box){
        const size = (data.size || 0) * state.scale;
        box.style.width = Math.max(8, size)+'px';
        box.style.height = Math.max(8, size)+'px';
        marker.style.width = Math.max(8, size)+'px';
        marker.style.height = Math.max(8, size)+'px';
      }
    } else if(meta.supportsBox){
      const box = marker.querySelector('.coords-marker__text-box');
      if(box){
        const auto = computeAutoBox(field, data);
        const widthUnits = auto.width;
        const heightUnits = auto.height;
  const widthPx = Math.max(20, widthUnits * state.scale);
  const heightPx = Math.max(12, heightUnits * state.scale);
  const anchor = align === 'center' ? 0.5 : (align === 'right' ? 1 : (align === 'justify' ? 0.5 : 0));
  const adjustedLeft = baseLeft - (widthPx * anchor);
  marker.style.left = adjustedLeft+'px';
  marker.style.top = (baseTop - heightPx)+'px';
        marker.style.width = widthPx+'px';
        marker.style.height = heightPx+'px';
        box.style.width = widthPx+'px';
        box.style.height = heightPx+'px';
        box.style.top = '0px';
        box.style.left = '0px';
        const placeholder = marker.__placeholderEl || box.querySelector('.coords-marker__placeholder');
        if(placeholder){
          const sample = FIELD_PLACEHOLDERS[field] || (FIELD_META[field]?.label || field.toUpperCase());
          if(placeholder.textContent !== sample){ placeholder.textContent = sample; }
          const fontUnits = Number.isFinite(data.size) ? data.size : (defaultsSource[field]?.size || 24);
          const fontPx = Math.max(6, fontUnits * state.scale);
          placeholder.style.fontSize = fontPx+'px';
          const lineHeightFactor = auto.lineHeightFactor;
          placeholder.style.lineHeight = (lineHeightFactor * fontPx)+'px';
          placeholder.style.fontWeight = data.bold ? '600' : '500';
          placeholder.style.fontStyle = data.italic ? 'italic' : 'normal';
          placeholder.style.textTransform = data.uppercase ? 'uppercase' : 'none';
          placeholder.style.textAlign = align;
          const allowWrap = data.wrap === true;
          placeholder.style.whiteSpace = allowWrap ? 'normal' : 'nowrap';
          placeholder.style.wordBreak = allowWrap ? 'break-word' : 'normal';
          placeholder.style.overflow = allowWrap ? 'visible' : 'hidden';
          if(data.tracking !== undefined && Number.isFinite(data.tracking)){
            placeholder.style.letterSpacing = (data.tracking * state.scale)+'px';
          } else {
            placeholder.style.letterSpacing = 'normal';
          }
          if(data.angle !== undefined && Number.isFinite(data.angle) && data.angle !== 0){
            placeholder.style.transform = `rotate(${data.angle}deg)`;
            const origin = align === 'center' || align === 'justify' ? '50% 0%' : (align === 'right' ? '100% 0%' : '0% 0%');
            placeholder.style.transformOrigin = origin;
          } else {
            placeholder.style.transform = 'none';
            placeholder.style.transformOrigin = '';
          }
        }
      } else {
        marker.style.left = baseLeft+'px';
        marker.style.top = baseTop+'px';
      }
    } else {
      marker.style.left = baseLeft+'px';
      marker.style.top = baseTop+'px';
    }
    marker.classList.toggle('is-active', state.activeField === field);
    marker.setAttribute('aria-label', `${meta.label}: x=${Math.round(data.x)}, y=${Math.round(data.y)}`);
  }

  function setActiveField(field, opts){
    if(!FIELD_ORDER.includes(field)) return;
    state.activeField = field;
    selectField.value = field;
    renderAllMarkers();
    populateInputs(field);
    if(opts && opts.focusInputs){ inputX.focus(); }
  }

  function populateInputs(field){
    const data = state.coords[field];
    const meta = FIELD_META[field] || {};
    if(!data) return;
    inputX.value = Math.round(data.x * 10) / 10;
    inputY.value = Math.round(data.y * 10) / 10;
    if(meta.supportsSize !== false){
      inputSize.value = Math.round((data.size || 0) * 10) / 10;
      inputSize.disabled = false;
    } else {
      inputSize.value = '';
      inputSize.disabled = true;
    }
    const labelAngle = editorRoot.querySelector('label[for="coordsFieldAngle"]');
    if(meta.supportsAngle){
      inputAngle.value = data.angle !== undefined ? Math.round(data.angle * 10)/10 : 0;
      inputAngle.disabled = false;
      inputAngle.classList.remove('is-disabled');
      if(labelAngle) labelAngle.classList.remove('is-disabled');
    } else {
      inputAngle.value = '';
      inputAngle.disabled = true;
      inputAngle.classList.add('is-disabled');
      if(labelAngle) labelAngle.classList.add('is-disabled');
    }
    if(hintEl){ hintEl.textContent = FIELD_HINTS[field] || ''; }
  }

  function applyPatch(field, patch){
    const data = state.coords[field];
    if(!data) return;
    let changed = false;
    if(patch.x !== undefined){
      const nx = clamp(Number(patch.x), bounds.minX, bounds.maxX);
      if(Number.isFinite(nx) && nx !== data.x){ data.x = nx; changed = true; }
    }
    if(patch.y !== undefined){
      const ny = clamp(Number(patch.y), bounds.minY, bounds.maxY);
      if(Number.isFinite(ny) && ny !== data.y){ data.y = ny; changed = true; }
    }
    if(patch.size !== undefined && (FIELD_META[field] || {}).supportsSize !== false){
      const ns = clamp(Number(patch.size), bounds.minSize, bounds.maxSize);
      if(Number.isFinite(ns) && ns !== data.size){ data.size = ns; changed = true; }
    }
    if(patch.angle !== undefined && (FIELD_META[field] || {}).supportsAngle){
      const na = clamp(Number(patch.angle), bounds.minAngle, bounds.maxAngle);
      if(Number.isFinite(na) && na !== data.angle){ data.angle = na; changed = true; }
    }
    if(changed){
      renderMarker(field);
      if(state.activeField === field) populateInputs(field);
      updateDirty();
    }
  }

  function startDrag(field, marker, event){
    event.preventDefault();
    marker.setPointerCapture(event.pointerId);
    setActiveField(field);
    marker.classList.add('dragging');
    const rect = stageInner.getBoundingClientRect();
    const pointerId = event.pointerId;
    const data = state.coords[field] || {x:0,y:0};
    const startRelX = (event.clientX - rect.left) / state.scale;
    const startRelY = (event.clientY - rect.top) / state.scale;
    const offsetX = startRelX - data.x;
    const offsetY = startRelY - data.y;

    function onMove(ev){
      if(ev.pointerId !== pointerId) return;
      const relX = ((ev.clientX - rect.left) / state.scale) - offsetX;
      const relY = ((ev.clientY - rect.top) / state.scale) - offsetY;
      applyPatch(field, { x: relX, y: relY });
    }

    function onUp(ev){
      if(ev.pointerId !== pointerId) return;
      marker.releasePointerCapture(pointerId);
      marker.removeEventListener('pointermove', onMove);
      marker.removeEventListener('pointerup', onUp);
      marker.removeEventListener('pointercancel', onUp);
      marker.classList.remove('dragging');
    }

    marker.addEventListener('pointermove', onMove);
    marker.addEventListener('pointerup', onUp);
    marker.addEventListener('pointercancel', onUp);
  }

  selectField.addEventListener('change', ()=> setActiveField(selectField.value, { focusInputs: true }));
  inputX.addEventListener('input', ()=> applyPatch(state.activeField, { x: Number(inputX.value) }));
  inputY.addEventListener('input', ()=> applyPatch(state.activeField, { y: Number(inputY.value) }));
  inputSize.addEventListener('input', ()=> applyPatch(state.activeField, { size: Number(inputSize.value) }));
  inputAngle.addEventListener('input', ()=> applyPatch(state.activeField, { angle: Number(inputAngle.value) }));

  const csrfToken = (document.querySelector('input[name="_csrf"]') || document.querySelector('meta[name="csrf"]'))?.value || '';

  async function saveCoords(){
    if(saveBtn.disabled) return;
    const payload = serializeForSave(state.coords);
    updateStatus('Збереження…', 'muted');
    saveBtn.disabled = true;
    saveBtn.dataset.loading = '1';
    try {
      const fd = new FormData();
      fd.append('_csrf', csrfToken);
      fd.append('id', String(templateId));
      fd.append('coords', JSON.stringify(payload));
      const res = await fetch('/api/template_update.php', { method:'POST', body: fd, credentials:'same-origin' });
      const resp = await res.json().catch(()=>null);
      if(!res.ok || !resp || !resp.ok){
        const err = resp && resp.error ? resp.error : 'unknown';
        updateStatus('Помилка збереження: '+err, 'danger');
        saveBtn.disabled = false;
        return;
      }
      if(resp.template && resp.template.coords){
        for(const field of FIELD_ORDER){
          if(resp.template.coords[field]){
            state.coords[field] = sanitizeField(field, resp.template.coords[field]);
          }
        }
      }
      state.storedSnapshot = cloneCoords(state.coords);
      state.savedSerialized = JSON.stringify(serializeForSave(state.coords));
      updateDirty();
      const stamp = new Date().toLocaleTimeString('uk-UA', { hour12:false });
      updateStatus('Збережено о '+stamp, 'success');
      setSummaryMessage('Індивідуальні координати збережено о '+stamp, 'success');
    } catch(err){
      updateStatus('Мережева помилка збереження', 'danger');
      saveBtn.disabled = false;
    } finally {
      delete saveBtn.dataset.loading;
    }
  }

  saveBtn.addEventListener('click', saveCoords);

  resetBtn.addEventListener('click', ()=>{
    if(resetBtn.disabled) return;
    state.coords = cloneCoords(state.storedSnapshot);
    renderAllMarkers();
    populateInputs(state.activeField);
    updateDirty();
    updateStatus('Повернуто до збережених координат', 'muted');
    setSummaryMessage('Повернуто до останньо збережених координат.', 'muted');
  });

  defaultsBtn.addEventListener('click', ()=>{
    state.coords = composeCoords({ useStored: false, useGlobal: true });
    renderAllMarkers();
    populateInputs(state.activeField);
    updateDirty();
    updateStatus('Завантажено глобальні координати (потрібно зберегти)', 'warning');
    setSummaryMessage('Завантажено глобальні координати. Збережіть, якщо хочете застосувати їх для шаблону.', 'warning');
  });

  function onResize(){ updateScale(); }
  let resizeTimer = null;
  window.addEventListener('resize', ()=>{
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(onResize, 120);
  });

  if(bgImg.complete){
    updateScale();
  } else {
    bgImg.addEventListener('load', ()=> updateScale(), { once: true });
  }
  if(tplOriginalUrl){
    try {
      const probe = new Image();
      probe.decoding = 'async';
      probe.onload = ()=>{ applyNativeDimensions(probe.naturalWidth, probe.naturalHeight); };
      probe.src = tplOriginalUrl;
    } catch(_e){}
  }
  createMarkers();
  renderAllMarkers();
  setActiveField(state.activeField);
  updateDirty();

  async function hydrateFromServer(){
    const serializedBefore = JSON.stringify(serializeForSave(state.coords));
    if(serializedBefore !== state.savedSerialized) return; // користувач вже вніс зміни
    let url = '/api/templates_list.php';
    if(templateOrgId){
      url += '?org_id='+encodeURIComponent(templateOrgId);
    }
    try {
      const res = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
      if(!res.ok) return;
      const payload = await res.json().catch(()=>null);
      if(!payload || !payload.ok || !Array.isArray(payload.items)) return;
      const match = payload.items.find(item => Number(item.id) === templateId);
      if(!match) return;
      if(Number.isFinite(match.width) && Number.isFinite(match.height)){
        applyNativeDimensions(match.width, match.height);
      }
      if(match.coords && typeof match.coords === 'object'){
        const nextCoords = cloneCoords(state.coords);
        for(const field of FIELD_ORDER){
          if(match.coords[field]){
            nextCoords[field] = sanitizeField(field, match.coords[field]);
          }
        }
        const serializedNext = JSON.stringify(serializeForSave(nextCoords));
        if(serializedNext !== state.savedSerialized){
          state.coords = nextCoords;
          state.storedSnapshot = cloneCoords(nextCoords);
          state.savedSerialized = serializedNext;
          renderAllMarkers();
          populateInputs(state.activeField);
          updateDirty();
          setSummaryMessage('Відновлено збережені координати з сервера.', 'success');
        }
      }
    } catch(err){
      console.warn('Не вдалося підвантажити координати шаблону', err);
    }
  }

  hydrateFromServer();
})();
