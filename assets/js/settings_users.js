// Operators management (create + list read-only with link to operator.php)
(function(){
  function $(s,ctx=document){ return ctx.querySelector(s); }
  function ce(t,cls){ const el=document.createElement(t); if(cls) el.className=cls; return el; }
  const state = { loaded:false, csrf:null, page:1, pages:1, per_page:50, total:0, sort:'id', dir:'asc' };
  function fetchJSON(url, opts={}){ return fetch(url, opts).then(r=>r.json()); }
  function ensureCsrf(){ if(state.csrf) return state.csrf; const f=$('#opCreateForm'); if(f){ state.csrf = f.querySelector('input[name="_csrf"]').value; } return state.csrf; }
  function loadList(page){
    if(page) state.page = page;
    const tbody = $('#operatorsTable tbody');
    if(!tbody) return;
    const url = `/api/operators_list.php?page=${state.page}&per_page=${state.per_page}&sort=${state.sort}&dir=${state.dir}`;
    fetchJSON(url).then(j=>{
      if(!j.ok){ tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">Помилка завантаження</td></tr>'; return; }
      const rows = j.users;
      state.page = j.page; state.pages = j.pages; state.per_page = j.per_page; state.total = j.total; state.sort = j.sort; state.dir = j.dir;
      if(!rows.length){ tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">Немає користувачів</td></tr>'; return; }
      tbody.innerHTML='';
      rows.forEach(r=>{
        const tr = ce('tr');
        const inactive = (r.is_active == 0);
        const isAdmin = r.role === 'admin';
        const created = r.created_at ? formatCreated(r.created_at) : '—';
        tr.className = (inactive?'row-inactive ':'') + (isAdmin?'row-admin':'');
        tr.innerHTML = '<td>'+r.id+'</td>'
          +'<td class="mono">'+escapeHtml(r.username)+'</td>'
          +'<td>'+r.role+'</td>'
          +'<td>'+(inactive?'<span class="badge badge-danger">неактивний</span>':'<span class="badge badge-success">активний</span>')+'</td>'
          +'<td>'+created+'</td>'
          +'<td><a class="btn btn-light btn-sm" href="/operator.php?id='+r.id+'">→</a></td>';
        tbody.appendChild(tr);
      });
      updateSummary();
      renderPagination();
    }).catch(()=>{ tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">Мережева помилка</td></tr>'; });
  }
  // Removed inline action helpers in lite mode
  function toFormData(obj){ const fd=new FormData(); Object.keys(obj).forEach(k=>fd.append(k,obj[k])); return fd; }
  function bindCreate(){ const f=$('#opCreateForm'); if(!f || f.__bound) return; f.__bound=true; const status=$('#opCreateStatus'); const btn=$('#opCreateBtn'); f.addEventListener('submit',e=>{ e.preventDefault(); status.textContent='Надсилаємо...'; btn.disabled=true; const fd=new FormData(f); fetch(f.action,{method:'POST',body:fd,credentials:'same-origin'}).then(r=>r.json()).then(j=>{ if(!j.ok){ status.textContent=mapErr(j.error); } else { status.textContent='Створено'; f.reset(); loadList(); } }).catch(()=>{ status.textContent='Мережева помилка'; }).finally(()=>{ btn.disabled=false; }); }); }
  function mapErr(code){ switch(code){ case 'empty': return 'Порожні поля'; case 'mismatch': return 'Паролі не співпадають'; case 'short': return 'Пароль <8'; case 'exists': return 'Логін зайнятий'; case 'uname': return 'Невалідний логін'; default: return 'Помилка'; } }
  function escapeHtml(str){ return str.replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[c])); }
  function formatCreated(val){
    // Expect MySQL DATETIME "YYYY-MM-DD HH:MM:SS"; trim seconds
    if(!val) return '—';
    if(val.length >= 16) return val.substring(0,16); // keep YYYY-MM-DD HH:MM
    return val;
  }
  function updateSummary(){
    const el = $('#operatorsSummary');
    if(!el) return;
    if(state.total === 0){ el.textContent=''; return; }
    const from = (state.page-1)*state.per_page + 1;
    const to = Math.min(state.page*state.per_page, state.total);
    el.textContent = `Показано ${from}–${to} з ${state.total}`;
  }
  function renderPagination(){
    const wrap = $('#operatorsPagination');
    if(!wrap) return;
    wrap.innerHTML='';
    if(state.pages <= 1){ return; }
    const nav = ce('nav','pagination');
    const delta = 2;
    const pages = [];
    for(let i=1;i<=state.pages;i++){
      if(i===1 || i===state.pages || (i>=state.page-delta && i<=state.page+delta)) pages.push(i);
    }
    const withDots=[]; let last=0;
    pages.forEach(p=>{ if(p-last>1) withDots.push('...'); withDots.push(p); last=p; });
    withDots.forEach(p=>{
      if(p==='...'){ const span=ce('span','page-dots'); span.textContent='...'; nav.appendChild(span); return; }
      const a=ce('a','page'+(p===state.page?' active':'')); a.href='#'; a.textContent=p; a.addEventListener('click',e=>{ e.preventDefault(); if(p!==state.page){ loadList(p); } }); nav.appendChild(a);
    });
    wrap.appendChild(nav);
  }
  function init(){ if($('#operatorsTable')){ bindCreate(); loadList(1); } }
  document.addEventListener('settings:section-loaded',e=>{ if(e.detail && e.detail.tab==='users') init(); });
  document.addEventListener('DOMContentLoaded', ()=>{ const panel = document.querySelector('.tabs .tab.active[data-tab="users"]'); if(panel) init(); });
})();
