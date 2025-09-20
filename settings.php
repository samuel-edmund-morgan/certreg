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
        <button type="button" class="tab<?= $tab==='account' ? ' active' : '' ?>" role="tab" aria-selected="<?= $tab==='account' ? 'true':'false' ?>" data-tab="account" data-url="/settings.php?tab=account">Акаунт</button>
    </div>
    <div class="tab-panel settings-panel" role="tabpanel" data-panel="<?= htmlspecialchars($tab) ?>">
        <div class="settings-panel__inner">
            <div id="settingsContent" class="settings-panel__content">
                <?php
                // Server-side initial render for non-JS / direct load (fallback)
                include __DIR__ . '/settings_section.php';
                ?>
            </div>
        </div>
    </div>
</div>


<?php
include $_SERVER['DOCUMENT_ROOT'] . '/footer.php';
?>
<script src="/assets/js/branding.js"></script>
<script src="/assets/js/settings_nav.js" defer></script>
<script src="/assets/js/color_sync.js" defer></script>
<script src="/assets/js/settings_account.js" defer></script>
<script src="/assets/js/password_toggle.js" defer></script>
