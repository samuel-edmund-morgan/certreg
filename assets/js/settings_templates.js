(()=>{
  // Templates management UI logic
  const tableId = 'templatesTable';
  let table, tbody, summary, createForm, orgSelect;

  function esc(str){return str==null?'':String(str).replace(/[&<>"']/g,s=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[s]));}
  function fmtBytes(n){ if(!n && n!==0) return ''; const u=['B','KB','MB','GB']; let i=0; let v=n; while(v>=1024 && i<u.length-1){ v/=1024; i++; } return (i===0? v: v.toFixed(1))+' '+u[i]; }
  function qs(sel,ctx=document){return ctx.querySelector(sel);}  

  async function loadList(){
    if(!tbody) return;
    tbody.innerHTML='<tr><td colspan="7" class="text-center fs-13 text-muted">Завантаження...</td></tr>';
    try {
      const params = new URLSearchParams();
      if(orgSelect && orgSelect.value) params.set('org_id', orgSelect.value);
      const r = await fetch('/api/templates_list.php'+(params.toString()?'?'+params.toString():''), {credentials:'same-origin'});
      if(!r.ok) throw new Error('HTTP '+r.status);
      const j = await r.json();
      if(!j.ok){ tbody.innerHTML='<tr><td colspan="7" class="text-center text-danger">Помилка</td></tr>'; return; }
      if(!j.items.length){ tbody.innerHTML='<tr><td colspan="7" class="text-center fs-13 text-muted">Немає шаблонів</td></tr>'; summary.textContent='0'; return; }
      tbody.innerHTML = j.items.map(renderRow).join('');
      summary.textContent = 'Всього: '+j.items.length;
    } catch(err){ console.error(err); tbody.innerHTML='<tr><td colspan="7" class="text-center text-danger">Помилка завантаження</td></tr>'; }
  }

  function renderRow(t){
  const prev = t.preview_url ? `<img src="${esc(t.preview_url)}" alt="preview" class="tpl-prev-img" data-full="${esc(t.preview_url)}">` : '<span class="fs-11 text-muted">немає</span>';
    const size = fmtBytes(t.file_size)+'\n'+esc(t.width)+'×'+esc(t.height);
    const status = t.status === 'active' ? '<span class="badge ok">active</span>' : '<span class="badge off">disabled</span>';
    const code = `<code>${esc(t.code||'')}</code>`;
    return `<tr data-id="${t.id}">
      <td>${t.id}</td>
      <td class="tpl-prev">${prev}</td>
      <td><div class="fw-600">${esc(t.name)}</div><div class="fs-12 text-muted">${code}</div></td>
      <td class="fs-12">${size}</td>
      <td class="fs-12">v${t.version}</td>
      <td>${status}</td>
      <td class="tpl-actions flex gap-4">
        <a class="btn btn-xs btn-light" href="/template.php?id=${t.id}" title="Деталі" aria-label="Деталі шаблону ${t.id}">→</a>
        <button class="btn btn-xs btn-secondary tpl-toggle" title="Toggle active">Toggle</button>
        <button class="btn btn-xs btn-warning tpl-edit" title="Edit meta">Редагувати</button>
        <button class="btn btn-xs btn-accent tpl-replace" title="Replace file">Фон</button>
        <button class="btn btn-xs btn-danger tpl-del" title="Delete">Видалити</button>
      </td>
    </tr>`;
  }

  function currentCsrf(){ return document.querySelector('meta[name=csrf]')?.getAttribute('content') || document.querySelector('input[name=_csrf]')?.value || ''; }

  function bindCreate(){
    if(!createForm) return;
    const btn = qs('#tplCreateBtn');
    const st  = qs('#tplCreateStatus');
    createForm.addEventListener('submit', async e=>{
      e.preventDefault();
      st.textContent='Створення...'; btn.disabled=true;
      try {
        if(orgSelect && !orgSelect.value){
          st.textContent='Оберіть організацію'; btn.disabled=false; return;
        }
        const fd = new FormData(createForm);
        const r = await fetch(createForm.action,{method:'POST',body:fd,credentials:'same-origin'});
        const j = await r.json().catch(()=>({}));
        if(j.ok){ st.textContent='OK'; createForm.reset(); loadList(); }
        else { st.textContent='Помилка: '+(j.error||''); }
      } catch(err){ st.textContent='Мережа'; }
      finally { btn.disabled=false; setTimeout(()=>{ st.textContent=''; },3500); }
    });
  }

  async function toggleTemplate(id, btn){
    btn.disabled=true; const fd=new FormData(); fd.append('_csrf', currentCsrf()); fd.append('id', id);
    try { const r= await fetch('/api/template_toggle.php',{method:'POST',credentials:'same-origin',body:fd}); const j= await r.json(); if(j.ok){ loadList(); } else { alert('Помилка: '+(j.error||'')); btn.disabled=false; } } catch(e){ btn.disabled=false; }
  }
  async function deleteTemplate(id, btn){
    if(!confirm('Видалити шаблон #'+id+'?')) return;
    btn.disabled=true; const fd=new FormData(); fd.append('_csrf', currentCsrf()); fd.append('id', id);
    try { const r= await fetch('/api/template_delete.php',{method:'POST',credentials:'same-origin',body:fd}); const j= await r.json(); if(j.ok){ loadList(); } else { alert('Помилка: '+(j.error||'')); btn.disabled=false; } } catch(e){ btn.disabled=false; }
  }
  function openEditDialog(row){
    const id=row.getAttribute('data-id');
    const name=row.querySelector('td:nth-child(3) .fw-600')?.textContent||'';
    const status=row.querySelector('td:nth-child(6) .badge')?.classList.contains('ok')?'active':'disabled';
    const dlg=document.createElement('dialog');
    dlg.className='tpl-edit-dialog';
    dlg.innerHTML=`<form method="dialog" class="form tpl-edit-form">\n<h3 class="mt-0 mb-8">Редагувати шаблон #${id}</h3>\n<label>Назва<br><input type="text" name="name" value="${esc(name)}" maxlength="160" required></label>\n<label>Статус<br><select name="status"><option value="active" ${status==='active'?'selected':''}>active</option><option value="disabled" ${status==='disabled'?'selected':''}>disabled</option></select></label>\n<div class="flex gap-8 mt-12">\n<button class="btn btn-primary" value="save">Зберегти</button>\n<button class="btn btn-light" value="cancel">Скасувати</button>\n</div>\n</form>`;
    document.body.appendChild(dlg); dlg.showModal();
    dlg.addEventListener('close', async ()=>{
      if(dlg.returnValue==='save'){
        const fd=new FormData(); fd.append('_csrf', currentCsrf()); fd.append('id', id); const form=dlg.querySelector('form'); fd.append('name', form.name.value.trim()); fd.append('status', form.status.value);
        try { const r= await fetch('/api/template_update.php',{method:'POST',credentials:'same-origin',body:fd}); const j= await r.json(); if(j.ok){ loadList(); } else { alert('Помилка: '+(j.error||'')); } } catch(e){ alert('Мережа'); }
      }
      dlg.remove();
    });
  }
  function openReplaceDialog(row){
    const id=row.getAttribute('data-id');
    const dlg=document.createElement('dialog');
    dlg.className='tpl-replace-dialog';
    dlg.innerHTML=`<form method="dialog" class="form tpl-replace-form" enctype="multipart/form-data">\n<h3 class="mt-0 mb-8">Заміна фону #${id}</h3>\n<input type="file" name="template_file" accept="image/jpeg,image/png,image/webp" required>\n<p class="fs-12 text-muted mt-4">JPG/PNG/WEBP ≤15MB; розмір 200..12000px.</p>\n<div class="flex gap-8 mt-12">\n<button class="btn btn-primary" value="save">Замінити</button>\n<button class="btn btn-light" value="cancel">Скасувати</button>\n</div>\n</form>`;
    document.body.appendChild(dlg); dlg.showModal();
    dlg.addEventListener('close', async ()=>{
      if(dlg.returnValue==='save'){
        const form=dlg.querySelector('form'); if(!form.template_file.files.length){ dlg.remove(); return; }
        const fd=new FormData(); fd.append('_csrf', currentCsrf()); fd.append('id', id); fd.append('template_file', form.template_file.files[0]);
        try { const r= await fetch('/api/template_update.php',{method:'POST',credentials:'same-origin',body:fd}); const j= await r.json(); if(j.ok){ loadList(); } else { alert('Помилка: '+(j.error||'')); } } catch(e){ alert('Мережа'); }
      }
      dlg.remove();
    });
  }

  function onTableClick(e){
    // Preview click (non-button)
    const img = e.target.closest('img.tpl-prev-img');
    if(img){
      const full = img.getAttribute('data-full');
      const dlg=document.createElement('dialog');
      dlg.className='tpl-prev-dialog';
      dlg.innerHTML=`<div class="flex flex-col"><img src="${esc(full)}" alt="preview" class="tpl-big-prev"/><div class="mt-8 flex gap-8"><button class="btn btn-primary" data-act="close">Закрити</button></div></div>`;
      document.body.appendChild(dlg); dlg.showModal();
      dlg.addEventListener('click', ev=>{ if(ev.target===dlg) dlg.close(); });
      dlg.querySelector('[data-act=close]').addEventListener('click', ()=> dlg.close());
      dlg.addEventListener('close', ()=> dlg.remove());
      return;
    }
    const btn=e.target.closest('button'); if(!btn) return;
    const row=btn.closest('tr[data-id]'); if(!row) return; const id=row.getAttribute('data-id');
    if(btn.classList.contains('tpl-toggle')){ toggleTemplate(id, btn); }
    else if(btn.classList.contains('tpl-del')){ deleteTemplate(id, btn); }
    else if(btn.classList.contains('tpl-edit')){ openEditDialog(row); }
    else if(btn.classList.contains('tpl-replace')){ openReplaceDialog(row); }
  }

  async function loadOrganizationsForAdmin(){
    if(!orgSelect) return;
    try {
      const r = await fetch('/api/org_list.php?per_page=100&page=1',{credentials:'same-origin'});
      const j = await r.json();
      orgSelect.innerHTML='<option value="">(Всі)</option>';
      if(j.ok && Array.isArray(j.orgs)){
        j.orgs.forEach(o=>{ const opt=document.createElement('option'); opt.value=o.id; opt.textContent=o.name+' ['+o.code+']'; orgSelect.appendChild(opt); });
      }
    }catch(e){ orgSelect.innerHTML='<option value="">(помилка)</option>'; }
  }

  function init(){
    table=document.getElementById(tableId); if(!table) return;
    tbody=table.querySelector('tbody'); summary=document.getElementById('templatesSummary'); createForm=document.getElementById('templateCreateForm'); orgSelect=document.getElementById('tplOrgSelect');
    if(orgSelect){ orgSelect.addEventListener('change', ()=> loadList()); loadOrganizationsForAdmin(); }
    bindCreate();
    table.addEventListener('click', onTableClick);
    loadList();
  }

  document.addEventListener('settings:section-loaded', e=>{ if(e.detail.tab==='templates'){ init(); }});
  if(new URL(location.href).searchParams.get('tab')==='templates'){ init(); }
})();
