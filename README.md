# certreg (privacy-first) ![CI](https://github.com/samuel-edmund-morgan/certreg/actions/workflows/ci.yml/badge.svg)

Мінімалістична система перевірки сертифікатів без зберігання персональних даних. Імʼя нормалізується локально та не потрапляє в БД; сервер оперує лише анонімними токенами.

## Зміст
- [Установка](#установка)
- [API](#api)
- [Міграції](#міграції)
- [Безпека](#безпека)
- [Дорожня карта](#дорожня-карта)
- [Ліцензія](#ліцензія)
- [Автоматичні тести](#автоматичні-тести)
- [Ігноровані файли / артефакти](#ігноровані-файли--артефакти)
- [Налаштування (AJAX) та акаунт](#налаштування-ajax-та-акаунт)
 - [Оператори (винесено на окремі сторінки)](#оператори-внесено-на-окремі-сторінки)
 - [Організації (детальні сторінки)](#організації-детальні-сторінки)

## Установка

### Швидкий старт (dev, сумісний з CI)
Найпростіший спосіб розгорнути локально так само, як у CI:

1) Скопіюйте конфіг і заповніть параметри БД (`db_host`, `db_name`, `db_user`, `db_pass`).
2) Запустіть ідемпотентну міграцію, яка створить/оновить схему (organizations, templates, tokens.template_id, індекси тощо).
3) Створіть тестового адміністратора.
4) Встановіть Node залежності та браузер Playwright (для UI‑тестів).
5) Запустіть тести або увімкніть dev‑сервер.

Команди (приклад):

```bash
cp config.php.example config.php
php scripts/migrate.php
php tests/create_test_admin.php

# Опція: тестовий цикл як у CI
php tests/run_tests.php && php tests/lookup_count_test.php
npm install
npx playwright install --with-deps chromium
CERTREG_TEST_MODE=1 npx playwright test
```

Цей шлях відтворює те, що робить CI, тому мінімізує різницю між локальним середовищем та пайплайном.

### 1. Встановлення залежностей
Оновіть індекси пакетів та встановіть nginx, PHP 8.3 (з потрібними модулями), MySQL/MariaDB та Git:

```bash
sudo apt update
sudo apt install -y nginx php8.3-fpm php8.3-gd php8.3-mbstring php8.3-xml php8.3-mysql mysql-server git
sudo phpenmod gd mbstring
sudo systemctl restart php8.3-fpm
```

Примітка: Якщо у вашому дистрибутиві доступна лише PHP 8.2 – встановіть відповідні пакети `php8.2-*` та відкоригуйте команди. Додатково встановіть Certbot для HTTPS:

```bash
sudo apt install -y certbot python3-certbot-nginx
```

### 2. Налаштування MySQL

Базова безпека:

```bash
sudo mysql_secure_installation
```

Створіть БД і користувача:

CREATE USER 'certreg_user'@'localhost' IDENTIFIED BY 'strong-password-here';
GRANT ALL PRIVILEGES ON certreg.* TO 'certreg_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

Змініть пароль на надійний. (Опціонально) додайте публічного read‑only користувача пізніше для верифікації.

sudo chown -R www-data:www-data /var/www/certreg
```

### 4. Конфігурація
Скопіюйте файл прикладу:

```bash
cd /var/www/certreg
cp config.php.example config.php
```
Заповніть у `config.php`: параметри БД (`db_host`,`db_name`,`db_user`,`db_pass`), `site_name`, `logo_path`, `org_code`, `infinite_sentinel`, `canonical_verify_url`.

#### Брендування (логотип, favicon, кольори)
Тепер підтримується пер-організаційний шар. Джерела:

Пріоритет (від найвищого до найнижчого):
1. Глобальні overrides у таблиці `branding_settings` (системний шар для всіх організацій – застосовується останнім і може перекрити орг-рівень, поки не введено окремі пер-org overrides поверх).
2. Рядок організації (`organizations.*` – `name→site_name`, `logo_path`, `favicon_path`, `primary_color`, `accent_color`, `secondary_color`, `footer_text`, `support_contact`) – лише для поточного оператора (його `org_id`). Адмініни (global) бачать цей шар лише якщо самі логіняться як оператор; інакше fallback.
3. `config.php` (`site_name`, `logo_path`, `favicon_path`).
4. Статичні дефолти (`/assets/logo.png`, `/assets/favicon.ico`).

Пер-організаційні файли зберігаються у директорії `/files/branding/org_<id>/` (наприклад: `logo_*.png`, `favicon_*.svg`, `branding_colors.css`). Глобальні – у `/files/branding/`.

Файли зберігаються у `/files/branding/` з унікальними іменами (`logo_YYYYmmdd_HHMMSS.ext`, `favicon_YYYYmmdd_HHMMSS.ext`).

Обмеження:
- Логотип: PNG/JPG/SVG ≤ 2MB
- Favicon: ICO/PNG/SVG ≤ 128KB
- Кольори: HEX `#RRGGBB`

Після збереження кольорів генерується детермінований файл `branding_colors.css`:
- Глобальний (`/files/branding/branding_colors.css`) – коли збережено через вкладку "Брендування".
- Пер-організаційний (`/files/branding/org_<id>/branding_colors.css`) – коли оновлюються поля кольорів у конкретній організації.

При наявності пер-організаційного CSS (для поточного оператора) він підключається ПЕРШИМ, а за його відсутності – глобальний. (Коли зʼявиться механізм пер-org overrides поверх глобальних, порядок може бути переглянутий; наразі глобальний шар вищий у пріоритеті даних, але css-файл підключається fallback'ом.)
```css
:root{ --primary:#102d4e; --accent:#d12d8a; --secondary:#6b7280; }
```
`header.php` підключає відповідний файл ПІСЛЯ базового `assets/css/styles.css` з cache-busting параметром `?v=<mtime>`. Якщо кольори очищено – відповідний файл видаляється.

Ролі кольорів:
- `--primary` – структурний фон / базові основні кнопки.
- `--accent` – акцент для інтеракцій (сортування, прогрес, active таби, hover, пагінація).
- `--secondary` – альтернативний бренд‑тон для другорядних дій (`.btn-secondary`). Якщо не заданий – fallback до `--primary`.
- Семантичні (не бренд): успіх (`.status-badge.ok`, зелений), помилка (`.status-badge.err`, червоний), небезпечні дії (`.btn-danger`), попередження (жовтий дублікат / outline). Вони залишаються сталими для когнітивної впізнаваності.

Куди дивитись, щоб підтвердити роботу accent:
1. Посортуйте таблицю – заголовок активного стовпця стане кольору accent.
2. Статус «processing» (`.status-badge.proc`) у bulk видачі.
3. Синя смужка прогресу в bulk – змінює колір.
4. Активна вкладка горизонтальних налаштувань має підкреслення accent.
5. Наведіть курсор на кнопку у topbar – фон стає accent.
6. Активна сторінка пагінації і hover по неактивних.

Кнопки / палітра:
```text
.btn-primary   -> --primary
.btn-accent    -> --accent
.btn-secondary -> --secondary (fallback primary)
.btn-success   -> (DEPRECATED alias) now styled як accent для уніфікації; не використовуйте в новому коді
.btn-danger    -> semantic danger (червоний, НЕ бренд)
```

Utility:
```html
<button class="btn btn-accent">Accent</button>
<span class="text-accent">Акцентований текст</span>
<a class="link-accent" href="#">Акцентоване посилання</a>
```

Self‑check (`php self_check.php`) перевіряє: існування логотипу, favicon, валідність HEX для primary/accent/secondary і синтаксис `branding_colors.css` (наявність відповідних значень).

Перенос рядків у назві сайту: введіть буквально `\\n` де потрібен розрив. Напр.: `Перша частина\\nДруга частина` → у шапці два рядки, у `<title>` один.

### 5. Схема БД (актуальна, як у CI)
Рекомендовано: замість ручного SQL використовуйте `php scripts/migrate.php` (ідемпотентно). Нижче — еквівалентна схема, що відповідає CI:

```sql
USE certreg;

-- Користувачі (мінімальна схема як у CI; колонка role опціональна)
CREATE TABLE IF NOT EXISTS creds (
   id INT AUTO_INCREMENT PRIMARY KEY,
   username VARCHAR(64) NOT NULL UNIQUE,
   passhash VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Токени + індекси
CREATE TABLE IF NOT EXISTS tokens (
   id INT AUTO_INCREMENT PRIMARY KEY,
   cid VARCHAR(64) NOT NULL,
   version TINYINT NOT NULL DEFAULT 3,
   h CHAR(64) NOT NULL,
   extra_info VARCHAR(255) DEFAULT NULL,
   issued_date DATE DEFAULT NULL,
   valid_until DATE DEFAULT NULL,
   revoked_at DATETIME DEFAULT NULL,
   revoke_reason VARCHAR(255) DEFAULT NULL,
   created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
   lookup_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
   last_lookup_at DATETIME NULL,
   UNIQUE KEY uq_tokens_cid (cid),
   UNIQUE KEY uq_tokens_h (h),
   KEY idx_tokens_revoked_at (revoked_at),
   KEY idx_tokens_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Журнал подій + композитний індекс (cid, created_at)
CREATE TABLE IF NOT EXISTS token_events (
   id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
   cid VARCHAR(64) NOT NULL,
   event_type ENUM('revoke','unrevoke','delete','create','lookup') NOT NULL,
   reason VARCHAR(255) NULL,
   admin_id INT NULL,
   admin_user VARCHAR(64) NULL,
   prev_revoked_at DATETIME NULL,
   prev_revoke_reason VARCHAR(255) NULL,
   created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
   INDEX idx_cid (cid),
   INDEX idx_event (event_type),
   INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX IF NOT EXISTS idx_token_events_cid_created_at ON token_events(cid, created_at);

-- Організації (мінімальна схема як у CI)
CREATE TABLE IF NOT EXISTS organizations (
   id INT AUTO_INCREMENT PRIMARY KEY,
   name VARCHAR(160) NOT NULL,
   code VARCHAR(32) NOT NULL UNIQUE,
   is_active TINYINT(1) NOT NULL DEFAULT 1,
   primary_color VARCHAR(7) NULL,
   accent_color VARCHAR(7) NULL,
   secondary_color VARCHAR(7) NULL,
   footer_text VARCHAR(255) NULL,
   support_contact VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO organizations (id,name,code,is_active) VALUES (1,'Test Org','ORG-CERT',1)
   ON DUPLICATE KEY UPDATE name=VALUES(name), code=VALUES(code), is_active=VALUES(is_active);

-- Шаблони (звʼязані з org)
CREATE TABLE IF NOT EXISTS templates (
   id INT AUTO_INCREMENT PRIMARY KEY,
   org_id INT NOT NULL,
   name VARCHAR(160) NOT NULL,
   code VARCHAR(60) NOT NULL,
   status ENUM('active','inactive','archived') DEFAULT 'active',
   filename VARCHAR(255) NULL,
   file_ext VARCHAR(10) NULL,
   file_hash CHAR(64) NULL,
   file_size INT NULL,
   width INT NULL,
   height INT NULL,
   version INT NOT NULL DEFAULT 1,
   created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
   updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
   KEY idx_org_status (org_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Привʼязка шаблону до токена (NULLable) + індекс
ALTER TABLE tokens ADD COLUMN IF NOT EXISTS template_id INT NULL;
CREATE INDEX IF NOT EXISTS idx_tokens_template_id ON tokens(template_id);
```

Створення адміністратора (простий шлях):

```bash
php tests/create_test_admin.php
```

Або вручну через SQL/CLI — на ваш розсуд.

### Налаштування (AJAX) та акаунт

Сторінка `settings.php` використовує AJAX-навігацію: при переході між вкладками (Брендування / Організації / Оператори / Шаблони / Акаунт) довантажується лише внутрішній вміст через `settings_section.php?tab=...` без повного перезавантаження. Це зменшує навантаження й робить форми більш чуйними.

#### Вкладка «Шаблони» (Phase 1.3 UI)
Надає базове керування шаблонами:
* Створення: назва, опціонально `code` (інакше `T<ID>`), файл зображення (JPG/PNG/WEBP) – одразу генерується `preview.jpg`.
* Список із крихітним превʼю (12×12) – кешований JPEG (`preview.jpg`) для швидкого перегляду без завантаження оригіналу (клік відкриває діалог з великим превʼю).
* Дії у рядку (історично): `→` (перейти на `template.php?id=...`). Інші дії перенесені на сторінку деталей. Toggle тепер: `active ↔ inactive` (статус `archived` не перемикається).
* Версія (`version`) збільшується лише при заміні файла.
* Кешування вкладок працює разом із оновленням списку (`settings_templates.js`).
Адмін бачить селектор організації (фільтр/створення для конкретної), оператор – лише свою.

Детальна сторінка `template.php`:
* Показує всі поля (`filename`, `file_hash`, `width×height`, `version`, `updated_at`).
* Окремі форми: перейменувати, toggle, заміна файлу, видалення.
* Превʼю автоматично cache-bust через `?v=<version>`.
* Доступ: оператори лише в межах своєї `org_id`.

Налаштування превʼю:
* Максимальна ширина генерованого `preview.jpg` керується `config['template_preview_max_width']` (за замовчанням 800px).
* У таблиці масштабування робиться через CSS (клас `.tpl-prev-img`).
* Для діагностики помилок збереження файлів можна ввімкнути розширений режим (`config['debug_templates']=true` або заголовок `X-Debug-Template: 1`).

Вкладка «Акаунт» дозволяє змінити пароль поточного користувача (admin або operator). Форма надсилає POST на `/api/account_change_password.php` з полями:
```
_csrf         – токен CSRF
old_password  – поточний пароль
new_password  – новий пароль
new_password2 – підтвердження
```
Правила:
* Мінімум 8 символів
* Принаймні одна літера та одна цифра (простий евристичний чек)
* Після успіху – `session_regenerate_id(true)` для захисту від fixation.

У разі помилки повертається JSON `{ ok:false, errors:{ field:"reason" } }`, при успіху `{ ok:true }`.
Фронтенд показує статус у полі `#accountPwdStatus` й простий індикатор сили (дуже слабкий → надійний).

AJAX кешує вкладки в памʼяті (in-memory cache) – повторний перехід не робить додатковий HTTP запит, доки не буде перезавантажено сторінку.

### Оператори (винесено на окремі сторінки)

Управління операторами перенесено із вкладки `settings.php?tab=users` на окремі сторінки:

* `settings.php?tab=users` – список усіх облікових записів (admin + operator) з індикаторами статусу (read‑only).
* `operator.php?id=...` – детальна сторінка з діями для конкретного оператора (rename / toggle / reset / delete).

Адміністраторські акаунти відображаються у списку, але будь-які зміни над ними через UI заборонені (захист від ескалації / помилкового редагування). 

Доступні дії (лише для `role=operator`):
* Створення (через форму у вкладці `Налаштування → Користувачі` або API точку `/api/operator_create.php`).
* Перейменування логіна.
* Активування / деактивація (`is_active` toggle).
* Скидання паролю (введення нового вручну з подвійним підтвердженням).
* Видалення (після підтвердження, без soft-delete).

Безпекові обмеження:
* Жодних змін для записів з `role=admin` (форсований редірект із повідомлення `forbidden`).
* Усі state-операції виконуються POST + CSRF (`_csrf`) + `require_admin()` серверна перевірка.
* `is_active=0` блокує логін без потреби видаляти акаунт.

Мапа кодів помилок (JSON або redirect `msg` параметр):
```
exists     – логін зайнятий
uname      – невалідний логін (regex)
mismatch   – паролі не співпадають
short      – пароль < 8
forbidden  – дія над admin
nf         – не знайдено
badid      – розбіжність ідентифікатора у формі
empty      – відсутні обовʼязкові поля
db|err     – внутрішня помилка
unknown    – невідома дія
```

Рекомендація: деактивуйте оператора замість видалення для можливості швидкого ре-активування та збереження журналів повʼязаних подій у системі (опосередковано через `token_events`).

API точки залишаються (збережена сумісність):
* `GET /api/operators_list.php`
* `POST /api/operator_create.php`
* `POST /api/operator_toggle_active.php`
* `POST /api/operator_reset_password.php`
* `POST /api/operator_rename.php`
* `POST /api/operator_delete.php`

Nginx: `operator.php` у regex групі адмінських сторінок; `operators_list.php` явно дозволений для `GET` (інші операторські дії лишаються POST‑тільки).

### Організації (детальні сторінки)

`organization.php?id=...` – детальна сторінка організації за аналогією до `operator.php`.

* `settings.php?tab=organizations` – перелік організацій (пошук, сортування, створення).
* `organization.php?id=...` – редагування: назва, кольори (primary / accent / secondary), footer/support, логотип, favicon, статус (активна / вимкнена), видалення.

Обмеження та правила:
* Код (`code`) immutable (read‑only) – використовується в canonical ORG полі.
* Дефолтна організація (`config.org_code`) не може бути вимкнена або видалена.
* Видалення заборонене, якщо існують оператори або токени (`has_ops`, `has_tokens`).
* Оновлення виконується AJAX multipart POST на `api/org_update.php`; у відповіді повертається нормалізований обʼєкт `org`.
* Пер-організаційні кольори → файл `files/branding/org_<id>/branding_colors.css`, що підключається в `header.php` для оператора цієї організації.
* Відсутні inline `<script>` – логіка у `assets/js/org_edit.js` (CSP friendly).

Це уніфікує UX (оператори та організації мають рівнозначні сторінки) та спрощує підтримку.

### 6. Nginx
Приклад конфігурації знаходиться у `docs/nginx/certreg.conf`.

Кроки:
1) Скопіюйте приклад:
   `/var/www/certreg/docs/nginx/certreg.conf` → `/etc/nginx/sites-available/certreg`
2) Відредагуйте `server_name` та `fastcgi_pass` під вашу версію PHP-FPM (напр., `/run/php/php8.3-fpm.sock`).
3) Увімкніть сайт і перезавантажте nginx.

Після зміни перевірте синтаксис і перезавантажте:

```bash
sudo ln -s /etc/nginx/sites-available/certreg /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

### 7. Перевірка PHP-FPM
Переконайтеся, що версія збігається з сокетом у конфігурації nginx:

```bash
systemctl status php8.3-fpm
```

### 8. Self-check

```bash
php self_check.php
```
Або відкрийте `/verify.php` в браузері. Перевірте, що CSP не блокує необхідні ресурси і немає помилок 500 в логах.

### 9. HTTPS (рекомендовано)

```bash
sudo certbot --nginx -d your.domain.name
```
Після випуску сертифіката додайте HSTS у продакшні (в nginx), не в PHP.

### 10. Rate limiting (опція)
Додайте в nginx (приклад):
```
limit_req_zone $binary_remote_addr zone=api_status:10m rate=30r/m;
location /api/status.php { limit_req zone=api_status burst=10 nodelay; }
```

### 11. Резервні копії
Резервуйте: `config.php`, дампи БД (`mysqldump certreg`), логи nginx, список встановлених пакетів.

### 12. Оновлення
Оскільки міграцій немає: зробіть резервну копію БД → `git pull` → перевірка `self_check.php`. Якщо буде потрібно змінити схему в майбутньому – окремий оновлений SQL блок буде додано в README.

### Додаткові сценарії прав доступу
Якщо потрібно додати права існуючому обмеженому користувачу (для індексів/ALTER):

```sql
GRANT ALTER, INDEX, DROP, CREATE ON certreg.* TO 'certuser'@'localhost';
FLUSH PRIVILEGES;
```
Після цього можна виконати:
```sql
ALTER TABLE tokens ADD UNIQUE KEY uq_tokens_cid (cid);
```

(Для старої таблиці `data` приклад був: `ALTER TABLE data ADD UNIQUE KEY uq_data_hash (hash);`)

---
Стисла версія (TL;DR):
1. Встановити пакети та PHP модулі.
2. Створити БД + користувача.
3. Клонувати код, налаштувати `config.php`.
4. Виконати SQL зі схемою (creds, tokens, token_events) + додати admin.
5. Налаштувати nginx + HTTPS.
6. self_check & тестова видача токена.
7. Увімкнути rate limiting / резервні копії.

## API
- `POST /api/register.php` – створення токена `{ cid, v:3, h, date, valid_until, extra_info? }`.
   - Починаючи з multi-org: клієнт може передавати `org_code` (читабельний код організації) – бекенд ігнорує підміну й привʼязує запис до `org_id` поточного оператора (або явно вказаного коду, якщо це admin). Невідповідність -> `org_mismatch`.
 - `GET /api/templates_list.php` – список шаблонів (поточна реалізація: максимум 200, сортування `id DESC`).
    - Оператор: тільки його організація.
    - Адмін: усі; можна фільтрувати `?org_id=`.
    - Формат (оновлено з metadata + preview): `{ ok:true, items:[ { id, org_id?, org_code?, name, code, status, filename, file_ext, width, height, version, created_at, updated_at, preview_url? } ] }`.
    - `preview_url` присутній якщо створено кешований превʼю `preview.jpg` (генерується при створенні/оновленні).
    - Якщо таблиця відсутня – `{ ok:true, items:[], note:"no_templates_table" }`.
   - Nginx: додано до allowlist (див. `docs/nginx/certreg.conf` блок `location ~ ^/api/(org_list|templates_list)\.php$`).
 - `POST /api/template_create.php` – створення нового шаблону (фонового зображення) сертифіката.
      - Формат запиту: `multipart/form-data`
      - Поля:
         * `name` (обовʼязково, ≤160 символів)
         * `code` (опційно, якщо не вказано – автогенерується `T<ID>`; шаблон: `^[A-Za-z0-9_-]{2,60}$`)
         * `org_id` (опційно тільки для admin; оператор ігнорує та використовує власну організацію; admin без `org_id` зараз отримує помилку `org_id_required` для явності)
         * `template_file` (input name; JPG/JPEG/PNG/WEBP, 200..12000 px по ширині/висоті, ≤15MB)
         * `_csrf` (стандартний CSRF токен)
      - Валідації / коди помилок (`error`):
         * `name_required`, `name_too_long`
         * `org_id_required`, `bad_org_id`, `org_context_missing`
         * `file_required`, `upload_error`, `upload_invalid`, `file_empty`, `file_too_large`
         * `bad_ext`, `not_image`, `image_too_small`, `image_too_large`
         * `bad_code` (regex не пройдений), `code_exists`
         * `db` (внутрішня БД помилка), `file_store_failed`, `no_templates_table`
      - Повертає: `{ ok:true, template:{ id, org_id, name, code, status:'active', filename, file_ext, file_hash, file_size, width, height, version } }`.
      - Статус початково завжди `active`.
      - Файлова структура: `files/templates/<org_id>/<template_id>/original.<ext>`; хеш SHA-256 збережено у `file_hash` для майбутньої перевірки цілісності.
      - Безпека: код унікальний у межах організації (унікальність забезпечується перевіркою перед вставкою, fallback `T<ID>` гарантовано унікальний).
      - Nginx: додано до POST адмінського блоку (оновлено `docs/nginx/certreg.conf`).
      - Приклади:
         ```bash
         curl -X POST -b cookie.txt -F "name=Base Template" -F "template_file=@/path/bg.png" -F "_csrf=..." https://example.org/api/template_create.php
         ```
         Відповідь:
         ```json
         {"ok":true,"template":{"id":7,"org_id":3,"name":"Base Template","code":"T7","status":"active","filename":"bg.png","file_ext":"png","file_hash":"<sha256>","file_size":123456,"width":1600,"height":900,"version":1}}
         ```
      - Подальші кроки (Phase 1): `template_update`, `template_delete`, `template_toggle`, оновлений `templates_list` з метаданими та попередніми зображеннями (preview кеш).
- `GET /api/status.php?cid=...` – перевірка статусу `{ h, revoked?, revoked_at?, revoke_reason?, valid_until }`.
 - `POST /api/template_update.php` – оновлення існуючого шаблону.
      - Поля (multipart/form-data або application/x-www-form-urlencoded якщо без файлу):
         * `id` (обовʼязково)
         * `name` (опційно; якщо передано — перевірка як у create, ≤160)
      * `status` (опційно; `active|inactive|archived`)
         * `template_file` (опційно; замінює фон — ті самі обмеження: ext JPG/PNG/WEBP, 200..12000 px, ≤15MB, оновлює `file_*`, `width/height`, `hash`)
         * `_csrf`
      - Іммʼютабельне: `code`, `org_id` (для оператора). Адмін не змінює org через цей endpoint.
      - Версіонування: при заміні файлу `version = version + 1`.
      - Коди помилок: `bad_id`, `not_found`, `org_context_missing`, `forbidden`, `name_required`, `name_too_long`, `bad_status`, всі файлові як у create (`upload_error`, `file_empty`, `file_too_large`, `bad_ext`, `not_image`, `image_too_small`, `image_too_large`), `nothing_to_update`, `db`, `file_store_failed`.
      - Відповідь: `{ ok:true, template:{ id, org_id, name, code, status, filename, file_ext, file_hash, file_size, width, height, version, created_at, updated_at } }`.
      - Nginx: додано до POST admin API блоку.
      - Приклад:
         ```bash
         curl -X POST -b cookie.txt -F "id=7" -F "name=Updated Template" -F "status=inactive" -F "_csrf=..." https://example.org/api/template_update.php
         ```
         Зі зміною фонового файлу:
         ```bash
         curl -X POST -b cookie.txt -F "id=7" -F "template_file=@/path/new_bg.png" -F "_csrf=..." https://example.org/api/template_update.php
         ```
         Відповідь (скорочено):
         ```json
         {"ok":true,"template":{"id":7,"org_id":3,"name":"Updated Template","status":"active","version":2,...}}
         ```
 - `POST /api/template_delete.php` – видалення (hard delete) шаблону.
 - `POST /api/template_toggle.php` – швидка зміна статусу `active ↔ inactive` (якщо `archived` – повертає `bad_status_current`).
         - Поля: `id`, `_csrf`.
         - Умова: Працює лише якщо поточний статус у домені `active|inactive`; `archived` повертає `bad_status_current`.
         - Логіка: читає поточний статус → встановлює протилежний → повертає оновлений обʼєкт шаблону.
         - Коди помилок: `bad_id`, `not_found`, `org_context_missing`, `forbidden`, `bad_status_current` (якщо статус поза `active|disabled`), `db`, `no_templates_table`.
         - Відповідь: `{ ok:true, template:{ ... } }` (той самий набір полів що в `template_update`).
         - Виклик:
            ```bash
            curl -X POST -b cookie.txt -F "id=7" -F "_csrf=..." https://example.org/api/template_toggle.php
            ```
         - Використання в UI: кнопка/іконка «Активувати/Вимкнути» без необхідності завантаження файлу.

### Превʼю (preview cache)
При `template_create` та при завантаженні нового файлу в `template_update` генерується превʼю `preview.jpg` (JPEG, якість ~82, ширина максимум 800px, пропорції збережено). 
Використання:
* Швидке завантаження списку шаблонів у UI без важких оригіналів.
* Поле `preview_url` містить cache-busting параметр `?v=<mtime>`; на детальній сторінці використовується версія ( `?v=<version>` ).
* Зменшене превʼю в таблиці відображається як 12×12 (іконка) для стабільної ширини таблиці; клік відкриває модальне велике зображення.
* Якщо генерація превʼю з якоїсь причини не вдалася (нестача GD / memory) – основна операція не вважається помилковою; просто відсутній `preview_url` і self_check позначить `[WARN] Preview missing`.
* Налаштування: `template_preview_max_width` у `config.php`.

Self-check (T1) тепер:
* Перевіряє наявність preview для останніх шаблонів.
* Виявляє «осиротілі» директорії або відсутні директорії для записів у БД.

      - Поля: `id` (обовʼязково), `_csrf`.
      - Поведінка: жорстке видалення запису з БД та рекурсивне очищення директорії `files/templates/<org_id>/<id>/` (усі версійні файли *поки* зберігаються як один `original.<ext>`, тож додаткових перевірок не потрібно). Операція незворотна.
      - Обмеження доступу:
         * Оператор може видаляти лише шаблони своєї організації.
         * Admin може видаляти будь-який шаблон (org scope ігнорується), але майбутні звʼязки з токенами (після додавання `tokens.template_id`) заблокують видалення.
      - Захист від використання: якщо у таблиці `tokens` існує колонка `template_id` і є хоча б один токен з цим значенням – повертається `in_use` і видалення не виконується.
      - Коди помилок (`error`):
         * `bad_id` – невалідний або відсутній `id`.
         * `not_found` – шаблон не існує (або гонка після вже видаленого).
         * `org_context_missing` – оператор без привʼязаного `org_id` (порушення інваріанту multi-org).
         * `forbidden` – спроба оператора видалити чужий шаблон.
         * `in_use` – (майбутній сценарій) шаблон привʼязаний до одного чи кількох токенів.
         * `fs_delete_failed` – запис у БД видалено, але очищення файлової директорії частково або повністю не вдалося (рекомендується ручне прибирання). Повертається `partial:true`.
         * `db` – непередбачена помилка БД.
         * `no_templates_table` – таблиця відсутня (міграція не застосована).
      - Відповідь при успіху: `{ ok:true, deleted_id:<id> }`.
      - Приклади:
        ```bash
        curl -X POST -b cookie.txt -F "id=7" -F "_csrf=..." https://example.org/api/template_delete.php
        ```
        Відповідь:
        ```json
        {"ok":true,"deleted_id":7}
        ```
      - Примітка щодо цілісності: видалення спершу видаляє рядок з БД (щоб блокувати паралельні update), потім намагається очистити файлову структуру. Якщо файлова частина провалилась – повторний запуск не відновить шаблон (рядок вже видалений), але self-check (див. розділ нижче, заплановане розширення) може попередити про «осиротілі» директорії або залишкові файли.
      - Nginx: додано до regex POST API блоку поруч із `template_create` та `template_update`.
      - Подальші кроки: після додавання `template_toggle` цей endpoint завершує базовий CRUD.
- `POST /api/bulk_action.php` – пакетні операції `revoke | unrevoke | delete`.
- `GET /api/events.php?cid=...` – журнал подій.
- `POST /api/account_change_password.php` – зміна паролю (аутентифікований admin/operator).
 - `GET /api/operators_list.php` – список користувачів (`id, username, role, is_active, created_at`).
 - `POST /api/operator_create.php` – створення оператора (`_csrf, username, password, password2`).
 - `POST /api/operator_toggle_active.php` – вкл/викл оператора (`id`).
 - `POST /api/operator_reset_password.php` – встановлення нового паролю оператору (`id,password,password2`).
 - `POST /api/operator_rename.php` – перейменування оператора (`id, username`).
 - `POST /api/operator_delete.php` – видалення оператора (не для admin).
 - `POST /api/operator_change_org.php` – зміна організації оператора (admin only, заборонено для admin акаунтів).
 - `POST /api/org_create.php` – створення організації (ім'я, код immutable, опційно кольори, логотип, favicon).
 - `POST /api/org_update.php` – оновлення назви, кольорів, footer/support, логотипу, favicon, активності (код незмінюється).
 - `POST /api/org_set_active.php` – швидке вкл/викл статусу is_active (НЕ можна вимкнути дефолтну організацію `config.org_code`; бекенд повертає `default_protected`, UI не показує кнопку для неї).
 - `POST /api/org_delete.php` – видалення організації (заборонено, якщо прив'язані оператори або токени, А ТАКОЖ неможливо для дефолтної організації `config.org_code`). UI автоматично вимикає кнопку для дефолтної. Папка `/files/branding/org_<id>/` очищається.
 - `GET /api/org_list.php` – пагінація + пошук (name/code) + сортування (id,name,code,created_at).
   - Усі operator-create / change_org вимагають активну організацію; `org_code` immutable.

## Міграції
Докладно в [MIGRATION.md](MIGRATION.md). Поточна канонічна схема (v3):
```
v3|NAME|ORG|CID|ISSUED_DATE|VALID_UNTIL|CANON_URL|EXTRA
```
Сервер зберігає тільки `{cid,version,h,issued_date,valid_until,extra_info?}`.

### 2025-09-21: Organizations + org_id
Мета: мульти-орговий фундамент без зміни існуючих canonical токенів.

Скрипт (ідемпотентний):
```bash
php scripts/migrations/2025_09_21_add_organizations.php
```
Що робить:
1. Створює таблицю `organizations` (якщо відсутня): `id,name,code,logo_path,favicon_path,primary_color,accent_color,secondary_color,footer_text,support_contact,is_active,created_at,updated_at`.
2. Вставляє/знаходить default org (`code=config.org_code`, `name=config.site_name`).
3. Додає `creds.org_id` (NULLable) + індекс. Backfill: усім `role=operator` виставляє default org. Адміни залишаються `NULL` (глобальні).
4. Додає `tokens.org_id` (NULLable) + індекс. Backfill усіх токенів default org.
5. Додає `templates.org_id` (NULLable) + індекс. Backfill існуючих.

Політика:
- `organizations.code` immutable (використовується у canonical рядку як ORG).
- Admin (role=admin, org_id=NULL) бачить усі організації.
- Operators повинні мати `org_id` (бекенд примусово встановлює при створенні).
- Надалі брендинг/шаблони резольвуються через `org_id` із fallback на глобальні налаштування.
 - Видача токенів: canonical рядок включає поле `ORG` яке дорівнює коду організації оператора. JS бере код із `data-org` у `<body>`, яке формується у `header.php` після злиття брендувань і пер-організаційного шару. Бекенд зберігає `tokens.org_id` (якщо колонка є) і повертає помилку `org_mismatch`, якщо переданий у запиті `org_code` не відповідає сесійній організації.
 - Підготовка до привʼязки шаблонів: додано `templates_list` + клієнтський селектор (не впливає на HMAC; canonical не містить template id). Наступний етап: зберігання `templates.org_id` та валідація відповідності при видачі.
   - Бейдж активної організації тепер відображається на сторінці видачі (`issue_token.php`) за допомогою зовнішнього скрипта `assets/js/issue_org_badge.js` (CSP без inline JS). Inline `<script>` вилучено.

Подальші кроки:
1. Прив'язка операторів до org (обов'язково для role=operator, admin = глобальний `NULL`).
2. Пер-організаційне застосування брендування (fallback на глобальне якщо немає налаштувань).
3. Шаблони/видача токенів обмежені org.
4. Canonical рядок включає `ORG=<code>`.

В поточній реалізації вже доступні CRUD (окрім inline edit у таблиці) та безпечне видалення без каскаду наявних даних (hard block, якщо є залежності). Кольори організації — окремий `branding_colors.css` у директорії org.

### 2025-09-20: Додавання `creds.created_at`
Для відображення дати створення операторів у UI додано стовпець `created_at` у таблицю `creds`.

Запустіть міграцію (ідемпотентна – можна запускати повторно без шкоди):
```bash
php scripts/migrations/2025_09_20_add_creds_created_at.php
```
Що робить скрипт:
1. Створює `created_at DATETIME` якщо його немає.
2. Backfill: ставить `NOW()` для рядків без значення / з нульовою датою.
3. Намагається зробити колонку `NOT NULL DEFAULT CURRENT_TIMESTAMP`.
4. Додає індекс `idx_creds_created_at` (якщо відсутній).

Після цього колонка "Створено" в списку користувачів показує реальну дату.

## Безпека
Докладні рекомендації, модель загроз та чеклісти наведено у [SECURITY.md](SECURITY.md). Ключові принципи:
- Анонімні токени та canonical рядок із полями `ORG`, `CID`, `VALID_UNTIL`.
- Окремі користувачі БД з мінімальними правами.
- Підтримка rate‑limiting та жорсткого CSP.

### Модель приватності (узагальнено)
| Дані | БД | QR payload | Ніколи не надсилається |
|------|----|-----------|------------------------|
| NAME (оригінал) | ✗ | ✗ | ✓ |
| Нормалізоване NAME | ✗ | ✗ (впливає лише на HMAC) | – |
| Salt (32B) | ✗ | ✓ | ✗ |
| H (HMAC) | ✓ | ✓ | – |
| INT (10 hex) | похідне | відображається | – |

### Архітектурний потік
1. Видача: клієнт нормалізує імʼя → формує canonical → обчислює HMAC → надсилає лише метадані + `cid` + `h`.
2. Перевірка: декодування payload → реконструкція canonical → HMAC → статус.
3. Аудит: події записуються у `token_events` без PII.

### Audit Trail
`token_events(event_type)` = `create|revoke|unrevoke|delete|lookup`, зберігає попередні значення для forensics.

### Плановані покращення
- Усунення inline JS → CSP без nonce.
- Автоматичний diff security headers (`self_check` розширення).
- Розширена гомогліф-детекція.

## Дорожня карта
1. Винести всі inline scripts → чистий CSP.
2. Batch PDF (M4).
3. Спрощення статус‑колонки у `tokens.php`: прибрати кнопки, показувати лише «Активний» / «Відкликано».
4. Nginx hardening + rate limiting (M6).
5. QR refactor & caching (M7).
6. Header diff self-check (M8).
7. Розширена детекція гомогліфів.

## Ліцензія
MIT

## Автоматичні тести
Front-end (UI) покриття реалізовано за допомогою Playwright (E2E) + PHP CLI тести (бекенд / API / CSP).

Команди:

```bash
# Встановити Node залежності (один раз)
npm install

# Запуск лише PHP тестів
php tests/run_tests.php

# Повний UI набір (headless Chromium)
CERTREG_TEST_MODE=1 npx playwright test

# Окремий тест (приклад bulk)
CERTREG_TEST_MODE=1 npx playwright test tests/ui/tokens_bulk.spec.js
```

CERTREG_TEST_MODE=1 відключає rate limiting для стабільності тестів.

### Вибір розміру PDF
Поодиноке та масове створення сертифікатів підтримують вибір формату сторінки безпосередньо у формі видачі:

- **Авто** — сторінка підлаштовується під розмір шаблону або фонового зображення.
- **A4 / Letter** — попередньо задані орієнтації (портрет і ландшафт) задають `MediaBox` PDF; зображення масштабується із збереженням пропорцій.
- **Власний розмір** — можна ввести ширину та висоту у мм; коректні значення обовʼязкові для генерації.

Обраний формат застосовується до автоматичних і ручних завантажень PDF (індивідуальних і пакетних). Масштабування виконується пропорційно, тож сертифікат не спотворюється.

### Batch PDF (test mode vs normal)
У звичайному режимі після успішної обробки кількох (>=2) рядків система автоматично генерує batch PDF (мульти‑сторінковий) з усіма сертифікатами.

У `CERTREG_TEST_MODE=1` автоматичне створення та автоматичне завантаження batch PDF вимкнено для уникнення race‑умов із квитковим (ticket) механізмом тестового завантаження. Тести явно клікають кнопку `Batch PDF` після того як усі бейджі перейдуть у стан `OK`. Це робить результат детермінованим і запобігає отриманню fallback мінімального PDF.

Одно‑рядковий сценарій у тестовому режимі й надалі використовує ticket для стабільного завантаження індивідуального PDF.

PDF завантаження та QR рендер перевіряються; bulk revoke/unrevoke/delete, сортування, гомогліфи та login UI – покриті.

Глобальний guard для помилок консолі браузера автоматично провалює тест, якщо зʼявляються `console.error` або CSP-помилки (реалізовано у `tests/ui/fixtures.js`). Специ повинні імпортувати `./fixtures` замість `@playwright/test`.

Додаткове покриття: сторінка перевірки (`verify_status.spec.js`) перевіряє активний, відкликаний та прострочений сертифікати.
Повний життєвий цикл токена покриває `verify_lifecycle.spec.js`:
- Revoke → статус "Відкликано" + відображення причини на сторінці токенів.
- Unrevoke → повернення статусу "Активний" при повторній перевірці.
- Delete → повторна перевірка повертає повідомлення "не знайдено".

### Покриття (matrix)
| Категорія | Тести | Статус |
|-----------|-------|--------|
| Видача одинична | `api_flow.php`, UI issuance | ✓ |
| Видача bulk | (план) | ▶ |
| Revocation lifecycle | `verify_lifecycle.spec.js` | ✓ |
| Expiry | `verify_status.spec.js` | ✓ |
| Audit events | `api_flow.php` + spot | ✓ (розширити) |
| Homoglyph guard | UI | ✓ (базова) |
| Сортування/пагінація | UI | ✓ (масштаб ▶) |
| Self-check | `self_check.php` | ✓ (розширення ▶) |
| Security headers diff | (скрипт) | ▶ |

Пер-рядкові (inline) дії відкликання / відновлення реалізовані через невеликі `<form>` у колонці "Статус" (`tokens.php`) та обробляються AJAX (`assets/js/tokens_page.js`). Тести вводять причину перед кліком (валідація бекенду вимагає >=5 символів).

## Ігноровані файли / артефакти
`.gitignore` включає:
- `node_modules/`, Playwright звіти (`playwright-report/`, `blob-report/`, `test-results/`), тимчасові та кеш файли.
- Секрети: `config.php`, `.env*`, ключі (`*.key`, `*.pem`, `*.crt`, ...).
- Згенеровані сертифікати: `files/certs/*` (порожня `.gitkeep` зберігає директорію).
- Локальна конфігурація веб-сервера: `certreg.conf`.

Не коміть реальні приватні ключі / сертифікати. Для тестування використовуйте самопідписані.

### CI
GitHub Actions workflow (`.github/workflows/ci.yml`) виконує:
1. Ініціалізацію MySQL + схему.
2. PHP бекенд тести (`run_tests.php`, `lookup_count_test.php`, `self_check.php`).
3. UI тести Playwright (Chromium, headless) з `CERTREG_TEST_MODE=1`.
4. Завантаження звіту Playwright у разі помилки.

Локально повний цикл можна відтворити:

```bash
php tests/run_tests.php && php tests/lookup_count_test.php && CERTREG_TEST_MODE=1 npx playwright test
```

## self_check remediation
`php self_check.php` виконує:
1. Перевірку whitelist entrypoints.
2. Перевірку прав `config.php`.
3. Схему (`tokens`, `token_events`).
4. Файлову безпеку (H2) та аудит подій (H10).

Флаг `--suggest-fixes` показує SQL для виправлення аномалій журналу.
Опціонально можна додати автоматичну вставку synthetic `create` подій (див. TODO в `self_check.php`).
