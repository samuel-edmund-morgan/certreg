document.addEventListener('submit', (e) => {
  const form = e.target;
  if (form.classList && form.classList.contains('js-delete-form')) {
    if (!confirm("Видалити цей запис? Дію не можна скасувати.")) {
      e.preventDefault();
    }
  }
});

// Make topbar action buttons equal width (use widest width)
function unifyTopbarButtons() {
  const container = document.querySelector('.topbar__actions');
  if (!container) return;
  const buttons = Array.from(container.querySelectorAll('.btn'));
  if (buttons.length < 2) return;
  // reset widths first
  buttons.forEach(b => b.style.width = '');
  const widths = buttons.map(b => b.getBoundingClientRect().width);
  const max = Math.max(...widths);
  buttons.forEach(b => b.style.width = Math.ceil(max) + 'px');
}

window.addEventListener('DOMContentLoaded', unifyTopbarButtons);
window.addEventListener('resize', () => {
  // debounce
  clearTimeout(window.__unifyBtnsTimer);
  window.__unifyBtnsTimer = setTimeout(unifyTopbarButtons, 120);
});

// Improved: persist computed width and handle cases when only one button is present
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

window.addEventListener('DOMContentLoaded', unifyTopbarButtonsPersistent);
window.addEventListener('resize', () => {
  clearTimeout(window.__unifyBtnsTimer);
  window.__unifyBtnsTimer = setTimeout(unifyTopbarButtonsPersistent, 120);
});

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

