<?php
$cfg = require __DIR__.'/config.php';
// --- Security headers (no inline scripts) ---
if (!headers_sent()) {
  $csp = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'; connect-src 'self'; upgrade-insecure-requests";
  header('Content-Security-Policy: ' . $csp);
  header('X-Content-Type-Options: nosniff');
  header('X-Frame-Options: DENY');
  header('Referrer-Policy: no-referrer');
  header('Permissions-Policy: geolocation=(), microphone=(), camera=(), interest-cohort=()');
  header('X-XSS-Protection: 0');
  // Strong HSTS should be set at nginx after stable HTTPS:
  // header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
}
$coordsJson = htmlspecialchars(json_encode($cfg['coords'] ?? [], JSON_UNESCAPED_UNICODE), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8">
  <link rel="shortcut icon" href="/assets/favicon.ico" type="image/x-icon">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= htmlspecialchars($cfg['site_name']) ?></title>
  <link rel="stylesheet" href="/assets/css/styles.css">
</head>
<body<?= isset($isAdminPage) && $isAdminPage ? ' class="admin-page"' : '' ?> data-coords='<?= $coordsJson ?>'>
<header class="topbar">
  <div class="topbar__inner">
    <?php require_once __DIR__.'/auth.php'; ?>
    <?php if (is_admin_logged()): ?>
      <a href="/admin.php" class="brand" style="text-decoration:none;color:inherit">
        <div class="logo" style="background-image:url('<?= htmlspecialchars($cfg['logo_path']) ?>')" aria-label="Адмін панель"></div>
        <div class="title"><?= htmlspecialchars($cfg['site_name']) ?></div>
      </a>
    <?php else: ?>
      <div class="brand">
        <div class="logo" style="background-image:url('<?= htmlspecialchars($cfg['logo_path']) ?>')"></div>
        <div class="title"><?= htmlspecialchars($cfg['site_name']) ?></div>
      </div>
    <?php endif; ?>
    <nav class="topbar__actions">
  <?php // auth already required above ?>
    <?php if (is_admin_logged()): ?>
  <a class="btn btn-light" href="/issue_token.php" style="margin-right:8px">Видача</a>
  <a class="btn btn-light" href="/tokens.php" style="margin-right:8px">Токени</a>
  <a class="btn btn-light" href="/events.php" style="margin-right:8px">Аудит</a>
        <form action="/logout.php" method="post" style="display:inline-block">
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
          <button class="btn btn-light" type="submit">Вийти</button>
        </form>
      <?php else:
        // show login link only when user is on admin.php (public pages like checkCert should not show it)
        $current = basename($_SERVER['SCRIPT_NAME'] ?? '');
        if ($current === 'admin.php'): ?>
          <a class="btn btn-light" href="/admin.php">Увійти</a>
      <?php endif; ?>
      <?php endif; ?>
    </nav>
  </div>
</header>

<?php if(empty($hideAlertBanner)): ?>
  <div class="alert-banner" role="alert" aria-live="polite">
    <div class="alert-banner__marquee">
      <div class="alert-banner__track">
        <span class="alert-banner__text">Увага! Якщо дані у вікні 'Технічні дані' не збігаються з даними роздрукованого сертифіката, це свідчить про підробку документа.</span>
        <span class="alert-banner__text">Увага! Якщо дані у вікні 'Технічні дані' не збігаються з даними роздрукованого сертифіката, це свідчить про підробку документа.</span>
      </div>
    </div>
  </div>
<?php endif; ?>

<main class="container">
