// Branding settings form logic (CSP-compliant external script)
(function(){
  const f = document.getElementById('brandingForm');
  if(!f) return;
  const statusEl = document.getElementById('brandingStatus');
  const btn = document.getElementById('brandingSaveBtn');
  f.addEventListener('submit', async (e)=>{
    e.preventDefault();
    if(statusEl) statusEl.textContent='Збереження...';
    if(btn) btn.disabled=true;
    try {
      const fd = new FormData(f);
      const res = await fetch(f.action,{method:'POST',body:fd,credentials:'same-origin'});
      let js={};
      try { js = await res.json(); } catch(_){ js={ok:false,error:'parse'}; }
      if(!res.ok || !js.ok){
        if(statusEl) statusEl.textContent='Помилка';
      } else {
        if(statusEl) statusEl.textContent='Збережено';
        // Reload after short delay if any asset changed; always reload to update multiline rendering
        setTimeout(()=>location.reload(),600);
      }
    } catch(err){
      if(statusEl) statusEl.textContent='Мережева помилка';
    } finally {
      if(btn) btn.disabled=false;
    }
  });
})();
