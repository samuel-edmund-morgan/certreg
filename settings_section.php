<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/db.php';

// AJAX endpoint returning only the inner HTML of a settings section (no layout/header/footer)
// Enforces admin access.
require_admin();

header('Content-Type: text/html; charset=utf-8');

$tab = $_GET['tab'] ?? 'branding';

function render_branding_section(PDO $pdo, array $cfg){
    $branding = [];
    $st = $pdo->query("SELECT setting_key, setting_value FROM branding_settings");
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r){ $branding[$r['setting_key']] = $r['setting_value']; }
    $curSite   = $branding['site_name'] ?? ($cfg['site_name'] ?? '');
    $curPrimary= $branding['primary_color'] ?? '';
    $curAccent = $branding['accent_color'] ?? '';
    $curSecondary = $branding['secondary_color'] ?? '';
    $curLogo   = $branding['logo_path'] ?? ($cfg['logo_path'] ?? '');
    $curFavicon= $branding['favicon_path'] ?? ($cfg['favicon_path'] ?? '/assets/favicon.ico');
    $csrf = csrf_token();
    ob_start();
    ?>
    <h2 class="mt-0">Брендування</h2>
    <p class="fs-14 text-muted">Оновіть назву сайту, кольори, логотип і favicon. Кольори у форматі HEX (наприклад #102d4e). Для переносу рядка у назві використовуйте послідовність <code>\n</code>.</p>
    <form id="brandingForm" class="form" method="post" action="/api/branding_save.php" enctype="multipart/form-data" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
        <label>Назва сайту
            <input type="text" name="site_name" value="<?= htmlspecialchars($curSite) ?>" required maxlength="255">
        </label>
        <label>Primary колір
            <div class="flex gap-8 align-center color-field" title="HEX формат #rrggbb">
                <input type="text" class="color-hex" data-color-peer="primary_color_picker" name="primary_color" placeholder="#102d4e" value="<?= htmlspecialchars($curPrimary) ?>" maxlength="7" pattern="#?[0-9A-Fa-f]{6}" aria-describedby="primaryHint">
                <input type="color" id="primary_color_picker" class="color-picker" value="<?= htmlspecialchars($curPrimary ?: '#102d4e') ?>" aria-label="Primary color picker">
            </div>
            <span id="primaryHint" class="fs-12 text-muted">Використовується для верхнього шару UI (кнопки, хедер, футер).</span>
        </label>
        <label>Accent колір
            <div class="flex gap-8 align-center color-field" title="Акцент: активні елементи">
                <input type="text" class="color-hex" data-color-peer="accent_color_picker" name="accent_color" placeholder="#2563eb" value="<?= htmlspecialchars($curAccent) ?>" maxlength="7" pattern="#?[0-9A-Fa-f]{6}" aria-describedby="accentHint">
                <input type="color" id="accent_color_picker" class="color-picker" value="<?= htmlspecialchars($curAccent ?: '#2563eb') ?>" aria-label="Accent color picker">
            </div>
            <span id="accentHint" class="fs-12 text-muted">Підкреслення активних вкладок, ховер у topbar, прогрес, badge процесу.</span>
        </label>
        <label>Secondary колір (опц.)
            <div class="flex gap-8 align-center color-field" title="Second: допоміжні кнопки">
                <input type="text" class="color-hex" data-color-peer="secondary_color_picker" name="secondary_color" placeholder="#6b7280" value="<?= htmlspecialchars($curSecondary) ?>" maxlength="7" pattern="#?[0-9A-Fa-f]{6}" aria-describedby="secondaryHint">
                <input type="color" id="secondary_color_picker" class="color-picker" value="<?= htmlspecialchars($curSecondary ?: '#6b7280') ?>" aria-label="Secondary color picker">
            </div>
            <span id="secondaryHint" class="fs-12 text-muted">Другорядні дії: <code>.btn-secondary</code>, текст у <code>.btn-light</code>.</span>
        </label>
        <div class="mb-12">
            <div class="fw-600 fs-14 mb-4">Логотип (PNG/JPG/SVG, ≤2MB)</div>
            <?php if($curLogo): ?>
                <div class="mb-8 branding-preview branding-preview--logo" aria-label="Поточний логотип">
                    <img src="<?= htmlspecialchars($curLogo) ?>" alt="Поточний логотип">
                </div>
            <?php endif; ?>
            <input type="file" name="logo_file" accept="image/png,image/jpeg,image/svg+xml">
        </div>
        <div class="mb-12">
            <div class="fw-600 fs-14 mb-4">Favicon (ICO/PNG/SVG, ≤128KB)</div>
            <?php if($curFavicon): ?>
                <div class="mb-8 branding-preview branding-preview--favicon" aria-label="Поточний favicon">
                    <img src="<?= htmlspecialchars($curFavicon) ?>" alt="Поточний favicon">
                </div>
            <?php endif; ?>
            <input type="file" name="favicon_file" accept="image/x-icon,image/png,image/svg+xml">
        </div>
        <label>Текст у футері (© ...)
            <input type="text" name="footer_text" placeholder="© <?= date('Y') ?> Національна академія СБУ" value="<?= htmlspecialchars($branding['footer_text'] ?? '') ?>" maxlength="255">
            <span class="fs-12 text-muted">Якщо пусто – використається дефолт із конфігурації (рік оновлюється автоматично).</span>
        </label>
        <label>Контакт підтримки
            <input type="text" name="support_contact" placeholder="527-76-90" value="<?= htmlspecialchars($branding['support_contact'] ?? '') ?>" maxlength="255">
            <span class="fs-12 text-muted">Наприклад: номер телефону або email.</span>
        </label>
        <div class="flex gap-10 align-center branding-actions">
            <button class="btn btn-primary" type="submit" id="brandingSaveBtn">Зберегти</button>
            <span id="brandingStatus" class="fs-13 text-muted ml-4"></span>
        </div>
    </form>
    <?php
    return ob_get_clean();
}

switch($tab){
    case 'branding':
        echo render_branding_section($pdo, $cfg);
        break;
    case 'organizations':
        echo '<h2 class="mt-0">Організації</h2>';
        echo '<p class="fs-14 text-muted maxw-760">Керуйте переліком організацій. Код незмінний після створення. Неактивні організації недоступні для операторів.</p>';
        $csrfOrg = csrf_token();
        echo '<form id="orgCreateForm" class="form mb-20" method="post" action="/api/org_create.php" enctype="multipart/form-data" autocomplete="off">'
            .'<input type="hidden" name="_csrf" value="'.htmlspecialchars($csrfOrg).'" />'
            .'<div class="org-create-grid">'
                .'<label>Назва
                    <input class="input" type="text" name="name" required maxlength="255" placeholder="Назва організації">
                </label>'
                .'<label>Код (immutable)
                    <input class="input" type="text" name="code" required pattern="[A-Z0-9_-]{2,32}" maxlength="32" placeholder="ACME">
                </label>'
                .'<label>Primary колір
                    <div class="flex gap-8 align-center color-field">'
                        .'<input class="input color-hex" data-color-peer="org_primary_color_picker" type="text" name="primary_color" pattern="#?[0-9A-Fa-f]{6}" maxlength="7" placeholder="#102d4e">'
                        .'<input type="color" id="org_primary_color_picker" class="color-picker" value="#102d4e" aria-label="Primary color picker">'
                    .'</div>
                </label>'
                .'<label>Accent колір
                    <div class="flex gap-8 align-center color-field">'
                        .'<input class="input color-hex" data-color-peer="org_accent_color_picker" type="text" name="accent_color" pattern="#?[0-9A-Fa-f]{6}" maxlength="7" placeholder="#2563eb">'
                        .'<input type="color" id="org_accent_color_picker" class="color-picker" value="#2563eb" aria-label="Accent color picker">'
                    .'</div>
                </label>'
                .'<label>Secondary колір
                    <div class="flex gap-8 align-center color-field">'
                        .'<input class="input color-hex" data-color-peer="org_secondary_color_picker" type="text" name="secondary_color" pattern="#?[0-9A-Fa-f]{6}" maxlength="7" placeholder="#6b7280">'
                        .'<input type="color" id="org_secondary_color_picker" class="color-picker" value="#6b7280" aria-label="Secondary color picker">'
                    .'</div>
                </label>'
                .'<label>Footer текст
                    <input class="input" type="text" name="footer_text" maxlength="255" placeholder="© '.date('Y').' Організація">
                </label>'
                .'<label>Контакт підтримки
                    <input class="input" type="text" name="support_contact" maxlength="255" placeholder="support@example.com">
                </label>'
                .'<label>Логотип
                    <input class="input" type="file" name="logo_file" accept="image/png,image/jpeg,image/svg+xml">
                </label>'
                .'<label>Favicon
                    <input class="input" type="file" name="favicon_file" accept="image/x-icon,image/png,image/svg+xml">
                </label>'
                .'<div class="col-span-2 flex gap-10 align-center"><button class="btn btn-primary" type="submit" id="orgCreateBtn">Створити</button><span id="orgCreateStatus" class="fs-12 text-muted"></span></div>'
            .'</div>'
            .'<div class="fs-12 text-muted mt-6">Після створення ви можете змінювати назву та кольори, але <strong>код</strong> залишиться незмінним.</div>'
        .'</form>';
        echo '<div class="flex gap-10 align-center mb-10"><input type="text" id="orgSearch" class="w-260" placeholder="Пошук (name/code)"><button class="btn btn-secondary" id="orgSearchBtn">Пошук</button><button class="btn btn-light" id="orgResetBtn">Скинути</button></div>';
        echo '<div class="table-wrap"><table class="table" id="orgsTable"><thead><tr>'
            .'<th data-sort="id" class="sortable">ID</th>'
            .'<th data-sort="code" class="sortable">Код</th>'
            .'<th>Бренд</th>'
            .'<th data-sort="created_at" class="sortable">Створено</th>'
            .'<th>Статус</th>'
            .'<th>Дії</th>'
            .'</tr></thead><tbody><tr><td colspan="6" class="text-center text-muted fs-13">Завантаження...</td></tr></tbody></table></div>';
        echo '<div id="orgsSummary" class="fs-12 text-muted mt-8"></div>';
        echo '<div id="orgsPagination" class="mt-10"></div>';
        echo '<script src="/assets/js/settings_orgs.js" defer></script>';
        break;
    case 'templates':
        echo '<h2>Шаблони</h2><p>Управління шаблонами сертифікатів.</p>';
        break;
    case 'users':
        echo '<h2 class="mt-0">Оператори</h2>';
        echo '<p class="fs-14 text-muted maxw-760">Створюйте та переглядайте облікові записи операторів. Докладні дії (перейменування, деактивація, скидання паролю, видалення) – на сторінці окремого оператора.</p>';
        $csrfUsers = csrf_token();
    echo '<form id="opCreateForm" class="form mb-20" method="post" action="/api/operator_create.php" autocomplete="off">'
            .'<input type="hidden" name="_csrf" value="'.htmlspecialchars($csrfUsers).'" />'
            .'<div class="op-create-grid">'
                .'<div class="op-field">'
                    .'<label>Логін<br><input type="text" name="username" required minlength="3" maxlength="40" pattern="[a-zA-Z0-9_.-]{3,40}" class="mono" placeholder="operator1" /></label>'
                    .'<span class="field-hint fs-12 text-muted">3–40 символів, унікальний.</span>'
                .'</div>'
                .'<div class="op-field">'
                    .'<label>Пароль<br><input type="password" name="password" required minlength="8" autocomplete="new-password" /></label>'
                    .'<span class="field-hint fs-12 text-muted">Мінімум 8 символів.</span>'
                .'</div>'
                .'<div class="op-field">'
                    .'<label>Повтор<br><input type="password" name="password2" required minlength="8" autocomplete="new-password" /></label>'
                    .'<span class="field-hint fs-12 text-muted">Повторіть пароль точно.</span>'
                .'</div>'
                .'<div class="op-field">'
                    .'<label>Організація<br><select name="org_id" id="opOrgSelect" required><option value="">Завантаження...</option></select></label>'
                    .'<span class="field-hint fs-12 text-muted">Обовʼязково. Оператор належить до однієї організації.</span>'
                .'</div>'
                .'<div class="op-actions">'
                    .'<button type="submit" id="opCreateBtn" class="btn btn-primary op-create-btn">Створити</button>'
                    .'<span id="opCreateStatus" class="fs-12 text-muted" aria-live="polite"></span>'
                .'</div>'
            .'</div>'
            .'<div class="fs-12 text-muted mt-6">Роль завжди <code>operator</code>. Дозволені символи логіну: <code>a-z A-Z 0-9 _ . -</code>. Рекомендується поєднання літер і цифр у паролі.</div>'
        .'</form>';
        echo '<div class="table-wrap"><table class="table" id="operatorsTable" data-page="1"><thead><tr>'
            .'<th>ID</th><th>Логін</th><th>Організація</th><th>Роль</th><th>Статус</th><th>Створено</th><th>Деталі</th>'
            .'</tr></thead><tbody>'
            .'<tr><td colspan="7" class="text-center text-muted fs-13">Завантаження...</td></tr>'
            .'</tbody></table></div>';
        echo '<div id="operatorsSummary" class="fs-12 text-muted mt-8"></div>';
        echo '<div id="operatorsPagination" class="mt-10"></div>';
        echo '<div class="mt-12 fs-12 text-muted">Для керування – відкрийте рядок через кнопку у колонці "Деталі" (<code>operator.php?id=...</code>).</div>';
        break;
    case 'account':
        $csrf = csrf_token();
        echo '<h2 class="mt-0">Акаунт</h2>';
        echo '<p class="fs-14 text-muted">Змініть пароль адміністратора. Після зміни рекомендується вийти та увійти знову на інших відкритих вкладках.</p>';
        echo '<form id="accountPasswordForm" class="form" method="post" action="/api/account_change_password.php" autocomplete="off">'
            .'<input type="hidden" name="_csrf" value="'.htmlspecialchars($csrf).'" />'
            .'<label>Поточний пароль'
                .'<div class="pw-field"><input type="password" name="old_password" required minlength="1" autocomplete="current-password" /><button type="button" class="pw-toggle" aria-label="Показати пароль" data-target="old_password">👁</button></div>'
            .'</label>'
            .'<label>Новий пароль'
                .'<div class="pw-field"><input type="password" name="new_password" required minlength="8" autocomplete="new-password" /><button type="button" class="pw-toggle" aria-label="Показати пароль" data-target="new_password">👁</button></div>'
                .'<span class="fs-12 text-muted">Мінімум 8 символів, бажано літера + цифра.</span>'
            .'</label>'
            .'<label>Повтор нового паролю'
                .'<div class="pw-field"><input type="password" name="new_password2" required minlength="8" autocomplete="new-password" /><button type="button" class="pw-toggle" aria-label="Показати пароль" data-target="new_password2">👁</button></div>'
            .'</label>'
            .'<div class="flex gap-10 align-center">'
                .'<button type="submit" class="btn btn-primary" id="accountPwdBtn">Змінити</button>'
                .'<span id="accountPwdStatus" class="fs-13 text-muted"></span>'
            .'</div>'
        .'</form>';
        break;
    default:
        http_response_code(400);
        echo '<div class="alert alert-error">Невідома секція</div>';
}
?>
