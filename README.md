<!--
Оригінальний зміст README було замінено інструкцією розгортання згідно запиту користувача.
-->

# certreg (privacy-first версія)

Ця версія системи прибрала персональні дані (ПІБ) із сервера. Сертифікат видається локально в браузері адміністратора, а сервер зберігає тільки анонімний токен:

Поле | Зберігається на сервері | В QR | Візуально на сертифікаті
---- | ----------------------- | ---- | -----------------------
ПІБ  | ✖ (ніколи)              | ✖    | ✔ (намальовано локально)
course | ✔ | ✔ | ✔
grade  | ✔ | ✔ | ✔
date   | ✔ | ✔ | ✔
salt (s) | ✖ | ✔ | (імпліцитно в QR)
cid      | ✔ | ✔ | ✔ (за бажанням можна додати)
HMAC h   | ✔ | ✖ | (опційно фрагмент)

Перевірка працює так: QR → `verify.php?p=...` → сторінка зчитує JSON (v,cid,s,course,grade,date) → запитує `/api/status?cid=` щоб отримати `h` та `revoked` → користувач вводить ПІБ → HMAC(s, canonical) порівнюється з серверним h.

Canonical рядок (v1): `v1|NAME|COURSE|GRADE|DATE` де NAME нормалізований (NFC, без апострофів, collapse spaces, UPPERCASE).

## Розгортання (скорочено)
1. Клонування коду, налаштування `config.php`.
2. Міграція таблиці `tokens` (див. `migrations/004_create_tokens_table.php`). Legacy таблиця `data` та повʼязані файли видалені.
3. Створення адміністратора в `creds`.
4. Nginx: відкрийте лише `issue_token.php`, `tokens.php`, `verify.php`, `api/*.php`, `qr.php`. Забороніть доступ до інших .php (whitelist підхід).

Приклад спрощеного блоку nginx (HTTPS сервер опущено для стислості):
```nginx
location = / { return 403; }
location = /verify.php { include snippets/fastcgi-php.conf; fastcgi_pass unix:/run/php/php8.3-fpm.sock; }
location ^~ /api/ { include snippets/fastcgi-php.conf; fastcgi_pass unix:/run/php/php8.3-fpm.sock; }
location = /issue_token.php { include snippets/fastcgi-php.conf; fastcgi_pass unix:/run/php/php8.3-fpm.sock; }
location = /tokens.php { include snippets/fastcgi-php-conf; fastcgi_pass unix:/run/php/php8.3-fpm.sock; }
location = /qr.php { include snippets/fastcgi-php.conf; fastcgi_pass unix:/run/php/php8.3-fpm.sock; }
location ~ ^/(?!verify\.php|issue_token\.php|tokens\.php|api/|qr\.php).+\.php$ { return 403; }
```

## Видача
- Форма `issue_token.php` генерує сіль, рахує HMAC та реєструє токен через `/api/register`.
- ПІБ не передається.
- Зображення сертифіката (canvas → JPG) з QR та ПІБ зберігається локально користувачем.

## Перевірка
- Скан QR → `verify.php?p=...`.
- Введення ПІБ користувачем → локальний HMAC → порівняння з серверним `h`.
- Якщо відкликано — повідомлення + причина.

## Відкликання / Відновлення
Через `tokens.php` (кнопки «Відкликати» / «Відновити»), API: `/api/revoke.php`, `/api/unrevoke.php`.

## Роудмап (далі можна додати)
- template_version у canonical.
- issuer / valid_until.
- Масова видача (CSV).
- PDF експорт.
- Легкий audit log (агрегація спроб перевірки без IP або з хешованим IP).

## Безпека
- Немає ПІБ у базі — витік дає лише анонімні токени.
- Відсутність `h` у QR унеможливлює офлайн підробку без звернення до сервера.
- Можна додати rate-limit на `/api/status`.

## Очистка legacy
Legacy файли (generate_cert.php, checkCert.php, admin data CRUD) видалені. Історія доступна у Git. Якщо потрібен rollback — checkout попередній commit.

## License
MIT (за потреби додайте свій текст).

## 1. Встановлення залежностей
Почнемо з установки необхідного серверного ПЗ та залежностей. Виконайте оновлення списку пакетів і встановіть веб-сервер nginx, PHP (версії 8.3 з потрібними модулями), СУБД MySQL/MariaDB та Git:
```bash
sudo apt update
sudo apt install -y nginx php8.3-fpm php8.3-gd php8.3-mbstring php8.3-xml php8.3-mysql mysql-server git
sudo phpenmod gd mbstring
sudo systemctl restart php8.3-fpm
```
Примітка: Даний гайд використовує PHP 8.3. Переконайтеся, що всі пакети PHP-модулів встановлені відповідно до вибраної версії PHP (якщо, наприклад, використовуєте PHP 8.2, встановіть пакети php8.2-gd тощо).
Для підтримки TLS і автоматичної конфігурації nginx через Let’s Encrypt встановимо Certbot та його nginx-плагін (знадобиться на етапі налаштування HTTPS):
```bash
sudo apt install -y certbot python3-certbot-nginx
```

## 2. Налаштування MySQL
На цьому кроці забезпечимо базову безпеку MySQL та підготуємо базу даних для програми.

Початкове налаштування безпеки MySQL:
```bash
sudo mysql_secure_installation
```

Створення бази даних і користувача:
```bash
sudo mysql
```
```sql
CREATE DATABASE certreg CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'certreg_user'@'localhost' IDENTIFIED BY 'strong-password-here';
GRANT ALL PRIVILEGES ON certreg.* TO 'certreg_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```
Примітка: Замініть 'strong-password-here' на надійний пароль.

Створення таблиць та адміністратора:
```sql
USE certreg;

CREATE TABLE creds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NOT NULL UNIQUE,
    passhash VARCHAR(255) NOT NULL
);

CREATE TABLE data (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name  VARCHAR(255) NOT NULL,
    score VARCHAR(255) NOT NULL,
    course VARCHAR(255) NOT NULL,
    date  VARCHAR(50)  NOT NULL,
    hash  VARCHAR(64) DEFAULT NULL,
    hash_version TINYINT UNSIGNED NOT NULL DEFAULT 1,
    revoked_at DATETIME NULL,
    revoke_reason VARCHAR(255) NULL
);
-- (Опціонально, але РЕКОМЕНДОВАНО) Забезпечити унікальність hash, щоби не допустити дубльованих сертифікатів з однаковим набором полів:
ALTER TABLE data ADD UNIQUE KEY uq_data_hash (hash);
```

Генерація хеша пароля адміністратора:

### Підвищення прав існуючого користувача (якщо потрібно додати індекс пізніше)
Якщо ви вже створили користувача (наприклад `certuser`) з обмеженими правами і отримуєте помилки `ALTER command denied` або `DROP command denied` під час додавання унікального індексу чи використання `TRUNCATE`, зайдіть під root і виконайте:
```sql
GRANT ALTER, INDEX, DROP, CREATE ON certreg.* TO 'certuser'@'localhost';
FLUSH PRIVILEGES;
```
Після цього можна створити унікальний індекс (якщо ще не створений):
```sql
ALTER TABLE data ADD UNIQUE KEY uq_data_hash (hash);
```
`TRUNCATE TABLE data;` стане доступним (воно вимагає права DROP). Якщо не хочете давати DROP, використовуйте `DELETE FROM data;` для очистки.

### Версіонування хешу (hash_version)
Починаючи з пункту 1 дорожньої карти ми додали поле `hash_version` та ключ `hash_version` у `config.php`.

Поточне значення 1 означає canonical рядок: `name|score|course|date`.

У майбутній версії (2) планується розширення (наприклад: `v2|id=<id>|name=<...>|score=<...>|course=<...>|date=<...>|issuer=<...>|valid_until=<...>`). Перевірка у `checkCert.php` читає `hash_version` і відтворює відповідний canonical string, тому старі сертифікати лишаються валідними.

Оновлення: для додавання стовпця окремо (якщо він відсутній) можна виконати:
```sql
ALTER TABLE data ADD COLUMN hash_version TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER hash;
```
Конфіг: у `config.php` параметр `hash_version` контролює, яку версію формує `generate_cert.php`.

### Відкликання сертифікатів (revocation)
Додано стовпці `revoked_at`, `revoke_reason`. В адмін-панелі з'являються кнопки «Відкликати» (з обов'язковою причиною) та «Відновити». Після відкликання:
* У публічній перевірці сертифікат показує статус «Сертифікат відкликано» та причину.
* Хеш залишається в БД для аудиту, але вважається недійсним.

Міграція (окремо, якщо оновлюєте):
```sql
ALTER TABLE data
    ADD COLUMN revoked_at DATETIME NULL AFTER hash_version,
    ADD COLUMN revoke_reason VARCHAR(255) NULL AFTER revoked_at;
```

### Логи перевірок (verification_logs)
Створюється таблиця `verification_logs` для аудиту звернень до `/checkCert`.
Поля: requested_id, requested_hash, data_id (фактичний знайдений id або NULL), success (0/1), status (наприклад: ok, revoked, not_found, bad_id, bad_hash), revoked (0/1), remote_ip, user_agent, created_at.
Міграція:
```sql
CREATE TABLE verification_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    requested_id INT NULL,
    requested_hash CHAR(64) NULL,
    data_id INT NULL,
    success TINYINT(1) NOT NULL,
    status VARCHAR(32) NOT NULL,
    revoked TINYINT(1) NOT NULL DEFAULT 0,
    remote_ip VARCHAR(45) NOT NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created_at (created_at),
    INDEX idx_hash (requested_hash),
    INDEX idx_data (data_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```
Перегляд: сторінка `/logs.php` (доступна лише адміністратору) з фільтром та пагінацією.
Примітка: для створення цієї таблиці користувачу потрібне право CREATE (додано в розширений GRANT вище). Якщо міграція впала з помилкою типу `CREATE command denied`, дайте право:
```sql
GRANT CREATE ON certreg.* TO 'certuser'@'localhost';
FLUSH PRIVILEGES;
```
```bash
php -r "echo password_hash('your-strong-admin-pass', PASSWORD_DEFAULT), PHP_EOL;"
```
```sql
INSERT INTO creds (username, passhash) VALUES ('admin', 'вставте_сюди_згенерований_хеш');
```
Примітка: Логін можете змінити.

## 3. Клонування і конфігурація
```bash
cd /var/www
sudo git clone https://github.com/samuel-edmund-morgan/certreg.git
cd certreg
```
```bash
sudo cp config.php.example config.php
sudo nano config.php
```
Налаштуйте в config.php:
* DB доступ (db_host, db_name, db_user, db_pass)
* Домен (site_domain)
* Секретну сіль (hash_salt)
* Шаблон і шрифт (template_path, font_path)

Генерація солі:
```bash
openssl rand -hex 32
```
Переконайтесь у наявності `files/cert_template.jpg` та `fonts/Montserrat-Light.ttf`.

## 4. Права доступу
Код лише для читання веб-сервером:
```bash
sudo chown -R root:root /var/www/certreg
sudo find /var/www/certreg -type d -exec chmod 755 {} +
sudo find /var/www/certreg -type f -exec chmod 644 {} +
```
Каталог для сертифікатів (запис веб-сервером):
```bash
sudo chown -R www-data:www-data /var/www/certreg/files/certs
sudo chmod 755 /var/www/certreg/files /var/www/certreg/files/certs
```
Захист `config.php`:
```bash
sudo chown root:www-data /var/www/certreg/config.php
sudo chmod 640 /var/www/certreg/config.php
```

## 5. Налаштування nginx
`/etc/nginx/sites-available/certreg.conf`:
```nginx
server {
    server_name certificates.example.com;  # ваш домен
    root /var/www/certreg;
    index index.php;

    client_max_body_size 10m;
    add_header X-Frame-Options SAMEORIGIN always;
    add_header X-Content-Type-Options nosniff always;
    add_header Referrer-Policy no-referrer-when-downgrade always;

    location = / { return 403; }

    location = /checkCert { rewrite ^ /checkCert.php last; }

    location = /admin.php {
        allow 94.45.140.194;
        allow 94.45.140.195;
        allow 203.0.113.5;
        deny all;
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    location = /delete_record.php {
        allow 94.45.140.194;
        allow 94.45.140.195;
        allow 203.0.113.5;
        deny all;
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    location ~* \.(?:css|js|png|jpg|jpeg|gif|svg|ico|ttf|woff2?)$ {
        expires 7d;
        access_log off;
        add_header Cache-Control "public";
    }

    listen 80;
    listen [::]:80;
}
```
Активація:
```bash
sudo ln -s /etc/nginx/sites-available/certreg.conf /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

## 6. TLS (Certbot)
```bash
sudo certbot --nginx -d certificates.example.com -m admin@example.com --agree-tos --redirect
```
HSTS (в HTTPS-блоці):
```nginx
add_header Strict-Transport-Security "max-age=63072000; includeSubDomains; preload" always;
```

## 7. Безпека PHP
```ini
date.timezone = Europe/Kyiv
expose_php = Off
session.use_strict_mode = 1
session.cookie_httponly = 1
session.cookie_samesite = Lax
memory_limit = 256M
opcache.enable = 1
opcache.validate_timestamps = 1
opcache.max_accelerated_files = 10000
```
```bash
sudo systemctl restart php8.3-fpm
```

## 8. Обфускація адмін-панелі
Перейменування:
```bash
sudo mv /var/www/certreg/admin.php /var/www/certreg/admin_portal.php
```
Basic Auth:
```bash
sudo apt install -y apache2-utils
sudo htpasswd -c /etc/nginx/.htpasswd_certreg admin_portal_user
```
Прихований шлях:
```nginx
location = /butterfly {
    allow 203.0.113.5;
    deny all;
    include snippets/fastcgi-php.conf;
    fastcgi_param SCRIPT_FILENAME /var/www/certreg/admin_portal.php;
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    auth_basic "Restricted Area";
    auth_basic_user_file /etc/nginx/.htpasswd_certreg;
}
```
Блок службових викликів:
```nginx
location = /generate_cert.php { return 404; }
```

## 9. Захист SSH та HTTP
SSH (`/etc/ssh/sshd_config`):
```
PermitRootLogin no
PasswordAuthentication no
KbdInteractiveAuthentication no
ChallengeResponseAuthentication no
PubkeyAuthentication yes
AllowUsers youradminuser
```
Перезапуск:
```bash
sudo sshd -t && sudo systemctl restart ssh
```
Ключ:
```bash
ssh-keygen -t ed25519 -a 100 -f ~/.ssh/id_certreg -C "admin@certreg"
```
Fail2Ban:
```bash
sudo apt install -y fail2ban
```
UFW:
```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'
sudo ufw --force enable
sudo ufw status
```
Звуження SSH (опц.):
```bash
sudo ufw allow from 203.0.113.0/24 to any port 22 proto tcp
sudo ufw delete allow OpenSSH
```
Обмеження інших PHP:
```nginx
location ~ ^/(?!checkCert$).*\.php$ { return 403; }
```
Rate limit:
```nginx
limit_req_zone $binary_remote_addr zone=chkcert:10m rate=30r/m;
# у location = /checkCert додати:
limit_req zone=chkcert burst=10 nodelay;
```

### Повний приклад фінального `/etc/nginx/sites-available/certreg.conf`
Нижче зведений кінцевий варіант файлу (після усіх кроків 5–9). Замініть `certificates.example.com` та IP-адреси на свої. `limit_req_zone` можна залишити нагорі файлу (в Ubuntu/Debian ці файли інклудяться всередині `http{}` і директива валідна).
```nginx
# Ліміт запитів (зона — 30 запитів за хвилину на IP)
limit_req_zone $binary_remote_addr zone=chkcert:10m rate=30r/m;

# HTTP -> HTTPS редирект
server {
    server_name certificates.example.com;
    listen 80;
    listen [::]:80;
    return 301 https://$host$request_uri;
}

# Основний HTTPS сервер
server {
    server_name certificates.example.com;

    root /var/www/certreg;
    index index.php;

    # Безпека та політики
    client_max_body_size 10m;
    add_header X-Frame-Options SAMEORIGIN always;
    add_header X-Content-Type-Options nosniff always;
    add_header Referrer-Policy no-referrer-when-downgrade always;
    add_header Strict-Transport-Security "max-age=63072000; includeSubDomains; preload" always;

    # Забороняємо корінь
    location = / { return 403; }

    # Публічна перевірка сертифіката + rate limit
    location = /checkCert {
        rewrite ^ /checkCert.php last;
        limit_req zone=chkcert burst=10 nodelay;
    }

    # Прихований шлях до адмін-панелі (файл admin_portal.php)
    location = /butterfly {
        allow 203.0.113.5;   # <-- ваш(і) довірений(ні) IP / мережа
        deny all;
        include snippets/fastcgi-php.conf;
        fastcgi_param SCRIPT_FILENAME /var/www/certreg/admin_portal.php;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        auth_basic "Restricted Area";
        auth_basic_user_file /etc/nginx/.htpasswd_certreg;
    }

    # Видалення запису (захищено так само; можна прибрати якщо інтегровано в панель)
    location = /delete_record.php {
        allow 203.0.113.5;
        deny all;
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        auth_basic "Restricted Area";
        auth_basic_user_file /etc/nginx/.htpasswd_certreg;
    }

    # Забороняємо прямий виклик генератора
    location = /generate_cert.php { return 404; }

    # Забороняємо ВСІ інші .php окрім явно дозволених (/checkCert через rewrite і /butterfly через окрему локацію)
    location ~ ^/(?!checkCert$).*\.php$ { return 403; }

    # Загальний PHP (спрацює для checkCert.php після rewrite)
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    # Статика
    location ~* \.(?:css|js|png|jpg|jpeg|gif|svg|ico|ttf|woff2?)$ {
        expires 7d;
        access_log off;
        add_header Cache-Control "public";
    }

    listen 443 ssl http2;
    listen [::]:443 ssl http2;

    # (Шляхи додасть certbot — наведіть тут після випуску сертифікату)
    ssl_certificate /etc/letsencrypt/live/certificates.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/certificates.example.com/privkey.pem;
    include /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem;
}
```
> Після змін: `sudo nginx -t && sudo systemctl reload nginx`

## 10. Перевірка і запуск
1. Зайдіть в адмінку (прихований шлях) через HTTPS + Basic Auth.
2. Створіть запис і згенеруйте сертифікат.
3. Перевірте через `/checkCert?id=<ID>&hash=<HASH>` (обидва параметри обов'язкові).

Типові проблеми:
* 502 – перевірити `fastcgi_pass`.
* 500 – перевірити шаблон/шрифт і memory_limit.
* Hash не знаходиться – перевірити URL та наявність запису; не змінювати salt.

Логи: `/var/log/nginx/access.log`, `/var/log/nginx/error.log`, логи PHP-FPM.

Готово: застосунок certreg розгорнутий із базовими заходами безпеки.



