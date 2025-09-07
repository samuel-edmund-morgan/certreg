// Issue page bootstrap: reads coords from <body data-coords>
(function(){
  try {
    var body = document.body;
    if(body && body.dataset && body.dataset.coords){
      window.__CERT_COORDS = JSON.parse(body.dataset.coords);
    }
  } catch(e){ /* ignore */ }
})();
// Main logic separated (import existing issue.js logic)
// Assuming issue.js contains full implementation relying on window.__CERT_COORDS
