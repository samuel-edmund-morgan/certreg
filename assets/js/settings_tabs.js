// Make settings tabs behave like issuance tabs (no full reload on click unless needed)
(function(){
  function onClick(e){
    const btn = e.currentTarget;
    const url = btn.getAttribute('data-url');
    if(!url) return;
    // Navigate (server renders correct panel); could be swapped to AJAX in future
    window.location.href = url;
  }
  document.addEventListener('DOMContentLoaded', ()=>{
    document.querySelectorAll('.settings-tabs .tab').forEach(tab=>{
      tab.addEventListener('click', onClick);
    });
  });
})();
