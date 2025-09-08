# certreg (privacy-first)

Мінімалістична система перевірки сертифікатів без зберігання персональних даних. Імʼя нормалізується локально та не потрапляє в БД; сервер оперує лише анонімними токенами.

## Зміст
- [Установка](#установка)
- [API](#api)
- [Міграції](#міграції)
- [Безпека](#безпека)
- [Дорожня карта](#дорожня-карта)
- [Ліцензія](#ліцензія)

## Установка (коротко)
1. Створіть БД і користувача(ів).
2. `cp config.php.example config.php` і заповніть параметри (`db_*`, `org_code`).
3. `php migrations/004_create_tokens_table.php` (створення таблиць).
4. (Опція) `php migrations/005_drop_legacy.php --archive` для архівації/видалення legacy.
5. Nginx whitelist тільки потрібних PHP entrypoints.
6. Налаштуйте rate‑limit `/api/status.php`.

➡ Для повної, покрокової інструкції див. наступний розділ.

---
## Детальна інсталяція (рекомендовано)

### 1. Вимоги
| Компонент | Мінімум | Примітки |
|-----------|---------|----------|
| ОС | Debian/Ubuntu LTS або сумісна | Наведені команди для Debian/Ubuntu |
| PHP | 8.2+ (рекоменд. 8.3) | Розширення: `pdo_mysql`, `mbstring`, `gd`, `openssl`, `json` |
| MySQL / MariaDB | 10.5+ / 8.0+ | InnoDB, strict sql_mode |
| Web сервер | nginx + php-fpm | Whitelist конфіг |
| Шрифти | `fonts/Montserrat-Light.ttf` | Для рендеру PDF/зображення (клієнтське використання) |
| Шаблон | `files/cert_template.jpg` | Фонове зображення сертифіката |

Перевірити розширення PHP:
```bash
php -m | grep -E 'mbstring|gd|pdo_mysql'
```

### 2. Встановлення пакетів (Debian / Ubuntu)
```bash
sudo apt update
sudo apt install -y nginx php-fpm php-gd php-mbstring php-xml php-mysql php-cli git unzip
sudo systemctl enable --now php-fpm nginx
```
> Якщо у репозиторії використовується конкретна версія (наприклад 8.3), замініть узагальнені пакети на `php8.3-*`.

### 3. Клонування репозиторію
```bash
cd /var/www
sudo git clone <REPO_URL> certreg
cd certreg
sudo chown -R www-data:www-data .
```
> Або залиште власнику-адміну, а для запису логів/кеша надайте точкові права (тут немає потреби у глобальному записі для www-data, окрім можливого кешу QR у майбутньому).

### 4. Конфігурація
```bash
cp config.php.example config.php
```
Заповнити:
* `db_host`, `db_name`, `db_user`, `db_pass`
* `org_code` (НЕ міняти після першої бойової видачі — входить у HMAC)
* `site_name`, `logo_path` (опція)
* Координати (якщо використовуються для позиціювання елементів при генерації canvas)

### 5. База даних
Увійти в MySQL:
```bash
sudo mysql
```
Створити БД та основного користувача (приклад):
```sql
CREATE DATABASE certreg CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'certreg_app'@'localhost' IDENTIFIED BY 'STRONG-PASS';
GRANT SELECT,INSERT,UPDATE,DELETE,CREATE,ALTER,INDEX ON certreg.* TO 'certreg_app'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

Після виконання міграцій варто прибрати ALTER/CREATE/INDEX якщо не планується частих змін схеми (мінімізація привілеїв).

Створити публічного (read-limited) користувача для status API (опціонально, посилює безпеку):
```sql
CREATE USER 'certro'@'localhost' IDENTIFIED BY 'ANOTHER-STRONG-PASS';
GRANT SELECT (cid,version,h,revoked_at,revoke_reason,valid_until,course,grade,issued_date) ON certreg.tokens TO 'certro'@'localhost';
GRANT UPDATE (lookup_count,last_lookup_at) ON certreg.tokens TO 'certro'@'localhost';
GRANT INSERT (cid,event_type) ON certreg.token_events TO 'certro'@'localhost';
FLUSH PRIVILEGES;
```
Додайте у `config.php` поля `db_public_user`, `db_public_pass` щоб `api/status.php` перейшов на цього користувача.

### 6. Міграції
Запустіть основну міграцію (створює таблиці `tokens`, `token_events`, `creds` якщо це закладено в скрипт):
```bash
php migrations/004_create_tokens_table.php
```
Перевірте вихід — має повідомити про створені таблиці.

(Опціонально) Прибрати legacy:
```bash
php migrations/005_drop_legacy.php --archive
```
Це збереже дамп старих таблиць (якщо реалізовано) та видалить їх.

### 7. Створення адміністратора
Згенеруйте парольний хеш у PHP CLI:
```bash
php -r "echo password_hash('SUPER-SECRET-PASS', PASSWORD_DEFAULT), PHP_EOL;"
```
Вставте запис:
```sql
INSERT INTO creds (username, passhash) VALUES ('admin', '<OUTPUT_HASH>');
```
При першому вході, якщо Argon2id доступний, система може автоматично зробити rehash (перевірте через self_check).

### 8. Nginx (мінімалістичний приклад)
Файл `/etc/nginx/sites-available/certreg.conf`:
```nginx
server {
	listen 80;
	server_name example.org;
	root /var/www/certreg;

	# Без індексації довільних PHP
	location = / { return 403; }

	# Статичні ресурси
	location /assets/ { try_files $uri =404; add_header Cache-Control "public, max-age=31536000"; }
	location /files/  { try_files $uri =404; }

	# API та дозволені entrypoints
	location ^~ /api/ { include snippets/fastcgi-php.conf; fastcgi_pass unix:/run/php/php-fpm.sock; }
	location = /verify.php      { include snippets/fastcgi-php.conf; fastcgi_pass unix:/run/php/php-fpm.sock; }
	location = /issue_token.php { include snippets/fastcgi-php.conf; fastcgi_pass unix:/run/php/php-fpm.sock; }
	location = /tokens.php      { include snippets/fastcgi-php.conf; fastcgi_pass unix:/run/php/php-fpm.sock; }
	location = /events.php      { include snippets/fastcgi-php.conf; fastcgi_pass unix:/run/php/php-fpm.sock; }
	location = /qr.php          { include snippets/fastcgi-php.conf; fastcgi_pass unix:/run/php/php-fpm.sock; }
	location ~ ^/(?!verify\.php|issue_token\.php|tokens\.php|events\.php|api/|qr\.php).+\.php$ { return 403; }

	# Rate limit (приклад 60 запитів / хвилину на статус)
	limit_req_zone $binary_remote_addr zone=stat:10m rate=60r/m;
	location = /api/status.php { limit_req zone=stat; include snippets/fastcgi-php.conf; fastcgi_pass unix:/run/php/php-fpm.sock; }

	# Security headers (розширюйте за потребою)
	add_header X-Frame-Options "DENY" always;
	add_header Referrer-Policy "no-referrer" always;
	add_header Permissions-Policy "geolocation=(),camera=(),microphone=()" always;
	add_header X-Content-Type-Options "nosniff" always;
	# При переході на повний відсутній inline JS → оновити CSP:
	add_header Content-Security-Policy "default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'none'" always;
}
```
Активуйте:
```bash
sudo ln -s /etc/nginx/sites-available/certreg.conf /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

### 9. Відключення логування чутливого параметра `p`
QR payload передається як base64url у `p`. Щоб не логувати повністю:
```nginx
map $request_uri $p_sanitized {
	~p= /verify.php?p=REDACTED;
	default $request_uri;
}
log_format main '$remote_addr - $remote_user [$time_local] "$request_method $p_sanitized $server_protocol" $status $body_bytes_sent "$http_referer" "$http_user_agent"';
access_log /var/log/nginx/access.log main;
```

### 10. HTTPS
Після перевірки HTTP-конфігурації:
```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d example.org --redirect --email admin@example.org --agree-tos --no-eff-email
```
Додайте (після кількох днів стабільності) HSTS:
```nginx
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains; preload" always;
```

### 11. Self-check
Запустіть:
```bash
php self_check.php
```
Виправте попередження (відсутні розширення, права, конфіг).

### 12. Перевірка видачі та валідації
1. Зайдіть як адмін → сторінка видачі
2. Згенеруйте сертифікат (перевірте що QR відкриває `verify.php?p=...`)
3. На сторінці верифікації введіть ПІБ → має показати «чинний» (якщо не встановлено expiry та не відкликано)
4. Спробуйте Revoke → перевірте оновлений статус
5. Створіть тест із `valid_until` вчора → має показати прострочений

### 13. Резервне копіювання
Створіть скрипт `backup.sh` (простий приклад):
```bash
#!/bin/bash
DATE=$(date +%F)
mysqldump --single-transaction certreg | gzip > /var/backups/certreg-$DATE.sql.gz
find /var/backups -name 'certreg-*.sql.gz' -mtime +14 -delete
```
Cron:
```bash
chmod +x /usr/local/sbin/backup_certreg.sh
sudo crontab -e
# 3:05 щодня
5 3 * * * /usr/local/sbin/backup_certreg.sh
```
> Для підвищеної безпеки зашифруйте (`age` або `gpg`) перед зберіганням поза сервером.

### 14. Оновлення
```bash
git pull --ff-only
php self_check.php
```
Перевірити: чи не зʼявились нові міграції. При потребі виконати.

### 15. Типові проблеми
| Симптом | Можлива причина | Рішення |
|---------|-----------------|----------|
| 403 на `/api/status.php` | Забули дозволити у nginx | Додайте location на endpoint | 
| QR не відкривається | Неправильний domain або блокування | Перевірити server_name / rewrite |
| «ORG mismatch» (якщо є) | Змінили `org_code` після early v2 | Відкотити org_code або перевипустити | 
| Відсутній Argon2id | PHP без Argon2 | Використати bcrypt cost≥12, оновити PHP image |

---
## Короткий цикл CI / smoke
Необовʼязково, але корисно мати mini smoke:
```bash
php -l api/status.php
php self_check.php
```
Перший виклик лінтить PHP, другий дає базову діагностику.

---
Цей розділ адаптований під модель «сервер з мінімальною логікою, клієнтська генерація HMAC». За потреби доповніть приватними внутрішніми процедурами (ротація паролів, інцидентний план).

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
