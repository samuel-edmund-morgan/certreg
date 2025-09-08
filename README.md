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
1. Встановіть залежності:
   ```bash
   sudo apt update && sudo apt install nginx php-fpm php-mysql php-gd php-mbstring mysql-server git
   ```
2. Створіть БД та користувача в MySQL (SQL приклад у `config.php.example`).
3. Клонуйте репозиторій у `/var/www/certreg` та виставте права власності на `www-data`.
4. Скопіюйте `config.php.example` у `config.php` й заповніть `db_*`, `site_name`, `logo_path`, `org_code` тощо.
5. Запустіть міграції `php migrations/004_create_tokens_table.php` та, за потреби, `php migrations/005_drop_legacy.php --archive`.
6. Сконфігуруйте Nginx: скопіюйте `certreg.conf.example` у `/etc/nginx/sites-available/certreg`, відредагуйте `server_name`, увімкніть сайт (`ln -s` у `sites-enabled`), перевірте `nginx -t` і перезапустіть `sudo systemctl reload nginx`.
7. Переконайтеся, що `php-fpm` працює (`sudo systemctl enable --now php8.1-fpm`) і сокет збігається з конфігурацією.
8. Запустіть `php self_check.php` або відкрийте `https://<домен>/verify.php` для перевірки.
9. (Опційно) Увімкніть `limit_req` для `/api/status.php`.
10. (Опційно) Зробіть резервні копії конфігів.
11. (Опційно) Використовуйте HTTPS через certbot.

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
