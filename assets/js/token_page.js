(function(){
  ['revokeForm','unrevokeForm','deleteForm'].forEach(id=>{
    const f=document.getElementById(id); if(!f) return;
    f.addEventListener('submit', async e=>{
      e.preventDefault();
      if(id==='deleteForm'){
        if(!confirm('Видалити токен без можливості відновлення?')) return;
      }
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
  const res=await fetch(f.action,{method:'POST',body:fd,credentials:'same-origin'});
        if(!res.ok){ btn && (btn.disabled=false); alert('Помилка запиту'); return; }
        const js=await res.json();
        if(js.ok){
          if(id==='deleteForm'){
            window.location.href = '/tokens.php';
          } else {
            location.reload();
          }
        } else {
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
  // Copy buttons
  function flashStatus(){
    const st=document.getElementById('copyStatus'); if(!st) return; st.style.display='inline';
    clearTimeout(window.__copyStatusT);
    window.__copyStatusT=setTimeout(()=>{st.style.display='none';},1800);
  }
  function copyText(text){
    if(navigator.clipboard && navigator.clipboard.writeText){
      navigator.clipboard.writeText(text).then(flashStatus).catch(()=>fallback());
    } else fallback();
    function fallback(){
      const ta=document.createElement('textarea'); ta.value=text; ta.style.position='fixed'; ta.style.opacity='0'; document.body.appendChild(ta); ta.select(); try{document.execCommand('copy');}catch(e){} document.body.removeChild(ta); flashStatus();
    }
  }
  const cidBtn=document.getElementById('copyCidBtn');
  if(cidBtn){ cidBtn.addEventListener('click',()=>{ const cid=cidBtn.previousElementSibling?.textContent || cidBtn.parentElement.querySelector('span[style*="monospace"]')?.textContent; if(cid) copyText(cid.trim()); }); }
  const intBtn=document.getElementById('copyIntBtn');
  if(intBtn){ intBtn.addEventListener('click',()=>{ const intCode=document.getElementById('intCode'); if(intCode) copyText(intCode.textContent.trim()); }); }
})();
