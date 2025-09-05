# certreg — реєстр і перевірка сертифікатів

Простий PHP‑застосунок для ведення реєстру, генерації JPEG‑сертифікатів із QR‑кодом та публічної перевірки дійсності сертифіката за детермінованим HMAC‑хешем.

Протестовано на Ubuntu 22.04 LTS (nginx + php-fpm + MySQL).

## Що всередині
- PHP (PDO MySQL, GD з FreeType, сесії), без фреймворків
- Генерація QR (вбудована бібліотека `lib/phpqrcode.php`)
- Шаблон сертифіката: `files/cert_template.jpg`
- Збереження згенерованих JPG у `files/certs/`
- Шрифт для TTF‑рендерингу: `fonts/Montserrat-Light.ttf` (налаштовується у `config.php`)

## Вимоги і пакети

На чистій Ubuntu 22.04 встановіть веб‑стек і залежності. Нижче наведені команди, якими користувалися (зверніть увагу на версії):

```bash
sudo apt update
sudo apt install -y nginx php8.3-fpm php8.3-gd php8.3-mbstring php8.3-xml php8.3-mysql mysql-server git
sudo phpenmod gd mbstring
sudo systemctl restart php8.3-fpm
```

Увага щодо версій PHP: бажано, щоб версія `php-fpm` та модулів збігалася. Якщо ви обираєте PHP 8.3, встановіть відповідні модулі саме для 8.3:

```bash
sudo apt install -y php8.3-gd php8.3-mbstring php8.3-xml php8.3-mysql
sudo phpenmod gd mbstring
sudo systemctl restart php8.3-fpm
```

Додатково для TLS і автоматичної конфігурації nginx:

```bash
sudo apt install -y certbot python3-certbot-nginx
```

## Брандмауер (UFW)

```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'   # відкриє 80 та 443
sudo ufw --force enable
sudo ufw status
```

## MySQL: базова безпека та БД

1) Початкова конфігурація безпеки (працює і для MySQL, і для MariaDB):

```bash
sudo mysql_secure_installation
```

2) Створення БД і користувача (приклад):

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

3) Створення потрібних таблиць і адміністратора:

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

Згенеруйте хеш пароля й додайте адміністратора:

```bash
php -r "echo password_hash('your-strong-admin-pass', PASSWORD_DEFAULT), PHP_EOL;"
```

```sql
INSERT INTO creds (username, passhash) VALUES ('admin', 'вставте_згенерований_хеш');
```

## Розгортання з Git

```bash
cd /var/www
sudo git clone https://github.com/samuel-edmund-morgan/certreg.git
cd certreg
```

Скопіюйте й заповніть конфіг:

```bash
sudo cp config.php.example config.php
sudo nano config.php
```

Укажіть:
- параметри підключення до БД (`db_host`, `db_name`, `db_user`, `db_pass`)
- домен сайту (`site_domain`, наприклад `certs.nasbu.edu.ua`)
- секретну сіль для HMAC (`hash_salt`)
- шляхи до шаблону, шрифта та директорії для сертифікатів

Корисні утиліти:

```bash
# випадкова сіль (32 байти у hex)
openssl rand -hex 32
```

Переконайтеся, що є файл шаблону та шрифти:
- `files/cert_template.jpg` — макет сертифіката
- `fonts/Montserrat-Light.ttf` або змініть `font_path` у `config.php`

## Права доступу та власники

- Весь застосунок: читання сервером, змінювати — лише адміністратору сервера.
  Рекомендація: власник `root:root` (або ваш deploy‑користувач), права `755` для директорій і `644` для файлів.

```bash
sudo chown -R root:root /var/www/certreg
sudo find /var/www/certreg -type d -exec chmod 755 {} +
sudo find /var/www/certreg -type f -exec chmod 644 {} +
```

- Директорія для збереження сертифікатів має бути записуваною PHP (fpm під користувачем `www-data` на Ubuntu):

```bash
sudo chown -R www-data:www-data /var/www/certreg/files/certs
sudo chmod 755 /var/www/certreg/files /var/www/certreg/files/certs
```

Такі права дозволять PHP створювати файли, а nginx — віддавати їх як статику. Інші каталоги — тільки для читання веб‑сервером.

## PHP (php.ini) — можливі налаштування

Редагуйте `/etc/php/8.3/fpm/php.ini` (шлях може відрізнятися, якщо інша версія):

Рекомендовано:

```
; Часова зона (щоб уникнути попереджень date())
date.timezone = Europe/Kyiv

; Безпека та продуктивність
expose_php = Off
session.use_strict_mode = 1
session.cookie_httponly = 1
session.cookie_samesite = Lax

; Генерація зображень може потребувати більше пам'яті залежно від розміру шаблону
memory_limit = 256M

; Opcache (зазвичай налаштовується у /etc/php/8.3/fpm/conf.d/10-opcache.ini)
opcache.enable=1
opcache.validate_timestamps=1
opcache.max_accelerated_files=10000
```

Після змін перезапустіть FPM:

```bash
sudo systemctl restart php8.3-fpm
```

## Nginx: віртуальний хост (до запуску certbot)

Створіть файл `/etc/nginx/sites-available/certreg.conf` із базовою (не‑SSL) конфігурацією. Certbot згенерує сертифікати і автоматично оновить цей файл під HTTPS.

```nginx
server {
    server_name certs.nasbu.edu.ua;  # змініть на ваш домен

    root /var/www/certreg;
    index index.php;

    # Загальні обмеження/заголовки
    client_max_body_size 10m;
    add_header X-Frame-Options SAMEORIGIN always;
    add_header X-Content-Type-Options nosniff always;
    add_header Referrer-Policy no-referrer-when-downgrade always;

    # 1) Корінь сайту – 403
    location = / { return 403; }

    # 2) Публічна перевірка сертифіката
    location = /checkCert { rewrite ^ /checkCert.php last; }

    # 3) Адмінка — обмежити за IP (замість прикладів підставте дозволені IP)
    location = /admin.php {
        allow 94.45.140.194;
        allow 94.45.140.195;
        allow 94.45.140.196;
        allow 94.45.140.197;
        allow 94.45.140.198;
        allow 195.137.202.34;
        allow 94.158.95.219;
        deny all;
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    location = /delete_record.php {
        allow 94.45.140.194;
        allow 94.45.140.195;
        allow 94.45.140.196;
        allow 94.45.140.197;
        allow 94.45.140.198;
        allow 195.137.202.34;
        allow 94.158.95.219;
        deny all;
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    # Інші PHP-файли — відкриті (доступ обмежується в самому застосунку, напр. /login.php)
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

    listen 80;
    listen [::]:80;
}
```

Активуйте сайт і перевірте конфігурацію:

```bash
sudo ln -s /etc/nginx/sites-available/certreg.conf /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

## Отримання TLS сертифікатів (Certbot)

```bash
sudo certbot --nginx -d your.domain.name.here -m your-email@example.com --agree-tos --redirect
```

Certbot оновить vhost на HTTPS (додасть `listen 443 ssl`, шляхи до сертифікатів і редірект з 80 → 443). Автоподовження працює через таймер systemd: `systemctl list-timers | grep certbot`.

## Перевірка роботи

- Відкрийте `https://<ваш-домен>/admin.php` з дозволеної IP‑адреси — форма входу
- Після входу додайте запис → згенеруйте сертифікат → посилання на скачування зʼявиться і на сторінці перевірки
- Відкрийте `https://<ваш-домен>/checkCert?hash=...` — публічна перевірка

## Корисні нотатки

- У конфігурації nginx для PHP вказано сокет `php8.3-fpm`. Якщо використовуєте іншу версію — змініть шлях (`/run/php/phpX.Y-fpm.sock`).
- Якщо шаблон або шрифт відсутні, генератор поверне помилку 500. Перевірте `template_path` і `font_path` у `config.php`.
- Хеш формується через `hash_hmac('sha256', ...)` з сіллю з `config.php` — збережіть сіль у таємниці.
- Для додаткового харденінгу можна закрити виконання PHP поза коренем додатку та віддавати статику з окремого локації.

## Швидкий локальний запуск (тільки для розробки)

```bash
php -S localhost:8000
```

Далі відкрийте `http://localhost:8000` у браузері.


