// Unified topbar button width logic (persistent)
function unifyTopbarButtonsPersistent() {
  const container = document.querySelector('.topbar__actions');
  if (!container) return;
  const buttons = Array.from(container.querySelectorAll('.btn'));
  // reset inline widths so measurement is accurate
  buttons.forEach(b => b.style.width = '');

    if (buttons.length >= 1) {
    const widths = buttons.map(b => b.getBoundingClientRect().width);
    const max = Math.max(...widths);
    // If stored value exists and is larger, keep it; otherwise update stored value
    try {
      const stored = parseInt(localStorage.getItem('topbarBtnWidth'), 10) || null;
      if (!stored || Math.ceil(max) > stored) {
        localStorage.setItem('topbarBtnWidth', String(Math.ceil(max)));
        document.documentElement.style.setProperty('--topbar-btn-width', Math.ceil(max) + 'px');
      } else {
        // use stored if it's larger to avoid shrinking
        document.documentElement.style.setProperty('--topbar-btn-width', stored + 'px');
      }
    } catch (e) {
      document.documentElement.style.setProperty('--topbar-btn-width', Math.ceil(max) + 'px');
    }
  }
}

function scheduleUnify(){
  clearTimeout(window.__unifyBtnsTimer);
  window.__unifyBtnsTimer = setTimeout(unifyTopbarButtonsPersistent, 120);
}
window.addEventListener('DOMContentLoaded', unifyTopbarButtonsPersistent);
window.addEventListener('resize', scheduleUnify);

// Watch for changes in header (login -> logout) and reapply
document.addEventListener('DOMContentLoaded', () => {
  const container = document.querySelector('.topbar__actions');
  if (!container) return;
  const mo = new MutationObserver(() => {
    // small timeout to let DOM settle
  setTimeout(unifyTopbarButtonsPersistent, 50);
  });
  mo.observe(container, { childList: true, subtree: true, attributes: true });
});

// Localize browser "required" validation message to Ukrainian
document.addEventListener('DOMContentLoaded', () => {
  const msg = 'Будь ласка, заповніть це поле';
  function setup(el) {
    if (!el) return;
    el.addEventListener('invalid', (e) => {
      e.target.setCustomValidity(msg);
    });
    el.addEventListener('input', (e) => {
      e.target.setCustomValidity('');
    });
  }
  // Apply to all current required inputs
  document.querySelectorAll('input[required], textarea[required], select[required]').forEach(setup);
  // Observe DOM for dynamically added fields
  const mo = new MutationObserver(muts => {
    muts.forEach(m => {
      if (m.addedNodes) {
        m.addedNodes.forEach(n => {
          if (n.querySelectorAll) {
            n.querySelectorAll('input[required], textarea[required], select[required]').forEach(setup);
          }
        });
      }
    });
  });
  mo.observe(document.body, { childList: true, subtree: true });
});

// Fallback for older browsers: if form.reportValidity is not available,
// perform a manual required-field check on submit and show a Ukrainian alert.
document.addEventListener('submit', function (e) {
  const form = e.target;
  if (!(form && form.tagName === 'FORM')) return;
  // If browser supports reportValidity, let native validation handle it
  if (typeof form.reportValidity === 'function') return;

  const required = Array.from(form.querySelectorAll('input[required], textarea[required], select[required]'));
  for (const el of required) {
    // treat checkboxes/radios differently
    let empty = false;
    if (el.type === 'checkbox' || el.type === 'radio') {
      // find any checked with same name
      const name = el.name;
      if (name) {
        const anyChecked = form.querySelector('input[name="' + name + '"]:checked');
        empty = !anyChecked;
      } else {
        empty = !el.checked;
      }
    } else {
      empty = (String(el.value || '').trim() === '');
    }
    if (empty) {
      e.preventDefault();
      try { el.focus(); } catch (ignore) {}
      alert('Будь ласка, заповніть поле.');
      return false;
    }
  }
});

