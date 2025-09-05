document.addEventListener('submit', (e) => {
  const form = e.target;
  if (form.classList && form.classList.contains('js-delete-form')) {
    if (!confirm("Видалити цей запис? Дію не можна скасувати.")) {
      e.preventDefault();
    }
  }
});

