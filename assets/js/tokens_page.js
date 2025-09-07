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
})();
