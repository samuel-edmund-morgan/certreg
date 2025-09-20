// AJAX navigation for settings sections with History API
(function(){
  const cache = {}; // simple in-memory cache of loaded sections
  function qs(sel, ctx=document){ return ctx.querySelector(sel); }
  function qsa(sel, ctx=document){ return Array.prototype.slice.call(ctx.querySelectorAll(sel)); }

  function setActive(tab){
    qsa('.settings-tabs .tab').forEach(btn=>{
      const is = btn.getAttribute('data-tab') === tab;
      btn.classList.toggle('active', is);
      btn.setAttribute('aria-selected', is ? 'true' : 'false');
    });
  }

  function loadSection(tab, push){
    const container = qs('#settingsContent');
    if(!container) return;
    if(cache[tab]){
      container.innerHTML = cache[tab];
      setActive(tab);
      if(push){ history.pushState({tab}, '', '/settings.php?tab='+encodeURIComponent(tab)); }
      document.dispatchEvent(new CustomEvent('settings:section-loaded', {detail:{tab}}));
      return;
    }
    container.setAttribute('aria-busy','true');
    container.innerHTML = '<p class="fs-14 text-muted">Завантаження…</p>';
    fetch('/settings_section.php?tab='+encodeURIComponent(tab), {credentials:'same-origin'})
      .then(r=>{ if(!r.ok) throw new Error('HTTP '+r.status); return r.text(); })
      .then(html=>{
        cache[tab] = html;
        container.innerHTML = html;
        container.removeAttribute('aria-busy');
        setActive(tab);
        if(push){ history.pushState({tab}, '', '/settings.php?tab='+encodeURIComponent(tab)); }
        document.dispatchEvent(new CustomEvent('settings:section-loaded', {detail:{tab}}));
      })
      .catch(err=>{
        console.error('Load section failed', err);
        // Fallback to full reload
        window.location.href = '/settings.php?tab='+encodeURIComponent(tab);
      });
  }

  function onTabClick(e){
    e.preventDefault();
    const btn = e.currentTarget;
    const tab = btn.getAttribute('data-tab');
    if(btn.classList.contains('active')) return;
    loadSection(tab, true);
  }

  window.addEventListener('popstate', (e)=>{
    const tab = (e.state && e.state.tab) || new URL(location.href).searchParams.get('tab') || 'branding';
    loadSection(tab, false);
  });

  document.addEventListener('DOMContentLoaded', ()=>{
    // Attach handlers
    qsa('.settings-tabs .tab').forEach(btn=> btn.addEventListener('click', onTabClick));
    // Prime cache with initially rendered tab
    const current = new URL(location.href).searchParams.get('tab') || 'branding';
    const container = qs('#settingsContent');
    if(container){ cache[current] = container.innerHTML; }
    history.replaceState({tab: current}, '', '/settings.php?tab='+encodeURIComponent(current));
  });
})();
