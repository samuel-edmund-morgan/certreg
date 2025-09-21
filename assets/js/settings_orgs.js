(()=>{
  const state={page:1,pages:1,per:20,sort:'id',dir:'ASC',q:'',loading:false};
  let table, tbody, summary, pagination, searchInput, searchBtn, resetBtn, createForm;

  function esc(str){return str==null?'':String(str).replace(/[&<>"']/g,s=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[s]));}
  function fmtDate(dt){ if(!dt) return ''; return dt.replace('T',' ').substring(0,19); }

  async function load(){
    if(state.loading) return; state.loading=true;
  tbody.innerHTML='<tr><td colspan="6" class="text-center text-muted fs-13">Завантаження...</td></tr>';
    try {
      const p=new URLSearchParams({page:state.page,per_page:state.per,sort:state.sort,dir:state.dir});
      if(state.q) p.set('q',state.q);
      const r= await fetch('/api/org_list.php?'+p.toString(),{credentials:'same-origin'});
      if(!r.ok){ throw new Error('HTTP '+r.status); }
      const j= await r.json();
      if(!j.ok) throw new Error('bad json');
      state.pages=j.pages; state.page=j.page; state.total=j.total;
  if(j.orgs.length===0){ tbody.innerHTML='<tr><td colspan="6" class="text-center text-muted fs-13">Нічого не знайдено</td></tr>'; }
      else {
        tbody.innerHTML=j.orgs.map(o=>renderRow(o)).join('');
      }
      renderSummary(); renderPagination(); // row actions use delegated listener
    } catch(e){ tbody.innerHTML='<tr><td colspan="7" class="text-center text-danger">Помилка завантаження</td></tr>'; }
    finally { state.loading=false; }
  }

  function renderRow(o){
    const brandParts=[]; if(o.primary_color) brandParts.push('<span class="color-dot" style="--c:'+esc(o.primary_color)+'" title="primary"></span>');
    if(o.accent_color) brandParts.push('<span class="color-dot" style="--c:'+esc(o.accent_color)+'" title="accent"></span>');
    if(o.secondary_color) brandParts.push('<span class="color-dot" style="--c:'+esc(o.secondary_color)+'" title="secondary"></span>');
    const logo = o.logo_path?'<span class="fs-12 text-muted">logo</span>':'';
    const fav  = o.favicon_path?'<span class="fs-12 text-muted">fav</span>':'';
    const status = o.is_active==1?'<span class="badge ok">Активна</span>':'<span class="badge off">Вимкнена</span>';
    const nameHtml = esc(o.name).replace(/\\n/g,'<br>');
    const isDefault = o.is_default==1;
  return `<tr data-id="${o.id}"${isDefault?' data-default="1"':''} title="${nameHtml.replace(/<br>/g,' ')}">
      <td>${o.id}</td>
      <td><code>${esc(o.code)}</code></td>
      <td class="flex gap-4 align-center">${brandParts.join('')||'<span class=fs-12>-</span>'} ${logo} ${fav}</td>
      <td class="fs-12 text-muted">${esc(o.created_at||'').replace('T',' ').substring(0,19)}</td>
      <td>${status}${isDefault?' <span class="badge" title="Це базова організація">Основна</span>':''}</td>
      <td class="flex gap-4">${isDefault?'':'<button class="btn btn-xs btn-secondary toggle-org" data-active="'+o.is_active+'">'+(o.is_active==1?'Вимкнути':'Увімкнути')+'</button>'}<button class="btn btn-xs btn-warning edit-org">Редагувати</button>${isDefault?'':'<button class="btn btn-xs btn-danger del-org">Видалити</button>'}</td>
    </tr>`;
  }

  function renderSummary(){
    const from = state.total===0?0: ( (state.page-1)*state.per + 1 );
    const to = Math.min(state.page*state.per, state.total);
    summary.textContent = `Показано ${from}-${to} з ${state.total}`;
  }

  function renderPagination(){
    if(state.pages<=1){ pagination.innerHTML=''; return; }
    const parts=[]; const cur=state.page; const total=state.pages;
    function add(p,label){ parts.push(`<button class="pg-btn${p===cur?' active':''}" data-p="${p}">${label||p}</button>`); }
    add(1);
    if(cur>3) parts.push('<span class="pg-ellipsis">…</span>');
    for(let p=Math.max(2,cur-1); p<=Math.min(total-1,cur+1); p++){ if(p!==1 && p!==total) add(p); }
    if(cur<total-2) parts.push('<span class="pg-ellipsis">…</span>');
    if(total>1) add(total);
    pagination.innerHTML=parts.join('');
    pagination.querySelectorAll('.pg-btn').forEach(btn=>{
      btn.addEventListener('click',()=>{ const p=parseInt(btn.getAttribute('data-p'),10); if(p && p!==state.page){ state.page=p; load(); } });
    });
  }

  function bindStatic(){
    if(!table) return;
    table.querySelectorAll('th.sortable').forEach(th=>{
      th.addEventListener('click',()=>{
        const s=th.getAttribute('data-sort');
        if(state.sort===s){ state.dir = state.dir==='ASC'?'DESC':'ASC'; } else { state.sort=s; state.dir='ASC'; }
        state.page=1; load();
      });
    });
    if(searchBtn) searchBtn.addEventListener('click',()=>{ state.q=searchInput.value.trim(); state.page=1; load(); });
    if(resetBtn) resetBtn.addEventListener('click',()=>{ searchInput.value=''; state.q=''; state.page=1; load(); });
    if(searchInput) searchInput.addEventListener('keydown',e=>{ if(e.key==='Enter'){ e.preventDefault(); state.q=searchInput.value.trim(); state.page=1; load(); }});
    if(createForm){
      createForm.addEventListener('submit', async e=>{
        e.preventDefault(); const btn=document.getElementById('orgCreateBtn'); const st=document.getElementById('orgCreateStatus');
        st.textContent='Збереження...'; btn.disabled=true;
        try {
          const fd=new FormData(createForm);
          const r= await fetch(createForm.action,{method:'POST',credentials:'same-origin',body:fd});
          const j= await r.json();
            if(j.ok||j.data){ st.textContent='Створено'; createForm.reset(); state.page=1; load(); }
            else { st.textContent='Помилка'; if(j.errors){ console.warn(j.errors); } }
        } catch(err){ st.textContent='Помилка мережі'; }
        finally { btn.disabled=false; setTimeout(()=>{ st.textContent=''; },3000); }
      });
    }
  }

  function onTableClick(e){
    const tgt = e.target;
    const tr = tgt.closest('tr[data-id]'); if(!tr) return;
    const id = tr.getAttribute('data-id');
    if(tgt.classList.contains('toggle-org')){
      const cur = tgt.getAttribute('data-active'); const newVal = cur==='1'?0:1; tgt.disabled=true;
      const fd=new FormData(); fd.append('_csrf', (window.__csrf||document.querySelector('input[name=_csrf]')?.value||'')); fd.append('id',id); fd.append('is_active',newVal);
      fetch('/api/org_set_active.php',{method:'POST',credentials:'same-origin',body:fd})
        .then(r=>r.json().catch(()=>({}))).then(j=>{ if(j.ok){ load(); } else { tgt.disabled=false; } });
      return;
    }
    if(tgt.classList.contains('del-org')){
      if(!confirm('Видалити організацію #' + id + '?')) return;
      const fd=new FormData(); fd.append('_csrf', (window.__csrf||document.querySelector('input[name=_csrf]')?.value||'')); fd.append('id',id);
      fetch('/api/org_delete.php',{method:'POST',credentials:'same-origin',body:fd})
        .then(r=>r.json().catch(()=>({}))).then(j=>{ if(j.ok){ load(); } else if(j.error==='has_operators'){ alert('Неможливо: оператори ще привʼязані'); } else if(j.error==='has_tokens'){ alert('Неможливо: токени існують'); } else { alert('Помилка: '+(j.error||'невідома')); } });
      return;
    }
    if(tgt.classList.contains('edit-org')){
      if(tr.hasAttribute('data-default')){
        // For the default (base) organization redirect to Branding tab
        window.location.href = '/settings.php?tab=branding';
        return;
      } else {
        alert('Inline редагування додамо після асоціації операторів. Наразі використовуйте оновлення через окремий endpoint (ще не привʼязано).');
        return;
      }
    }
  }

  function initIfPresent(){
    table=document.getElementById('orgsTable');
    if(!table) return;
    tbody=table.querySelector('tbody');
    summary=document.getElementById('orgsSummary');
    pagination=document.getElementById('orgsPagination');
    searchInput=document.getElementById('orgSearch');
    searchBtn=document.getElementById('orgSearchBtn');
    resetBtn=document.getElementById('orgResetBtn');
    createForm=document.getElementById('orgCreateForm');
    bindStatic();
    if(table) table.addEventListener('click', onTableClick);
    load();
  }

  document.addEventListener('settings:section-loaded', (e)=>{ if(e.detail.tab==='organizations'){ initIfPresent(); }});
  if(new URL(location.href).searchParams.get('tab')==='organizations'){ initIfPresent(); }
})();
