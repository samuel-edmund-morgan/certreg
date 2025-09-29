# Testing Backlog (Step 1 Roadmap)

## Highest Priority (Sprint 1)
1. **Backend API: Організації**
   - Create reusable PHP test bootstrap (session, CSRF, DB reset helper).
   - Implement tests for `api/org_create.php` (success, duplicate code, bad format).
   - Implement tests for `api/org_set_active.php` (toggle, invalid ID).
   - Implement tests for `api/org_delete.php` (success, blocked by operators/tokens).
2. **Playwright: Налаштування організацій**
   - Extend `org_switch.spec.js` або створити нові spec із CRUD (create/update/toggle/delete, валідація полів).
   - Додати перевірку брендування (color inputs, reset state).
3. **Coverage Instrumentation**
   - Налаштувати локальний запуск PHP з `pcov`/`Xdebug` та збереження `coverage/` артефактів.
   - Додати збір Playwright trace + coverage (`use: { trace: 'retain-on-failure', coverageName: 'ui' }`).

## Medium Priority (Sprint 2)
- PHP: `api/operator_*`, `api/template_*`, `api/branding_save.php`, `account_change_password.php`.
- Playwright: CRUD операторів, шаблонів, журнал подій, перевірка rate-limit попереджень.
- CLI: smoke-тести `scripts/security/check_headers.sh`, `scripts/maintenance/*`.
- Performance assertions: стабілізувати `bulk_performance`, додати пороги часу.

## Supporting Tasks
- Автоматизований скидання БД між тестами (транзакції або `tests/reset_db.php`).
- Винести тестові дані (організації, шаблони) у `tests/fixtures/`.
- Підготувати GitHub Actions job для coverage badge/звітів.
