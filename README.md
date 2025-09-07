GET  /api/events.php?cid=CID&limit=50 -> аудит подій (revoke/unrevoke/delete)
| Audit trail подій (revoke/unrevoke/delete) зі збереженням старих значень.

1. Додати nginx rate limiting (`limit_req_zone $binary_remote_addr zone=certreg:10m rate=60r/m;`).
2. Налаштувати HSTS, OCSP stapling, регулярний автоматичний renew TLS.
3. Створити користувача `certreg_app` з SELECT/INSERT/UPDATE; заборонити DDL у runtime.
4. Аргонізація паролів: перевірити `PASSWORD_DEFAULT` – якщо bcrypt, оцінити cost >= 12; якщо доступний Argon2id, задати через `password_hash(..., PASSWORD_ARGON2ID, [...])`.
5. Заборонити логування параметра `p` (map_regex у nginx або custom `log_format` без `$request_uri`).
6. Документ «Положення про сервіс» – модель даних, відсутність персональних даних, класифікація ризиків.
7. Пілотний аудит: спроба підробки (підміна QR) і перевірка виявлення через невідповідність INT.
8. Розширити self-check (перевірка CSP, версія PHP, відсутність debug-файлів).
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

Canonical рядок (v1): `v1|NAME|COURSE|GRADE|DATE` де NAME нормалізований (див. розділ «Канонічний рядок і нормалізація»).

## Канонічний рядок і нормалізація (v1)
Цей розділ формалізує те, що робить клієнт під час видачі та перевірки.

### Поля
v (number) – версія формату (поточна 1)
NAME – нормалізоване ПІБ (НЕ зберігається на сервері)
COURSE – як введено (trim) без додаткової нормалізації окрім обрізання пробілів по краях
GRADE – як введено (trim)
DATE – ISO `YYYY-MM-DD` (браузерна value з type=date)

### Алгоритм нормалізації NAME (v1)
1. Вхід: сирий рядок, який ввів оператор (приблизно «Прізвище Ім'я По-батькові»)
2. Unicode NFC: `s.normalize('NFC')`
3. Видалення апострофів / схожих символів: усуваємо `['\u2019', "'", '`', '’', '\u02BC']`
4. Згортання пробілів: заміна усіх блоків пробілів на один пробіл (RegExp `/\s+/g -> ' '`)
5. Обрізання країв: `trim()`
6. Перетворення у верхній регістр (Unicode-aware у JS): `toUpperCase()`
7. Перевірка на ризик гомогліфів (поточний мінімальний набір): якщо одночасно зустрічається кирилиця й латинські символи `T O C` (`TOCtoc`) — блокувати (показати alert). Це превентивний захист від підміни латинських літер у кириличному тексті.

Результат кроку (7) використовується як NAME у canonical string. Жодна проміжна форма не надсилається на сервер.

### Формування canonical string (v1)
Шаблон: `v1|<NAME>|<COURSE>|<GRADE>|<DATE>`

### Криптографічні кроки (клієнт)
1. Генеруємо 32 байти випадкової солі: `crypto.getRandomValues(new Uint8Array(32))`
2. HMAC-SHA256 (key = salt, message = canonical UTF-8)
3. Отримуємо hex-представлення підпису (h)
4. Генеруємо CID (формат `C<timestamp_base36>-<4hex>`)
5. Відправляємо на сервер JSON: `{cid, v:1, h, course, grade, date}`
6. Формуємо QR payload: `{v,cid,s,course,grade,date}` де `s` = base64url(salt)
7. Payload JSON кодуємо в base64url і додаємо як параметр `p` у `/verify.php?p=...`

### Integrity short code (INT)
На сертифікат і на сторінку перевірки додано короткий код INT = перші 10 hex символів H (формат 5-5, напр. `A1B2C-D3E4F`). Це зручний візуальний маркер:
* швидке звіряння «зображення ↔ дані відповіді сервера» (виявлення підміни QR);
* усне підтвердження по фото/скріншоту; 
* не розкриває ПІБ і майже не дає шансів на випадкову колізію (40 біт). Повний H все одно використовується для криптографічної перевірки.

### Перевірка (браузер користувача)
1. Розпаковує JSON з параметра `p`
2. Витягає `v,cid,s,course,grade,date`
3. Запитує `/api/status.php?cid=...` щоб отримати `{h, revoked_at, revoke_reason}` (без ПІБ)
4. Користувач вводить своє ПІБ → нормалізація так само, як при видачі
5. Реконструюється canonical string і рахується HMAC(salt, canonical)
6. Порівняння отриманого hex з серверним h (строге)
7. Якщо співпадає і не відкликано — сертифікат чинний

### Причини саме такого дизайну
* Zero-PII на сервері – компрометація БД не розкриває імена
* salt у QR не дає змогу підібрати ПІБ (бо простір імен великий, а значення HMAC недоступне без звернення до сервера)
* Локальна нормалізація + одна версія формату запобігають розсинхрону між клієнтами
* В майбутньому можна додати `template_version`, `issuer`, `expires_at` – тоді буде v2

### Майбутнє (v2 – план)
Формат (можливий приклад):
`v2|NAME|COURSE|GRADE|DATE|ISSUER|EXPIRES|TEMPLATE_VER`
Правила нормалізації NAME лишаються стабільними (беккомпат). Можна розширити детекцію гомогліфів (додати A, E, K, M, H, O, P, C, T, X, Y, B). Для цього варіанта НЕ потрібно міняти v1 сертифікати – просто нові випуски позначені `v:2`.

### Мінімальна специфікація для незалежної імплементації
Input: (NAME_raw, COURSE, GRADE, DATE, SALT[32])
Output: h = HMAC_SHA256(SALT, canonical_v1(NAME_norm, COURSE, GRADE, DATE)) (hex 64 chars)
Failure modes: mismatched h, revoked, malformed payload, unsupported version

Edge cases покриті нормалізацією: надлишкові пробіли, різні види апострофів, змішаний регістр. Не покриває: подвійні пробіли всередині після очистки (бо згортаються), невидимі керівні символи (поки що — опціонально можна додати фільтр на `\p{C}`).

---

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
- Розширена детекція гомогліфів (латиниця ↔ кирилиця для A,E,K,M,H,O,P,C,T,X,Y,B).
- CSP без inline-скриптів (поточний перехідний nonce-режим).
- Security headers завершити на рівні nginx (Strict-Transport-Security, ACME challenge isolation).
- Rate-limit `/api/status.php` (nginx `limit_req`).
- Read-only MySQL користувач для SELECT + окремий для write (мін-привілеї).
- Внутрішній документ «Положення про сервіс (класифікація як не-ДІР)».
- Audit trail подій (revoke/unrevoke/delete) зі збереженням старих значень.
- Підтримка PDF експорту (serverless, генерується у браузері).
- Переведення пароля адміна на Argon2id (якщо доступний у PHP build).

## Харднінг (практичні кроки)
Нижче – концентрований чекліст посилення безпеки. Частина вже активована в коді (позначено ✓), решта виконується на рівні інфраструктури.

### Веб-рівень
| Контроль | Статус | Деталі |
|----------|--------|--------|
| Whitelist PHP entrypoints | ✓ | Рекомендовано у прикладі nginx (дозволені лише потрібні файли) |
| Content-Security-Policy з nonce | ✓ | Додається в `header.php`; inline-скрипти мають `nonce-...` (перехідний етап) |
| Відмова від inline JS повністю | ▶ | Винести логіку з `verify.php`, `token.php`, `tokens.php`, `issue_token.php` у окремі *.js файли |
| X-Frame-Options / frame-ancestors | ✓ | У CSP + заголовок `X-Frame-Options: DENY` |
| Referrer-Policy | ✓ | `no-referrer` |
| Permissions-Policy | ✓ | Відключено непотрібні можливості |
| HSTS | ▶ | Налаштувати в nginx після доступності постійного HTTPS (рік + preload) |
| Rate limiting `/api/status.php` | ▶ | `limit_req_zone` у nginx (наприклад 60req/м індивідуально) |
| Access log privacy | ▶ | Маскувати або не логувати параметр `p` (QR payload) |
| gzip / brotli | ▶ | Включити компресію (статично або `gzip on; brotli on;`) |

### PHP / додаток
| Контроль | Статус | Деталі |
|----------|--------|--------|
| Session cookie secure & httponly | ✓ | У `auth.php` – умови для HTTPS, SameSite=Lax |
| Session fixation захист | ✓ | `session_regenerate_id()` при логіні |
| CSRF для POST-дій адміна | ✓ | `require_csrf()` у відповідних endpoint-ах |
| Валідація revocation reason | ✓ | Сервер + клієнт; мін довжина + символ класу | 
| Escape output (HTML entities) | ✓ | Використання `htmlspecialchars` у списках і деталях |
| Паролі bcrypt/Argon2id | ▶ | Перевірити що `password_hash` використовує сучасний алгоритм; при наявності Argon2id міграція |
| Окремий користувач БД з мін-привілеями | ▶ | Створити користувача лише з SELECT,INSERT,UPDATE для `tokens` |
| Валідація вхідних параметрів API | ✓ | Trim + типізація + обмеження полів |
| Логування виключено для PII | ✓ | PII не існує; перевірити що web-сервер не зберігає параметр `p` |
| Відсутність eval / dynamic include | ✓ | Не використовується |
| UTF-8 нормалізація імен | ✓ | Реалізовано на клієнті (описано у README) |

### MySQL
| Контроль | Статус | Деталі |
|----------|--------|--------|
| `sql_mode` строгий | ▶ | Включити `STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION` |
| Мінімальні привілеї app user | ▶ | REVOKE ALTER/DROP/CREATE після міграцій |
| Регулярні бекапи (шифр) | ▶ | Зберігати зашифровано (gpg або age) |
| Моніторинг аномалій (DoS) | ▶ | Ліміти + алерти за частотою запитів |

### Сервер / ОС
| Контроль | Статус | Деталі |
|----------|--------|--------|
| Автооновлення безпеки | ▶ | `unattended-upgrades` (Debian/Ubuntu) |
| Відокремлена Unix-група для php-fpm pool | ▶ | Окремий користувач + chmod 750 на корінь додатку |
| Fail2ban / ssh hardening | ▶ | Обмежити паролі, дозволити лише ключі |
| Логи з ротацією | ▶ | logrotate для nginx + окремий retention |
| Часова синхронізація | ✓ | `systemd-timesyncd` або chrony |

### Домен / TLS
| Контроль | Статус | Деталі |
|----------|--------|--------|
| Let’s Encrypt автоматичне оновлення | ▶ | `certbot renew` timer/systemd |
| OCSP stapling | ▶ | `ssl_stapling on;` у nginx |
| HSTS preload | ▶ | Після стабільності HTTPS + підписати заявку у hstspreload.org |

### Операційні процедури
| Контроль | Статус | Деталі |
|----------|--------|--------|
| Внутрішній документ класифікації (не-ДІР) | ▶ | Описати дані, моделі загроз, відсутність ПІБ |
| Періодичний парольний аудит | ▶ | Раз на 6-12 міс або при зміні складу адмінів |
| Інцидентна процедура (runbook) | ▶ | Кроки: відкликання доступів, ротація пароля, перевірка цілісності |
| Тест відновлення з бекапу | ▶ | Перевірка що дамп можна розгорнути без PII ризиків |

### Наступні кроки впровадження
1. Перенести inline-скрипти у окремі файли (`verify.js`, `token.js`, `tokens.js`, `issue_page.js`) і видалити nonce (CSP -> `script-src 'self'`).
2. Додати nginx rate limiting (`limit_req_zone $binary_remote_addr zone=certreg:10m rate=60r/m;`).
3. Налаштувати HSTS, OCSP stapling, регулярний автоматичний renew TLS.
4. Створити користувача `certreg_app` з SELECT/INSERT/UPDATE; заборонити DDL у runtime.
5. Аргонізація паролів: перевірити `PASSWORD_DEFAULT` – якщо bcrypt, оцінити cost >= 12; якщо доступний Argon2id, задати через `password_hash(..., PASSWORD_ARGON2ID, [...])`.
6. Заборонити логування параметра `p` (map_regex у nginx або custom `log_format` без `$request_uri`).
7. Документ «Положення про сервіс» – коротко: модель даних, відсутність персональних даних, класифікація ризиків.
8. Пілотний аудит: спроба підробки (підміна QR) і перевірка виявлення через невідповідність INT.
9. Скрипт self-check (CLI) що перевіряє доступність критичних файлів і відсутність new *.php поза whitelist.

Ця секція слугує живим документом: при виконанні пунктів позначайте ✓ та оновлюйте деталі.

## Безпека
### Ключові властивості
* ПІБ ніколи не зберігається на сервері – витік БД розкриє лише (`cid`, `h`, `course`, `grade`, `issued_date`, revocation дані).
* Salt зберігається тільки у QR payload (і не на сервері), що робить перебір ПІБ по витеклому `h` непрактичним.
* HMAC обчислюється по канонічному рядку із нормалізованим NAME – зміна пробілів/регістру/апострофів не дає обійти перевірку.
* `h` відсутній у QR – офлайн валідність без сервера неможлива (запобігає статичним підробкам лише на основі картинки).
* INT (короткий код перших 10 hex H) – зручний візуальний маркер відповідності «зображення ↔ відповідь сервера».

### Що може зробити зловмисник
| Сценарій | Може? | Пояснення |
|----------|-------|-----------|
| Показати свій легітимний сертифікат іншим | Так | Це очікувана поведінка, не загроза |
| Змінити ПІБ у файлі зображення локально і пройти перевірку | Ні | Перевірка перераховує HMAC з введеним ПІБ та salt – не співпаде з `h` |
| Підмінити QR чужого сертифіката своїм | Може приклеїти | Але INT на друкованому і INT зі сторінки не збігаються, а введення ПІБ оригінального сертифіката дасть mismatch |
| Створити валідний сертифікат на чуже ПІБ без доступу до видачі | Ні | Немає salt нового сертифіката наперед + потрібно знати канонічне NAME |
| Відновити ПІБ із `h` і salt іншого сертифіката | Ні (практично) | Ітерація по реалістичному просторі ПІБ колосально дорога; salt 256 біт ускладнює будь-які кеші/таблиці |
| Зібрати базу ПІБ масово через status API | Ні | API не повертає ПІБ, лише `h` і метадані |
| Підібрати інший набір (NAME, course, grade, date) із тим же H | Обчисл. нездійсненно | Властивість HMAC-SHA256 (використовуємо 256-біт вихід) |
| Згенерувати колізію INT (10 hex) для обману візуальної перевірки | Дуже малоймовірно | 40 біт → ~1 трлн варіантів; повна перевірка все одно дивиться на повний H |

### Що НЕ дає система (декларовані не-цілі)
* Не запобігає власнику поширювати свій чинний сертифікат.
* Не забезпечує офлайн-криптоперевірку без сервера (навмисно – щоб унеможливити статичні підробки).
* Не приховує метадані (курс, оцінка, дата) – вони в QR для зручності.

### Додаткові рекомендації
* Rate-limit `/api/status.php` (напр. 30–60 r/m per IP) для шумозахисту.
* Строгий CSP + відсутність inline-скриптів (roadmap).
* Періодичний аудит того, що у репозиторії немає випадкового логування введених ПІБ.
* Опціонально – додати короткий checksum до INT (на випадок друкарських помилок при голосовому читанні).

### Витік бази даних: що це (не) означає
У БД зберігаються лише: `cid`, `h` (HMAC), `course`, `grade`, `issued_date`, `revoked_at`, `revoke_reason`, службові таймстемпи та хеш пароля адміністратора.

Якщо дамп БД потрапить у відкритий доступ:
* НЕ розкриваються ПІБ – їх ніколи не було в таблиці.
* Неможливо згенерувати нові валідні сертифікати – для цього потрібна сіль (salt) кожного окремого сертифіката, яка існує лише в QR payload і не зберігається на сервері.
* Неможливо «підмінити» вже надрукований сертифікат на інший домен – на фізичному/збереженому зображенні вбудований QR з конкретним доменом (origin). Щоб перенаправити користувача на інший сервер, треба фізично підмінити QR (передрук / наклейка), що виявляється невідповідністю INT або просто візуально.
* Копію БД можна імпортувати на інший хост, але сертифікати людей все одно містять QR із ОРИГІНАЛЬНИМ доменом. Користувач переходить саме на офіційний домен (HSTS рекомендується), а не на клон.

Що все ж розкривається при витоку:
* Обсяг і часовий розподіл видач (може бути чутливо як бізнес-метрика).
* Набір курсів і оцінок; у дуже малих групах стороння особа може спробувати відгадати ПІБ через зовнішні знання (реідентифікація за контекстом).
* Хеш пароля адміністратора (офлайн brute-force ризик) – ключовий елемент, який треба захищати сильним алгоритмом (bcrypt/Argon2id з достатньою складністю) і довгою фразою.

Гіпотетичні додаткові загрози можливі лише якщо разом із дампом витечуть журнали веб-сервера з параметром `p` (де всередині base64url JSON із salt). У такому випадку для тих конкретних записів зловмисник матиме (salt, h) і зможе (теоретично) робити словниковий перебір ПІБ. Тому рекомендується не логувати повний query string або замасковувати `p`.

Підсумок: конфіденційність БД низької критичності (відсутні ПІБ), інтерес представляють тільки статистика та пароль адміністратора. Основний фокус захисту: (1) складний пароль і сучасний парольний хеш, (2) відсутність логування salts/QR payload, (3) розділення привілеїв у MySQL (app user без ALTER/DROP), (4) контроль цілісності домену (HSTS, моніторинг сертифіката).

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

## Мінімальне розгортання (актуальна версія)
1. Створити БД + користувача.
2. Скопіювати `config.php.example` → `config.php`, налаштувати `db_*`, `site_name`, `logo_path`, `coords`.
3. Запустити міграцію `php migrations/004_create_tokens_table.php`.
4. (Необов'язково) Запустити `php migrations/005_drop_legacy.php --archive` для прибирання старих таблиць.
5. Виставити nginx whitelist тільки на: `issue_token.php`, `tokens.php`, `token.php`, `verify.php`, `qr.php`, `api/*.php`.
6. Додати rate-limit на `/api/status.php` при потребі.

## API (коротко)
POST /api/register.php {cid,v,h,course,grade,date} -> {ok:1}
GET  /api/status.php?cid=... -> {ok:1,h,revoked_at?,revoke_reason?}
POST /api/revoke.php {cid,reason}
POST /api/unrevoke.php {cid}
POST /api/delete_token.php {cid,_csrf}

## Права доступу (рекомендація)
- Заборонити всі *.php окрім білого списку.
- Admin сторінки за IP + сесія.
- `config.php` chmod 640.

## Ліцензія
MIT
openssl rand -hex 32

```

Переконайтесь у наявності `files/cert_template.jpg` та `fonts/Montserrat-Light.ttf`.

