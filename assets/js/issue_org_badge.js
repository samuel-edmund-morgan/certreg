// Ініціалізація бейджа організації на сторінці видачі
(function(){
  if(typeof document==='undefined') return;
  var bCode = document.getElementById('orgBadgeCode');
  var bName = document.getElementById('orgBadgeName');
  var body = document.body;
  if(!body) return;
  var code = body.getAttribute('data-org') || '';
  var name = body.getAttribute('data-orgname') || '';
  if(bCode){ bCode.textContent = code || '—'; }
  if(bName){ bName.textContent = name ? (' — '+name) : ''; }
})();
