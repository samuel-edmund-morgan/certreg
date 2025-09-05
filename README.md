# certreg

## Призначення
Простий PHP‑застосунок для реєстрації та перевірки сертифікатів. Адміністратор вводить дані слухачів, генерує JPEG‑сертифікат із QR‑кодом, а отримувач може перевірити його дійсність за посиланням.

## Налаштування
1. **Створіть базу даних MySQL та таблиці.**
   ```sql
   CREATE DATABASE certreg CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
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
   Створіть адміністратора, згенерувавши хеш пароля:
   ```bash
   php -r "echo password_hash('your-pass', PASSWORD_DEFAULT);"
   ```
   ```sql
   INSERT INTO creds (username, passhash) VALUES ('admin', 'отриманий_хеш');
   ```
2. **Скопіюйте файл конфігурації.**
   ```bash
   cp config.php.example config.php
   ```
   Заповніть у `config.php` параметри підключення до БД, домен сайту та шляхи до шаблону і шрифту.
3. **Підготуйте файли.** В каталозі `files/` має бути шаблон `cert_template.jpg`, а в `files/certs/` – папка для збереження сертифікатів.

## Запуск
Потрібен PHP 7.4+ з розширеннями PDO MySQL та GD. Бібліотека PHP QR Code включена у директорію `lib/`.

Запустити локально можна командою:
```bash
php -S localhost:8000
```
Після цього відкрийте `http://localhost:8000` у браузері або розгорніть застосунок на будь‑якому веб‑сервері з підтримкою PHP.

## Приклади використання
- `/admin.php` – сторінка входу адміністратора, додавання записів та перегляд списку.
- `/generate_cert.php?id=1` – створення JPG‑сертифіката для запису з ID 1 та оновлення поля `hash` в БД.
- `/checkCert.php?hash=<значення>` – перевірка сертифіката за хешем; при валідному хеші відображаються дані слухача.

