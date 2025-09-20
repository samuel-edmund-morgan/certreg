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

## Установка

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

```sql
sudo mysql
CREATE DATABASE certreg CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'certreg_user'@'localhost' IDENTIFIED BY 'strong-password-here';
GRANT ALL PRIVILEGES ON certreg.* TO 'certreg_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

Змініть пароль на надійний. (Опціонально) додайте публічного read‑only користувача пізніше для верифікації.

### 3. Клонування і права

```bash
sudo git clone https://github.com/your-org/certreg.git /var/www/certreg
sudo chown -R www-data:www-data /var/www/certreg
```

### 4. Конфігурація
Скопіюйте файл прикладу:

```bash
cd /var/www/certreg
cp config.php.example config.php
```
Заповніть у `config.php`: параметри БД (`db_host`,`db_name`,`db_user`,`db_pass`), `site_name`, `logo_path`, `org_code`, `infinite_sentinel`, `canonical_verify_url`.

### 5. Створення таблиць (v3)
Початкова v3-схема. Виконайте (налаштувавши БД):

```sql
USE certreg;

CREATE TABLE creds (
   id INT AUTO_INCREMENT PRIMARY KEY,
   username VARCHAR(64) NOT NULL UNIQUE,
   passhash VARCHAR(255) NOT NULL,
   `role` ENUM('admin', 'operator') NOT NULL DEFAULT 'operator'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE tokens (
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

CREATE TABLE token_events (
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

CREATE TABLE templates (
   id INT AUTO_INCREMENT PRIMARY KEY,
   name VARCHAR(255) NOT NULL,
   filename VARCHAR(255) NOT NULL,
   coordinates JSON DEFAULT NULL,
   created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
   created_by INT NULL,
   is_active BOOLEAN NOT NULL DEFAULT TRUE,
   INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE branding_settings (
   setting_key VARCHAR(100) PRIMARY KEY,
   setting_value TEXT,
   updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Створіть адміністратора:

```bash
php -r "echo password_hash('YourStrongPass', PASSWORD_DEFAULT), PHP_EOL;"
```
Вставте хеш у SQL:

```sql
INSERT INTO creds (username, passhash, `role`) VALUES ('admin','<HASH>', 'admin');
```

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
- `GET /api/status.php?cid=...` – перевірка статусу `{ h, revoked?, revoked_at?, revoke_reason?, valid_until }`.
- `POST /api/bulk_action.php` – пакетні операції `revoke | unrevoke | delete`.
- `GET /api/events.php?cid=...` – журнал подій.

## Міграції
Докладно в [MIGRATION.md](MIGRATION.md). Поточна канонічна схема (v3):
```
v3|NAME|ORG|CID|ISSUED_DATE|VALID_UNTIL|CANON_URL|EXTRA
```
Сервер зберігає тільки `{cid,version,h,issued_date,valid_until,extra_info?}`.

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
