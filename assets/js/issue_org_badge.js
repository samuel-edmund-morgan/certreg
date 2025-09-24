// Ініціалізація бейджа організації на сторінці видачі
(function(){
  if(typeof document==='undefined') return;
  var bCode = document.getElementById('orgBadgeCode');
  var bName = document.getElementById('orgBadgeName');
  var body = document.body;
  if(!body) return;
  var code = body.getAttribute('data-org') || '';
  var name = body.getAttribute('data-orgname') || '';
  // Normalize any newlines to spaces for display
  if(name && typeof name === 'string'){
    // Replace actual newlines and literal escape sequences ("\n", "\r") with spaces
    name = name
      .replace(/(?:\r\n|\r|\n)+/g,' ')
      .replace(/\\n|\\r/g,' ')
      .replace(/\s{2,}/g,' ')
      .trim();
  }
  if(bCode){ bCode.textContent = code || '—'; }
  if(bName){ bName.textContent = name ? (' — '+name) : ''; }
})();
