<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/db.php';

require_admin();

$title = "Налаштування";
include $_SERVER['DOCUMENT_ROOT'] . '/header.php';
?>

<h1 class="mb-20">Налаштування</h1>
<?php $tab = $_GET['tab'] ?? 'branding'; ?>
<div class="settings-tabs-wrapper">
    <div class="tabs settings-tabs" role="tablist" aria-label="Налаштування секції">
        <button type="button" class="tab<?= $tab==='branding' ? ' active' : '' ?>" role="tab" aria-selected="<?= $tab==='branding' ? 'true':'false' ?>" data-tab="branding" data-url="/settings.php?tab=branding">Брендування</button>
        <button type="button" class="tab<?= $tab==='templates' ? ' active' : '' ?>" role="tab" aria-selected="<?= $tab==='templates' ? 'true':'false' ?>" data-tab="templates" data-url="/settings.php?tab=templates">Шаблони</button>
        <button type="button" class="tab<?= $tab==='users' ? ' active' : '' ?>" role="tab" aria-selected="<?= $tab==='users' ? 'true':'false' ?>" data-tab="users" data-url="/settings.php?tab=users">Оператори</button>
    </div>
    <div class="tab-panel settings-panel" role="tabpanel" data-panel="<?= htmlspecialchars($tab) ?>">
        <?php
        if ($tab === 'branding') {
                        // Load existing branding settings into associative array
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
                        ?>
                        <h2 class="mt-0">Брендування</h2>
                        <p class="fs-14 text-muted">Оновіть назву сайту, кольори, логотип і favicon. Кольори у форматі HEX (наприклад #102d4e). Для переносу рядка у назві використовуйте буквально послідовність <code>\n</code> (наприклад: <code>Перша частина\nДруга частина</code>).</p>
                        <form id="brandingForm" class="form maxw-520" method="post" action="/api/branding_save.php" enctype="multipart/form-data" autocomplete="off">
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
                                                                                            <span id="secondaryHint" class="fs-12 text-muted">Другорядні дії: `.btn-secondary`, текст у `.btn-light`.</span>
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
                        <!-- JS moved to /assets/js/branding.js to comply with CSP -->
                        <?php
    } elseif ($tab === 'templates') {
        echo "<h2>Шаблони</h2><p>Управління шаблонами сертифікатів.</p>";
    } elseif ($tab === 'users') {
        echo "<h2>Оператори</h2><p>Управління користувачами системи.</p>";
    }
    ?>
  </div>
</div>


<?php
include $_SERVER['DOCUMENT_ROOT'] . '/footer.php';
?>
<script src="/assets/js/branding.js"></script>
<script src="/assets/js/settings_tabs.js" defer></script>
<script src="/assets/js/color_sync.js" defer></script>
