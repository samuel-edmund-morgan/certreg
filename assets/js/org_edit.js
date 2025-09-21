(()=>{
  const form = document.getElementById('orgUpdateForm');
  if(!form) return;
  const statusEl = document.getElementById('orgUpdateStatus');
  const btn = document.getElementById('orgUpdateBtn');
  form.addEventListener('submit', async (e)=>{
    e.preventDefault();
    statusEl.textContent='Збереження...'; btn.disabled=true;
    try {
      const fd = new FormData(form);
      const r = await fetch(form.action,{method:'POST',credentials:'same-origin',body:fd});
      const j = await r.json().catch(()=>({}));
      if(j.ok){
        statusEl.textContent='Збережено';
        // Update color inputs in case normalization (#ABCDEF) applied
        if(j.org){
          ['primary_color','accent_color','secondary_color'].forEach(k=>{ if(j.org[k]){ const inp=form.querySelector('[name="'+k+'"]'); if(inp) inp.value=j.org[k]; }});
        }
      } else if(j.errors){
        statusEl.textContent='Помилки: '+Object.keys(j.errors).join(', ');
      } else {
        statusEl.textContent='Помилка';
      }
    } catch(err){
      statusEl.textContent='Мережева помилка';
    } finally {
      btn.disabled=false;
      setTimeout(()=>{ if(statusEl.textContent==='Збережено') statusEl.textContent=''; },3000);
    }
  });
  // Color picker synchronization (reuse logic similar to branding page)
  document.querySelectorAll('.color-hex[data-color-peer]').forEach(inp=>{
    const peerId = inp.getAttribute('data-color-peer');
    const picker = document.getElementById(peerId);
    if(!picker) return;
    function norm(v){ v=v.trim(); if(!v) return ''; if(v[0]!=='#') v='#'+v; return v.length===7?v:''; }
    inp.addEventListener('input',()=>{ const v=norm(inp.value); if(v) picker.value=v; });
    picker.addEventListener('input',()=>{ inp.value=picker.value; });
  });
})();
