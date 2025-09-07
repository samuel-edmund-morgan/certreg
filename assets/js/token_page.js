(function(){
  ['revokeForm','unrevokeForm','deleteForm'].forEach(id=>{
    const f=document.getElementById(id); if(!f) return;
    f.addEventListener('submit', async e=>{
      e.preventDefault();
      if(id==='revokeForm'){
        const reasonInput = f.querySelector('input[name="reason"]');
        const valRaw = reasonInput.value;
        const norm = valRaw.trim().replace(/\s+/g,' ');
        if(norm.length === 0){ alert('Причина обовʼязкова'); return; }
        if(norm.length < 5){ alert('Мінімум 5 символів'); return; }
        if(!/[\p{L}\p{N}]/u.test(norm)){ alert('Потрібна хоча б одна літера або цифра'); return; }
        reasonInput.value = norm;
      }
      const btn = f.querySelector('button[type="submit"]');
      if(btn) btn.disabled = true;
      try {
        const fd=new FormData(f);
        const res=await fetch(f.action,{method:'POST',body:fd});
        if(!res.ok){ btn && (btn.disabled=false); alert('Помилка запиту'); return; }
        const js=await res.json();
        if(js.ok){ location.reload(); } else {
          btn && (btn.disabled=false);
          switch(js.error){
            case 'empty_reason': alert('Причина порожня'); break;
            case 'too_short': alert('Занадто коротко (мін 5)'); break;
            case 'bad_chars': alert('Немає літер або цифр'); break;
            default: alert(js.error||'Не вдалося');
          }
        }
      } catch(err){ btn && (btn.disabled=false); alert('Помилка мережі'); }
    });
  });
})();
