// Sync between hex text inputs and <input type="color"> pickers for branding settings
// CSP-safe: no inline handlers
(function(){
  function normHex(v){
    if(!v) return '';
    v = v.trim();
    if(v.startsWith('#')) v = v.slice(1);
    if(/^[0-9a-fA-F]{6}$/.test(v)) return '#'+v.toLowerCase();
    return '';
  }
  function attach(hexInput){
    if(hexInput.__colorSyncAttached) return; // idempotent
    hexInput.__colorSyncAttached = true;
    const peerId = hexInput.getAttribute('data-color-peer');
    if(!peerId) return;
    const picker = document.getElementById(peerId);
    if(!picker) return;
    const init = normHex(hexInput.value) || picker.value || '#000000';
    picker.value = init;
    hexInput.addEventListener('input', ()=>{
      let raw = hexInput.value.trim();
      if(/^([0-9a-fA-F]{6})$/.test(raw)){
        raw = '#'+raw;
        hexInput.value = raw.toLowerCase();
      }
      const n = normHex(hexInput.value);
      if(n){ picker.value = n; }
    });
    picker.addEventListener('input', ()=>{
      const v = picker.value;
      if(/^#[0-9a-fA-F]{6}$/.test(v)){
        hexInput.value = v;
      }
    });
  }
  function initScope(scope){
    scope.querySelectorAll('input.color-hex[data-color-peer]').forEach(attach);
  }
  document.addEventListener('DOMContentLoaded', ()=>{
    initScope(document);
  });
  // Re-init when settings section changes (AJAX loaded)
  document.addEventListener('settings:section-loaded', (e)=>{
    const container = document.getElementById('settingsContent');
    if(container){ initScope(container); }
  });
})();
