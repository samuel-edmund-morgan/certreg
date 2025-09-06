<?php
// Redirect legacy verification URL to new privacy-first verification page.
$target = '/verify.php';
header('Location: ' . $target, true, 301);
exit;
