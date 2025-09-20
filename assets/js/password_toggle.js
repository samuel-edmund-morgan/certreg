// Reusable password visibility toggle logic
(function(){
  function init(scope){
    scope.querySelectorAll('.pw-toggle').forEach(btn=>{
      if(btn.__bound) return; btn.__bound = true;
      btn.addEventListener('click', ()=>{
        const targetName = btn.getAttribute('data-target');
        if(!targetName) return;
        // Find nearest form first
        let form = btn.closest('form');
        let input = form ? form.querySelector('input[name="'+CSS.escape(targetName)+'"]') : document.querySelector('input[name="'+CSS.escape(targetName)+'"]');
        if(!input) return;
        const isPwd = input.getAttribute('type') === 'password';
        input.setAttribute('type', isPwd ? 'text' : 'password');
        btn.setAttribute('aria-pressed', isPwd ? 'true' : 'false');
        btn.textContent = isPwd ? '🙈' : '👁';
      });
    });
  }
  document.addEventListener('DOMContentLoaded', ()=>init(document));
  document.addEventListener('settings:section-loaded', e=>{
    const container = document.getElementById('settingsContent');
    if(container) init(container);
  });
})();
