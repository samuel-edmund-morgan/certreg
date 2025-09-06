<!--
Оригінальний зміст README було замінено інструкцією розгортання згідно запиту користувача.
-->

# Інструкція з розгортання проєкту certreg

certreg – це простий PHP‑додаток для ведення реєстру, генерації JPEG‑сертифікатів з QR‑кодом та публічної перевірки дійсності сертифікатів. Даний гайд описує повний процес його розгортання на сервері Ubuntu 22.04 LTS (веб-стек: nginx + php-fpm + MySQL) з акцентом на безпеку та покроковими командами для новачків і досвідчених користувачів. Дотримуйтесь інструкцій послідовно – від встановлення залежностей до фінального запуску – щоб гарантовано налаштувати систему безпечно і правильно.

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
    hash  VARCHAR(64) DEFAULT NULL
);
```

Генерація хеша пароля адміністратора:
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
3. Перевірте hash через `/checkCert?hash=...`.

Типові проблеми:
* 502 – перевірити `fastcgi_pass`.
* 500 – перевірити шаблон/шрифт і memory_limit.
* Hash не знаходиться – перевірити URL та наявність запису; не змінювати salt.

Логи: `/var/log/nginx/access.log`, `/var/log/nginx/error.log`, логи PHP-FPM.

Готово: застосунок certreg розгорнутий із базовими заходами безпеки.



