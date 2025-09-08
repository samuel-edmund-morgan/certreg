(function(){
  function bindAjax(formSelector, confirmText){
    document.querySelectorAll(formSelector).forEach(f=>{
      f.addEventListener('submit', async e=>{
        e.preventDefault();
        if(confirmText && !confirm(confirmText)) return;
        const fd = new FormData(f);
        const res = await fetch(f.action,{method:'POST',body:fd});
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
    const chkAll = document.getElementById('chkAll');
    const bulkBar = document.getElementById('bulkBar');
    const executeBtn = document.getElementById('bulkExecute');
    const cancelBtn = document.getElementById('bulkCancel');
    const selCountEl = document.getElementById('selCount');
    const actionSel = document.getElementById('bulkAction');
    const reasonInput = document.getElementById('bulkReason');
    const statusEl = document.getElementById('bulkStatus');
    const MAX = 100;
    function rowCheckboxes(){ return Array.from(document.querySelectorAll('.rowChk')); }
    function selected(){ return rowCheckboxes().filter(c=>c.checked).map(c=>c.value); }
    function updateBar(){
      const count = selected().length;
      if(count>0){ bulkBar.classList.remove('d-none'); } else { bulkBar.classList.add('d-none'); statusEl.textContent=''; }
      selCountEl.textContent = count;
      if(actionSel.value==='revoke'){ reasonInput.style.display='inline-block'; } else { reasonInput.style.display='none'; }
    }
    if(chkAll){ chkAll.addEventListener('change',()=>{ rowCheckboxes().forEach(c=>{ c.checked = chkAll.checked; }); updateBar(); }); }
    rowCheckboxes().forEach(c=> c.addEventListener('change', updateBar));
    actionSel.addEventListener('change', updateBar);
    cancelBtn.addEventListener('click', ()=>{ rowCheckboxes().forEach(c=>c.checked=false); if(chkAll) chkAll.checked=false; updateBar(); });
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
        const res = await fetch('/api/bulk_action.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf}, body: JSON.stringify(payload)});
        if(!res.ok){ statusEl.textContent='Помилка '+res.status; return; }
        const js = await res.json();
        if(!js.ok){ statusEl.textContent='Помилка'; return; }
        statusEl.textContent='Готово: '+js.processed+' успішно, пропущено '+js.skipped+', помилок '+js.errors.length;
        js.results.forEach(r=>{
          const tr = document.querySelector('tr[data-cid="'+r.cid+'"]');
          if(!tr) return;
          if(r.revoked_at){
            tr.classList.add('row-revoked');
            const badgeCell = tr.querySelector('td:nth-child(7)');
            if(badgeCell) badgeCell.innerHTML='<span class="badge badge-danger">Відкликано</span>';
          } else if(r.unrevoked){
            tr.classList.remove('row-revoked');
            const badgeCell = tr.querySelector('td:nth-child(7)');
            if(badgeCell) badgeCell.innerHTML='<span class="badge badge-success">Активний</span>';
          } else if(r.deleted){
            tr.parentNode.removeChild(tr);
          }
        });
      } catch(e){ statusEl.textContent='Внутрішня помилка'; }
      finally {
        executeBtn.disabled=false; cancelBtn.disabled=false; actionSel.disabled=false; reasonInput.disabled=false; if(chkAll) chkAll.disabled=false; rowCheckboxes().forEach(c=>c.disabled=false);
        rowCheckboxes().forEach(c=>c.checked=false); if(chkAll) chkAll.checked=false; updateBar();
      }
    }
    executeBtn.addEventListener('click', runBulk);
  }
})();
