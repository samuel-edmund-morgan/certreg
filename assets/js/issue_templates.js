// Динамічне завантаження шаблонів для сторінки видачі
// Мінімальний скелет: підтягуємо список і будуємо <select>
(function(){
  const singleSel = document.getElementById('templateSelect');
  const bulkSel = document.getElementById('bulkTemplateSelect');
  const awardDisplay = document.getElementById('awardTitleDisplay');
  const coordsMap = new Map();
  const metaMap = new Map();
  const awardMap = new Map();
  window.__ACTIVE_TEMPLATE_META = window.__ACTIVE_TEMPLATE_META || { width: null, height: null };
  if(!singleSel && !bulkSel) return; // якщо UI ще не додано

  function buildOption(it){
    const o=document.createElement('option');
    o.value=String(it.id);
    // Відображаємо назву шаблону; у дужках код організації
    const name = (it.name && String(it.name).trim()) || (it.code && String(it.code).trim()) || ('T'+it.id);
    const label = name + (it.org_code?(' ['+it.org_code+']'):'');
    o.textContent = label;
    // Збережемо допоміжні поля для побудови шляху до файла
    if(it.org_id) o.dataset.orgId = String(it.org_id);
    if(it.file_ext) o.dataset.ext = String(it.file_ext);
    if(Number.isFinite(Number(it.width)) && Number(it.width) > 0) o.dataset.width = String(it.width);
    if(Number.isFinite(Number(it.height)) && Number(it.height) > 0) o.dataset.height = String(it.height);
    return o;
  }

  function fillSelect(sel, items){
    if(!sel) return;
    sel.innerHTML='';
    if(!items.length){
      sel.disabled=true;
      const opt=document.createElement('option'); opt.textContent='Немає шаблонів'; opt.value=''; sel.appendChild(opt);
      return;
    }
    const defOpt=document.createElement('option'); defOpt.textContent='(Стандартний)'; defOpt.value=''; sel.appendChild(defOpt);
      items.forEach(it=> sel.appendChild(buildOption(it)));
    sel.disabled=false;
  }

  fetch('/api/templates_list.php', {credentials:'same-origin'})
    .then(r=> r.ok ? r.json() : Promise.reject())
    .then(js => {
      if(!js || !js.ok){ if(singleSel) singleSel.disabled=true; if(bulkSel) bulkSel.disabled=true; return; }
      // Показуємо тільки активні шаблони
      const items = (js.items||[]).filter(it=> String(it.status).toLowerCase()==='active');
      items.forEach(it=>{
        const key = String(it.id);
        if(it && typeof it.coords === 'object' && it.coords !== null){
          coordsMap.set(key, it.coords);
        } else {
          coordsMap.set(key, null);
        }
        metaMap.set(key, {
          width: (Number.isFinite(Number(it.width)) && Number(it.width) > 0) ? Number(it.width) : null,
          height: (Number.isFinite(Number(it.height)) && Number(it.height) > 0) ? Number(it.height) : null
        });
        if(typeof it.award_title === 'string'){
          const trimmed = it.award_title.trim();
          awardMap.set(key, trimmed || 'Нагорода');
        } else {
          awardMap.set(key, 'Нагорода');
        }
      });
      fillSelect(singleSel, items);
      fillSelect(bulkSel, items);
    }).catch(()=>{ try{ if(singleSel) singleSel.disabled=true; if(bulkSel) bulkSel.disabled=true; }catch(_e){} });

  function resolvePathFromSelect(sel){
    if(!sel) return null;
    const id = sel.value;
    if(!id) return null;
    const opt = sel.selectedOptions && sel.selectedOptions[0];
    const orgId = opt && opt.dataset && opt.dataset.orgId ? opt.dataset.orgId : null;
    const ext = opt && opt.dataset && opt.dataset.ext ? opt.dataset.ext : 'jpg';
    if(!orgId) return null;
    // Використовуємо оригінальний файл як фон
    return '/files/templates/'+orgId+'/'+id+'/original.'+ext;
  }

  function normalizeDimension(val){
    const num = Number(val);
    return Number.isFinite(num) && num > 0 ? num : null;
  }

  function onChange(sel){
    const path = resolvePathFromSelect(sel);
    let coords = null;
    let meta = { width: null, height: null };
    let awardTitle = 'Нагорода';
    try {
      if(sel && sel.value){
        const stored = coordsMap.get(String(sel.value));
        if(stored && typeof stored === 'object'){
          coords = stored;
        }
        const optMeta = metaMap.get(String(sel.value));
        if(optMeta){
          meta = {
            width: normalizeDimension(optMeta.width),
            height: normalizeDimension(optMeta.height)
          };
        }
        const award = awardMap.get(String(sel.value));
        if(typeof award === 'string' && award.trim()){
          awardTitle = award.trim();
        }
      }
    } catch(_e){}
    if(coords === null){
      // ensure null when undefined so listeners can reset to defaults
      coords = null;
    }
    const opt = sel && sel.selectedOptions && sel.selectedOptions[0];
    if(opt){
      const w = normalizeDimension(opt.dataset?.width);
      const h = normalizeDimension(opt.dataset?.height);
      if(w) meta.width = w;
      if(h) meta.height = h;
    }
    if(sel === singleSel){
      window.__SINGLE_TEMPLATE_COORDS = coords;
      applyAwardValue('awardTitleDisplay', awardTitle);
    } else if(sel === bulkSel){
      window.__BULK_TEMPLATE_COORDS = coords;
      window.__BULK_AWARD_TITLE = awardTitle;
    }
    window.__ACTIVE_TEMPLATE_COORDS = coords;
    window.__ACTIVE_TEMPLATE_META = meta;
    window.__ACTIVE_AWARD_TITLE = awardTitle;
    if(!path){
      // revert до дефолту
      const body = document.body;
      const tpl = body ? body.getAttribute('data-template') : '/files/cert_template.jpg';
      window.__ISSUE_TEMPLATE_OVERRIDE = null;
      document.dispatchEvent(new CustomEvent('cert-template-change', {detail:{path: tpl, coords, width: meta.width, height: meta.height, award_title: awardTitle}}));
      return;
    }
    window.__ISSUE_TEMPLATE_OVERRIDE = path;
    document.dispatchEvent(new CustomEvent('cert-template-change', {detail:{path, coords, width: meta.width, height: meta.height, award_title: awardTitle}}));
  }

  function applyAwardValue(id, value){
    const resolved = (value && typeof value === 'string') ? value.trim() : '';
    const finalVal = resolved || 'Нагорода';
    if(id === 'awardTitleDisplay'){
      if(awardDisplay){
        awardDisplay.textContent = finalVal;
        awardDisplay.dataset.awardTitle = finalVal;
      }
      return;
    }
    const input = document.getElementById(id);
    if(input){ input.value = finalVal; }
  }

  if(singleSel){
    singleSel.addEventListener('change', ()=>{
      const hid = document.getElementById('templateIdHidden'); if(hid){ hid.value = singleSel.value || ''; }
      onChange(singleSel);
    });
  }
  if(bulkSel){ bulkSel.addEventListener('change', ()=> onChange(bulkSel)); }
})();
