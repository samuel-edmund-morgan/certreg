<?php
require_once __DIR__.'/auth.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    logout_admin();
}
header('Location: /admin.php');
