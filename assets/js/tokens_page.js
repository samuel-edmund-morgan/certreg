(function(){
  // Simplified: only per-page selection (no cross-page persistence)
  function bindAjax(formSelector, confirmText){
    document.querySelectorAll(formSelector).forEach(f=>{
      f.addEventListener('submit', async e=>{
        e.preventDefault();
        if(confirmText && !confirm(confirmText)) return;
        const fd = new FormData(f);
        const res = await fetch(f.action,{method:'POST',body:fd,credentials:'same-origin'});
        if(!res.ok){ alert('Помилка запиту'); return; }
        const js = await res.json();
        if(js.ok){ location.reload(); } else { alert('Не вдалося: '+(js.error||'??')); }
      });
    });
  }
  bindAjax('.revoke-form','Відкликати цей токен?');
  bindAjax('.unrevoke-form','Скасувати відкликання і зробити активним?');
  // Авто-сабміт зміни фільтра стану
  const filterForm = document.getElementById('filterForm');
  if(filterForm){
    const stateSel = filterForm.querySelector('select[name=state]');
    if(stateSel){
      stateSel.addEventListener('change', ()=>{
        filterForm.querySelector('input[name=page]')?.remove();
        filterForm.submit();
      });
    }
  }
  // bulk operations
  const bulkForm = document.getElementById('bulkForm');
  if(bulkForm){
  const totalAll = parseInt(bulkForm.dataset.total||'0',10);
  const pageNum = parseInt(bulkForm.dataset.page||'1',10);
    const chkAll = document.getElementById('chkAll');
    const bulkBar = document.getElementById('bulkBar');
    const executeBtn = document.getElementById('bulkExecute');
    const cancelBtn = document.getElementById('bulkCancel');
    const selCountEl = document.getElementById('selCount');
  const selSummary = document.getElementById('selSummary');
    const actionSel = document.getElementById('bulkAction');
    const reasonInput = document.getElementById('bulkReason');
    const statusEl = document.getElementById('bulkStatus');
  const progressEl = document.getElementById('bulkProgress');
    const MAX = 100;
    function rowCheckboxes(){ return Array.from(document.querySelectorAll('.rowChk')); }
  function applyPersistedToPage(){}
    function selected(){ return rowCheckboxes().filter(c=>c.checked).map(c=>c.value); }
  function selectedGlobal(){ return selected(); }
    function updateBar(){
      const count = selectedGlobal().length;
  if(count>0){
        bulkBar.classList.remove('d-none');
        document.body.classList.add('has-bulk');
      } else {
        bulkBar.classList.add('d-none');
        document.body.classList.remove('has-bulk');
        // Preserve final result message like 'Готово: ...' so user can read outcome
        if(!/^Готово/.test(statusEl.textContent)) statusEl.textContent='';
      }
      selCountEl.textContent = count;
      selSummary.innerHTML = 'Вибрано <strong>'+count+'</strong> із <strong>'+totalAll+'</strong> (сторінка '+pageNum+')';
      if(actionSel.value==='revoke'){
        reasonInput.classList.remove('hidden-slot');
      } else {
        reasonInput.classList.add('hidden-slot');
      }
    }
    applyPersistedToPage();
    updateBar();
  if(chkAll){ chkAll.addEventListener('change',()=>{ rowCheckboxes().forEach(c=>{ c.checked = chkAll.checked; c.closest('tr')?.classList.toggle('row-selected', c.checked); }); updateBar(); }); }
  rowCheckboxes().forEach(c=> c.addEventListener('change', ()=>{ c.closest('tr')?.classList.toggle('row-selected', c.checked); updateBar(); }));
  // selectAllFiltered removed (feature dropped)
    actionSel.addEventListener('change', updateBar);
  cancelBtn.addEventListener('click', ()=>{ rowCheckboxes().forEach(c=>{ c.checked=false; c.closest('tr')?.classList.remove('row-selected'); }); if(chkAll) chkAll.checked=false; updateBar(); });
  async function runBulk(){
      const cids = selected();
      if(!cids.length) return;
      if(cids.length>MAX){ alert('Максимум '+MAX+' за раз.'); return; }
      const action = actionSel.value;
      if(!action){ alert('Оберіть дію'); return; }
      if(action==='revoke'){
        const rv = reasonInput.value.trim();
        if(rv.length<5){ alert('Причина >=5 символів'); return; }
      }
      if(action==='delete'){
        if(!confirm('Видалити '+cids.length+'? Дія незворотна.')) return;
      } else if(action==='revoke'){
        if(!confirm('Відкликати '+cids.length+'?')) return;
      }
      statusEl.textContent='Виконується...'; executeBtn.disabled=true; cancelBtn.disabled=true; actionSel.disabled=true; reasonInput.disabled=true; if(chkAll) chkAll.disabled=true; rowCheckboxes().forEach(c=>c.disabled=true);
      try {
        const payload = {action:action, cids:cids};
        if(action==='revoke') payload.reason = reasonInput.value.trim();
        const csrf = bulkForm.querySelector('input[name="_csrf"]').value;
        const res = await fetch('/api/bulk_action.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf,'Accept':'application/json'}, body: JSON.stringify(payload)});
        if(!res.ok){
          if(res.status===403){ statusEl.textContent='CSRF помилка. Оновіть сторінку.'; return; }
          statusEl.textContent='Помилка '+res.status; return;
        }
        const js = await res.json();
        if(!js.ok){
          if(js.error==='csrf'){ statusEl.textContent='CSRF помилка. Оновіть сторінку.'; return; }
          statusEl.textContent='Помилка'; return; }
        statusEl.textContent='Готово: '+js.processed+' успішно, пропущено '+js.skipped+', помилок '+js.errors.length;
        js.results.forEach(r=>{
          const tr = document.querySelector('tr[data-cid="'+r.cid+'"]');
          if(!tr) return;
          const statusCell = tr.querySelector('td:nth-child(8)'); // 8th column = Статус
          if(r.revoked_at){
            tr.classList.add('row-revoked');
            if(statusCell) statusCell.innerHTML='<span class="badge badge-danger">Відкликано</span>';
          } else if(r.unrevoked){
            tr.classList.remove('row-revoked');
            if(statusCell) statusCell.innerHTML='<span class="badge badge-success">Активний</span>';
          } else if(r.deleted){
            tr.parentNode.removeChild(tr);
          }
        });
        // Remove processed from persisted selection
  // Uncheck processed on page
  rowCheckboxes().forEach(c=>{ if(cids.includes(c.value)){ c.checked=false; c.closest('tr')?.classList.remove('row-selected'); } }); updateBar();
      } catch(e){ statusEl.textContent='Внутрішня помилка'; }
      finally {
        executeBtn.disabled=false; cancelBtn.disabled=false; actionSel.disabled=false; reasonInput.disabled=false; if(chkAll) chkAll.disabled=false; rowCheckboxes().forEach(c=>c.disabled=false);
      }
    }
    executeBtn.addEventListener('click', runBulk);
  }
  // Sorting (client-side simple for current page)
  const table = document.querySelector('.table');
  if(table){
    const tbody = table.querySelector('tbody');
    table.querySelectorAll('th a.sort').forEach(a=>{
      a.addEventListener('click', e=>{
        e.preventDefault();
        const key = a.dataset.sort;
        const current = a.classList.contains('active') ? (a.classList.contains('asc') ? 'asc':'desc'):null;
        table.querySelectorAll('th a.sort').forEach(x=>x.classList.remove('active','asc','desc'));
        let nextDir = 'asc';
        if(current==='asc') nextDir='desc'; else if(current==='desc') nextDir='asc';
        a.classList.add('active', nextDir);
        const rows = Array.from(tbody.querySelectorAll('tr'));
        rows.sort((r1,r2)=>{
          function val(r){
            if(key==='cid') return r.getAttribute('data-cid');
            if(key==='created') return r.getAttribute('data-created');
            if(key==='status') return r.getAttribute('data-status');
            if(key==='version') return r.children[2].textContent.trim();
            if(key==='course') return r.children[3].textContent.trim();
            if(key==='grade') return r.children[4].textContent.trim();
            if(key==='issued') return r.children[5].textContent.trim();
            return '';
          }
          const v1 = val(r1); const v2 = val(r2);
          if(v1 < v2) return nextDir==='asc' ? -1:1;
          if(v1 > v2) return nextDir==='asc' ? 1:-1;
          return 0;
        });
        rows.forEach(r=>tbody.appendChild(r));
      });
    });
  }
})();
