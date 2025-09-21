// Динамічне завантаження шаблонів для сторінки видачі
// Мінімальний скелет: підтягуємо список і будуємо <select>
(function(){
  const sel = document.getElementById('templateSelect');
  if(!sel) return; // якщо UI ще не додано
  fetch('/api/templates_list.php', {credentials:'same-origin'})
    .then(r=> r.ok ? r.json() : Promise.reject())
    .then(js => {
      if(!js || !js.ok){ sel.disabled=true; return; }
      sel.innerHTML='';
      if(!js.items.length){
        sel.disabled=true;
        const opt=document.createElement('option');
        opt.textContent='Немає шаблонів';
        opt.value='';
        sel.appendChild(opt);
        return;
      }
      const defOpt=document.createElement('option');
      defOpt.textContent='(Стандартний)';
      defOpt.value='';
      sel.appendChild(defOpt);
      js.items.forEach(it=>{
        const o=document.createElement('option');
        o.value=it.id;
        o.textContent = it.name + (it.org_code?(' ['+it.org_code+']'):'');
        sel.appendChild(o);
      });
      sel.disabled=false;
    }).catch(()=>{ try{ sel.disabled=true; }catch(_e){} });

  sel.addEventListener('change', ()=>{
    // Поки що тільки переключає background preview, якщо у майбутньому будуть збережені координати
    const id = sel.value;
    // очікувана майбутня схема: /files/templates/<id>/<filename>
    if(!id){
      // revert до дефолту
      const body = document.body;
      const tpl = body ? body.getAttribute('data-template') : '/files/cert_template.jpg';
      window.__ISSUE_TEMPLATE_OVERRIDE = null;
      // issue.js вже завантажив фон при старті; щоб перезавантажити, можна оновити сторінку або додати API до issue.js
      // Для простоти: emit кастомну подію
      document.dispatchEvent(new CustomEvent('cert-template-change', {detail:{path: tpl}}));
      return;
    }
    // Тимчасовий шлях (placeholder) — справжня логіка завантаження буде коли зʼявиться API завантаження шаблонів
    const guessed = '/files/templates/'+id+'/cert_template.jpg';
    window.__ISSUE_TEMPLATE_OVERRIDE = guessed;
    document.dispatchEvent(new CustomEvent('cert-template-change', {detail:{path: guessed}}));
  });
})();
