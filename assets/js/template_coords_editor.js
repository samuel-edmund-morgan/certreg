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
    name: { label: 'ПІБ', supportsAngle: true, supportsAlign: true, supportsSize: true },
    id: { label: 'CID', supportsAngle: false, supportsAlign: true, supportsSize: true },
    extra: { label: 'Додаткова', supportsAngle: true, supportsAlign: true, supportsSize: true },
    date: { label: 'Дата', supportsAngle: false, supportsAlign: true, supportsSize: true },
    expires: { label: 'Дійсний до', supportsAngle: true, supportsAlign: true, supportsSize: true },
    qr: { label: 'QR', supportsAngle: false, supportsAlign: false, supportsSize: true, isQr: true },
    int: { label: 'INT', supportsAngle: false, supportsAlign: true, supportsSize: true }
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

  const NUM_PROPS = new Set(['x','y','size','width','height','angle','max_width','tracking','line_height','radius','scale','order']);
  const BOOL_PROPS = new Set(['uppercase','wrap','bold','italic']);
  const STRING_PROPS = new Set(['align','font','color','text']);
  const ALIGN_VALUES = new Set(['left','center','right','justify']);
  const COLOR_RE = /^#[0-9a-f]{3,8}$/i;

  const tplWidth = Number(hostSection.dataset.templateWidth || '0');
  const tplHeight = Number(hostSection.dataset.templateHeight || '0');

  function baseDefaults(width, height){
    const result = {
      name: { x: 600, y: 420, size: 28, align: 'left' },
      id: { x: 600, y: 445, size: 20, align: 'left' },
      extra: { x: 600, y: 520, size: 24, align: 'left' },
      date: { x: 600, y: 570, size: 24, align: 'left' },
      expires: { x: 600, y: 600, size: 20, align: 'left', angle: 0 },
      qr: { x: 150, y: 420, size: 220 },
      int: { x: (width ? Math.max(40, width - 200) : 820), y: (height ? Math.max(40, height - 40) : 650), size: 14, align: 'left' }
    };
    return result;
  }

  const defaultsSource = baseDefaults(tplWidth, tplHeight);

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
        if(key === 'align'){
          const lower = str.toLowerCase();
          if(ALIGN_VALUES.has(lower)) target[key] = lower;
        } else if(key === 'color'){
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
    maxAngle: 360
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
    if(meta.supportsAlign){
      const align = typeof target.align === 'string' ? target.align.toLowerCase() : (base.align || 'left');
      target.align = ALIGN_VALUES.has(align) ? align : 'left';
    } else {
      delete target.align;
    }
    if(meta.isQr){
      if(!Number.isFinite(target.size)) target.size = base.size || 200;
      target.size = clamp(target.size, 20, bounds.maxSize);
      delete target.align;
      delete target.angle;
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
  const inputAlign = document.getElementById('coordsFieldAlign');
  const hintEl = document.getElementById('coordsEditorHint');
  const statusEl = document.getElementById('coordsStatus');
  const saveBtn = document.getElementById('coordsSaveBtn');
  const resetBtn = document.getElementById('coordsResetBtn');
  const defaultsBtn = document.getElementById('coordsDefaultsBtn');
  const summaryPre = document.querySelector('#templateCoordsSummary pre');
  const summaryEmpty = document.querySelector('#templateCoordsSummary p');

  if(!selectField || !inputX || !inputY || !inputSize || !saveBtn || !resetBtn || !defaultsBtn) return;

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

  state.storedSnapshot = cloneCoords(state.coords);
  state.savedSerialized = JSON.stringify(serializeForSave(state.coords));

  function updateSummary(){
    const payload = serializeForSave(state.coords);
    const json = JSON.stringify(payload, null, 2);
    if(summaryPre){ summaryPre.textContent = json; if(summaryPre.classList.contains('d-none')) summaryPre.classList.remove('d-none'); }
    if(summaryEmpty){ summaryEmpty.textContent = ''; summaryEmpty.classList.add('d-none'); }
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
    const naturalWidth = bgImg.naturalWidth || state.canvasWidth || rect.width;
    const naturalHeight = bgImg.naturalHeight || state.canvasHeight || rect.height;
    if(!state.canvasWidth && naturalWidth) state.canvasWidth = naturalWidth;
    if(!state.canvasHeight && naturalHeight) state.canvasHeight = naturalHeight;
    if(naturalWidth){ state.scale = rect.width / naturalWidth; }
    else { state.scale = 1; }
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
    const left = data.x * state.scale;
    const top = data.y * state.scale;
    marker.style.left = left+'px';
    marker.style.top = top+'px';
    marker.classList.toggle('is-active', state.activeField === field);
    if(meta.isQr){
      const box = marker.querySelector('.coords-marker__qr-box');
      if(box){
        const size = (data.size || 0) * state.scale;
        box.style.width = Math.max(8, size)+'px';
        box.style.height = Math.max(8, size)+'px';
      }
    }
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
    const labelAlign = editorRoot.querySelector('label[for="coordsFieldAlign"]');
    if(meta.supportsAngle){
      inputAngle.value = data.angle !== undefined ? Math.round(data.angle * 10)/10 : 0;
      inputAngle.hidden = false;
      if(labelAngle) labelAngle.hidden = false;
    } else {
      inputAngle.value = '';
      inputAngle.hidden = true;
      if(labelAngle) labelAngle.hidden = true;
    }
    if(meta.supportsAlign){
      inputAlign.value = data.align || 'left';
      inputAlign.hidden = false;
      if(labelAlign) labelAlign.hidden = false;
    } else {
      inputAlign.value = 'left';
      inputAlign.hidden = true;
      if(labelAlign) labelAlign.hidden = true;
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
    if(patch.align !== undefined && (FIELD_META[field] || {}).supportsAlign){
      const align = String(patch.align).toLowerCase();
      if(ALIGN_VALUES.has(align) && align !== data.align){ data.align = align; changed = true; }
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

    function onMove(ev){
      if(ev.pointerId !== pointerId) return;
      const relX = (ev.clientX - rect.left) / state.scale;
      const relY = (ev.clientY - rect.top) / state.scale;
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
  inputAlign.addEventListener('change', ()=> applyPatch(state.activeField, { align: inputAlign.value }));

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
      updateSummary();
      updateDirty();
      const stamp = new Date().toLocaleTimeString('uk-UA', { hour12:false });
      updateStatus('Збережено о '+stamp, 'success');
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
  });

  defaultsBtn.addEventListener('click', ()=>{
    state.coords = composeCoords({ useStored: false, useGlobal: true });
    renderAllMarkers();
    populateInputs(state.activeField);
    updateDirty();
    updateStatus('Завантажено глобальні координати (потрібно зберегти)', 'warning');
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
  createMarkers();
  renderAllMarkers();
  setActiveField(state.activeField);
  updateDirty();
})();
