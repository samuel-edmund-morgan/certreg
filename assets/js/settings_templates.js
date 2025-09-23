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

  // Removed toggle/edit/replace/delete functionality; management now only via detail page.

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
    // All actionable buttons removed (only arrow link remains which is a normal anchor)
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
