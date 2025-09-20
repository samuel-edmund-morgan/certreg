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
    <div class="tabs settings-tabs">
        <a class="tab<?= $tab==='branding' ? ' active' : '' ?>" href="/settings.php?tab=branding">Брендування</a>
        <a class="tab<?= $tab==='templates' ? ' active' : '' ?>" href="/settings.php?tab=templates">Шаблони</a>
        <a class="tab<?= $tab==='users' ? ' active' : '' ?>" href="/settings.php?tab=users">Користувачі</a>
    </div>
    <div class="tab-panel settings-panel">
                <?php
                if ($tab === 'branding') {
                        // Load existing branding settings into associative array
                        $branding = [];
                        $st = $pdo->query("SELECT setting_key, setting_value FROM branding_settings");
                        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r){ $branding[$r['setting_key']] = $r['setting_value']; }
                        $curSite   = $branding['site_name'] ?? ($cfg['site_name'] ?? '');
                        $curPrimary= $branding['primary_color'] ?? '';
                        $curAccent = $branding['accent_color'] ?? '';
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
                                <input type="text" name="primary_color" placeholder="#102d4e" value="<?= htmlspecialchars($curPrimary) ?>" maxlength="7" pattern="#?[0-9A-Fa-f]{6}">
                            </label>
                            <label>Accent колір
                                <input type="text" name="accent_color" placeholder="#2563eb" value="<?= htmlspecialchars($curAccent) ?>" maxlength="7" pattern="#?[0-9A-Fa-f]{6}">
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
                            <div class="flex gap-10 align-center">
                                <button class="btn btn-primary" type="submit" id="brandingSaveBtn">Зберегти</button>
                                <span id="brandingStatus" class="fs-13 text-muted"></span>
                            </div>
                        </form>
                        <!-- JS moved to /assets/js/branding.js to comply with CSP -->
                        <?php
                } elseif ($tab === 'templates') {
                        echo "<h2>Шаблони</h2><p>Управління шаблонами сертифікатів.</p>";
                } elseif ($tab === 'users') {
                        echo "<h2>Користувачі</h2><p>Управління користувачами системи.</p>";
                }
                ?>
    </div>
</div>


<?php
include $_SERVER['DOCUMENT_ROOT'] . '/footer.php';
?>
<script src="/assets/js/branding.js"></script>
