<?php
require_once __DIR__.'/auth.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    logout_admin();
}
header('Location: /admin.php');
