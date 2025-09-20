<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/db.php';

require_admin();

$title = "Налаштування";
include $_SERVER['DOCUMENT_ROOT'] . '/header.php';
?>

<h1 class="mb-20">Налаштування</h1>

<div class="row">
    <div class="col-md-3">
        <div class="list-group">
            <a href="/settings.php?tab=branding" class="list-group-item list-group-item-action active">Брендування</a>
            <a href="/settings.php?tab=templates" class="list-group-item list-group-item-action">Шаблони</a>
            <a href="/settings.php?tab=users" class="list-group-item list-group-item-action">Користувачі</a>
        </div>
    </div>
    <div class="col-md-9">
        <?php
        $tab = $_GET['tab'] ?? 'branding';
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
