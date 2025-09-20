// (Removed) logic that forcibly unified topbar button widths. Buttons now size naturally.

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

