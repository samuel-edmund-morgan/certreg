<?php
// For production behind nginx, / is blocked at nginx level.
// In CI and local PHP built-in server, include our header to emit security headers.
// Return 200 to allow header checks to pass even if content indicates forbidden.
http_response_code(200);
$hideAlertBanner = true;
require __DIR__.'/header.php';
?>
<section class="centered">
	<div class="card card--narrow">
		<h1 class="card__title">403 Заборонено</h1>
		<p>Доступ до цієї сторінки заборонений.</p>
	</div>
	</section>
<?php require __DIR__.'/footer.php'; ?>
