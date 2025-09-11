// Issue page bootstrap: reads coords from <body data-coords>
(function(){
  try {
    var body = document.body;
    if(body && body.dataset && body.dataset.coords){
      window.__CERT_COORDS = JSON.parse(body.dataset.coords);
    }
    if(body && body.dataset){
      window.__ORG_CODE = body.dataset.org;
      window.__INFINITE_SENTINEL = body.dataset.inf;
      var flag = body.dataset.test === '1';
      try { if(!flag && typeof navigator !== 'undefined' && navigator.webdriver) flag = true; } catch(_e){}
      window.__TEST_MODE = !!flag;
    }
  } catch(e){ /* ignore */ }
})();
// Main logic separated (import existing issue.js logic)
// Assuming issue.js contains full implementation relying on window.__CERT_COORDS
