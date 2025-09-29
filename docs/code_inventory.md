# Code Inventory

Цей документ узагальнює ключові файли репозиторію `certreg`, щоб пришвидшити навігацію перед подальшим нарощуванням тестового покриття.

## PHP (core логіка)

| Файл/директорія | Призначення |
| --- | --- |
| `index.php` | Головна сторінка, перенаправляє до панелі в залежності від ролі. |
| `login.php`, `logout.php`, `auth.php` | Аутентифікація: форми входу, перевірка сесії, вихід. |
| `admin.php`, `operator.php`, `settings.php`, `settings_section.php`, `organization.php`, `events.php` | Основні розділи адмін-панелі. Використовують спільні хедер/футер та JS‑модулі. |
| `issue_token.php`, `token.php`, `tokens.php` | Сторінки для одиночної та масової видачі нагород, перегляд токенів. |
| `verify.php`, `checkCert.php` | Публічне підтвердження нагороди, віддача сертифіката за CID. |
| `api/` | REST‑ендпоїнти (див. нижче). |
| `helpers.php`, `db.php`, `common_pagination.php`, `rate_limit.php` | Утиліти: робота з БД, пагінація, rate-limit. |
| `self_check.php` | Самодіагностика інсталяції (health-check). |
| `scripts/` | CLI‑скрипти (міграції, seed, службові утиліти). |

### API ендпоїнти (`api/`)

* `register.php`, `revoke.php`, `unrevoke.php`, `status.php` — операції над сертифікатами/токенами.
* `bulk_action.php` — масові дії над токенами.
* `template_*.php`, `templates_list.php` — CRUD для шаблонів.
* `org_*.php`, `operators_list.php`, `operator_*.php` — управління організаціями та операторами.
* `branding_save.php`, `events.php`, `delete_token.php` — налаштування брендингу, лог подій, видалення токена.
* `account_change_password.php` — зміна пароля облікового запису.

## JavaScript (frontend логіка)

Усі клієнтські модулі лежать у `assets/js/`.

| Файл | Призначення |
| --- | --- |
| `issue.js` | Одиночна видача: генерація CID/HMAC, рендер PDF/JPG, робота з QR, масштабування PDF. |
| `issue_bulk.js` | Масова видача: управління таблицею одержувачів, паралельна реєстрація, batch PDF, CSV. |
| `issue_templates.js` | Підвантаження та застосування шаблонів (фон, координати, титули) для single/bulk. |
| `issue_page.js`, `issue_org_badge.js` | Спільні хелпери сторінки видачі (перемикач вкладок, бейдж організації). |
| `verify.js` | Скрипт сторінки перевірки: валідація вводу, локалізовані повідомлення, QR дані. |
| `admin.js` | Поведінка панелі адміністрування (таблиці, модальні дії). |
| `assets/js/lib/phpqrcode.php` | PHP бібліотека для генерації QR (серверна, лежить у `lib/`). |

## Стилі

* `assets/css/styles.css` — головний стилевий файл (layout, таблиці, стан кнопок, адаптивні utility‑класи).
* `assets/css` може містити додаткові файли (шрифти в `fonts/`).

## Тестова інфраструктура

| Директорія | Призначення |
| --- | --- |
| `tests/` | PHP‑скрипти для інтеграційних тестів API/CLI. Включає `run_tests.php` як точку входу. |
| `tests/ui/` | Playwright‑тести. Покривають видачу (single/bulk), безпеку, PDF‑валідність, поведінку verify. |
| `playwright.config.js`, `tests/ui/globalSetup.js` | Конфігурація Playwright, bootstrap середовища. |
| `test-results/` | Каталог для артефактів E2E (screenshots, traces). |

## Build & CI

| Файл | Призначення |
| --- | --- |
| `package.json`, `package-lock.json` | npm залежності (Playwright, утиліти перевірок). |
| `.github/workflows/` | CI сценарії (збірка, тести, деплой — потрібно актуалізувати для покриття). |
| `MIGRATION.md`, `SECURITY.md` | Процедури оновлення БД та політика безпеки. |

## Подальші кроки для тестового покриття

1. Розширити існуючі PHP‑тести (`tests/`) сценаріями для `api/register.php`, `api/status.php`, verify‑флоу.
2. Додати Playwright‑тести для крайових кейсів (org-switch, revoke/unrevoke, налаштування операторів).
3. Підключити звіти покриття (Xdebug/pcov для PHP, `playwright show-trace`/coverage API для E2E).
4. Оновити GitHub Actions, щоб запускати обидва набори тестів і зберігати артефакти.
