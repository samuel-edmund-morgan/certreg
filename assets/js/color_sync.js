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
    const peerId = hexInput.getAttribute('data-color-peer');
    if(!peerId) return;
    const picker = document.getElementById(peerId);
    if(!picker) return;
    // Init picker from hex (fallback if invalid)
    const init = normHex(hexInput.value) || picker.value || '#000000';
    picker.value = init;
    // When hex input changes, update picker if valid; auto-prepend '#'
    hexInput.addEventListener('input', ()=>{
      let raw = hexInput.value.trim();
      if(/^([0-9a-fA-F]{6})$/.test(raw)){
        raw = '#'+raw;
        hexInput.value = raw.toLowerCase();
      }
      const n = normHex(hexInput.value);
      if(n){ picker.value = n; }
    });
    // When picker changes, update hex input
    picker.addEventListener('input', ()=>{
      const v = picker.value;
      if(/^#[0-9a-fA-F]{6}$/.test(v)){
        hexInput.value = v;
      }
    });
  }
  document.addEventListener('DOMContentLoaded', ()=>{
    document.querySelectorAll('input.color-hex[data-color-peer]').forEach(attach);
  });
})();
