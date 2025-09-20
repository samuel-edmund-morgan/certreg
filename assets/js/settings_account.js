// Client-side handling for account password change form
(function(){
  function $(sel,ctx=document){ return ctx.querySelector(sel); }
  function score(pw){
    if(!pw) return 0;
    let s = 0;
    if(pw.length >= 8) s++;
    if(pw.length >= 12) s++;
    if(/[A-Z]/.test(pw)) s++;
    if(/[a-z]/.test(pw)) s++;
    if(/\d/.test(pw)) s++;
    if(/[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(pw)) s++;
    return s; // 0..6
  }
  function strengthLabel(s){
    if(s<=1) return 'дуже слабкий';
    if(s===2) return 'слабкий';
    if(s===3) return 'посередній';
    if(s===4) return 'добрий';
    if(s>=5) return 'надійний';
  }
  function bind(){
    const form = $('#accountPasswordForm');
    if(!form || form.__pwBound) return; form.__pwBound = true;
    const new1 = form.querySelector('input[name=new_password]');
    const new2 = form.querySelector('input[name=new_password2]');
    const status = $('#accountPwdStatus');
  let meter = document.createElement('div');
  meter.className = 'pw-strength fs-12 text-muted';
  // Insert after the pw-field wrapper (parent is label > div.pw-field)
  const pwFieldWrapper = new1.parentElement; // .pw-field
  pwFieldWrapper.insertAdjacentElement('afterend', meter);
    new1.addEventListener('input', ()=>{
      const sc = score(new1.value);
  meter.textContent = 'Сила: '+strengthLabel(sc);
    });
    form.addEventListener('submit', (e)=>{
      e.preventDefault();
      status.textContent = 'Надсилаємо...';
      const fd = new FormData(form);
      if(new1.value !== new2.value){
        status.textContent = 'Паролі не співпадають';
        return;
      }
      fetch(form.action, {
        method:'POST',
        body: fd,
        credentials:'same-origin'
      }).then(r=>r.json())
        .then(j=>{
          if(!j.ok){
            if(j.errors){
              if(j.errors.old_password==='invalid') status.textContent='Невірний поточний пароль';
              else if(j.errors.new_password==='too_short') status.textContent='Новий пароль занадто короткий';
              else if(j.errors.new_password==='weak') status.textContent='Пароль слабкий (потрібно літера та цифра)';
              else if(j.errors.new_password2==='mismatch') status.textContent='Паролі не співпадають';
              else status.textContent='Помилка валідації';
            } else {
              status.textContent='Помилка';
            }
            return;
          }
          status.textContent = 'Пароль оновлено';
          form.reset();
        }).catch(()=>{ status.textContent='Мережева помилка'; });
    });
  }
  document.addEventListener('DOMContentLoaded', bind);
  document.addEventListener('settings:section-loaded', (e)=>{
    if(e.detail && e.detail.tab === 'account') bind();
  });
})();
