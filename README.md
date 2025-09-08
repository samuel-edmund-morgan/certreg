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
1. Створіть БД і користувача.
2. Скопіюйте `config.php.example` у `config.php` та налаштуйте параметри.
3. Запустіть міграцію: `php migrations/004_create_tokens_table.php`.
4. (Необов'язково) Приберіть спадкові таблиці: `php migrations/005_drop_legacy.php --archive`.
5. Виставте whitelist Nginx на `issue_token.php`, `tokens.php`, `token.php`, `verify.php`, `qr.php` та `api/*.php`.
6. Додайте rate‑limit на `/api/status.php` за потреби.

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
