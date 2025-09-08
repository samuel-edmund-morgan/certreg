# certreg (privacy-first)

Мінімалістична система перевірки сертифікатів без зберігання персональних даних. Імʼя нормалізується локально та не потрапляє в БД; сервер оперує лише анонімними токенами.

## Зміст
- [Установка](#установка)
- [API](#api)
- [Міграції](#міграції)
- [Безпека](#безпека)
- [Дорожня карта](#дорожня-карта)
- [Ліцензія](#ліцензія)

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
Заповніть у `config.php`: параметри БД (`db_host`,`db_name`,`db_user`,`db_pass`), `site_name`, `logo_path`, `org_code`, `infinite_sentinel`.

### 5. Створення таблиць (якщо ще не існують)
Для актуальної (v2) схеми використовується таблиця `tokens` (міграції вже мають SQL). Якщо розгортаєте «з нуля», просто виконайте потрібну міграцію:

```bash
php migrations/004_create_tokens_table.php
```

Якщо у вас залишилась стара таблиця `data`, її можна видалити або архівувати через `php migrations/005_drop_legacy.php --archive`.

Ініціалізуйте обліковий запис адміністратора (приклад):

```sql
USE certreg;
CREATE TABLE creds (
   id INT AUTO_INCREMENT PRIMARY KEY,
   username VARCHAR(64) NOT NULL UNIQUE,
   passhash VARCHAR(255) NOT NULL
);
-- згенеруйте хеш пароля у PHP CLI: 
-- php -r "echo password_hash('YourStrongPass', PASSWORD_DEFAULT), PHP_EOL;"
INSERT INTO creds (username, passhash) VALUES ('admin','<сюди_вставити_згенерований_хеш>');
```

### 6. Nginx
Створіть файл сайту `/etc/nginx/sites-available/certreg` (приклад в репозиторії може містити CSP/headers). Увімкніть і перезапустіть:

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
При деплої: `git pull`, запуск нових міграцій, контрольний `self_check.php`.

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
4. Запустити міграцію `004_create_tokens_table.php`.
5. Створити `creds` + admin.
6. Налаштувати nginx + HTTPS.
7. self_check & тестова видача токена.
8. Увімкнути rate limiting / резервні копії.

## API
- `POST /api/register.php` – створення токена `{cid,v,h,course,grade,date,valid_until}`.
- `GET /api/status.php?cid=...` – перевірка статусу `{h, revoked_at?, revoke_reason?}`.
- `POST /api/bulk_action.php` – пакетні операції `revoke | unrevoke | delete`.
- `GET /api/events.php?cid=...` – журнал подій.

## Міграції
Перехід між версіями описано в [MIGRATION.md](MIGRATION.md). Поточна канонічна схема:
```
v2|NAME|ORG|CID|COURSE|GRADE|ISSUED_DATE|VALID_UNTIL
```

## Безпека
Докладні рекомендації, модель загроз та чеклісти наведено у [SECURITY.md](SECURITY.md). Ключові принципи:
- Анонімні токени та canonical рядок із полями `ORG`, `CID`, `VALID_UNTIL`.
- Окремі користувачі БД з мінімальними правами.
- Підтримка rate‑limiting та жорсткого CSP.

## Дорожня карта
1. Винести всі inline scripts → чистий CSP.
2. Batch PDF (M4).
3. Nginx hardening + rate limiting (M6).
4. QR refactor & caching (M7).
5. Header diff self-check (M8).
6. Розширена детекція гомогліфів.

## Ліцензія
MIT
