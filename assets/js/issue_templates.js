// Динамічне завантаження шаблонів для сторінки видачі
// Мінімальний скелет: підтягуємо список і будуємо <select>
(function(){
  const singleSel = document.getElementById('templateSelect');
  const bulkSel = document.getElementById('bulkTemplateSelect');
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

  function onChange(sel){
    const path = resolvePathFromSelect(sel);
    if(!path){
      // revert до дефолту
      const body = document.body;
      const tpl = body ? body.getAttribute('data-template') : '/files/cert_template.jpg';
      window.__ISSUE_TEMPLATE_OVERRIDE = null;
      document.dispatchEvent(new CustomEvent('cert-template-change', {detail:{path: tpl}}));
      return;
    }
    window.__ISSUE_TEMPLATE_OVERRIDE = path;
    document.dispatchEvent(new CustomEvent('cert-template-change', {detail:{path}}));
  }

  if(singleSel){
    singleSel.addEventListener('change', ()=>{
      const hid = document.getElementById('templateIdHidden'); if(hid){ hid.value = singleSel.value || ''; }
      onChange(singleSel);
    });
  }
  if(bulkSel){ bulkSel.addEventListener('change', ()=> onChange(bulkSel)); }
})();
