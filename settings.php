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
                    echo "<h2>Брендування</h2><p>Налаштування брендування системи.</p>";
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
