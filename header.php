<?php
// Central header include: sets strict CSP that forbids inline scripts.
// To keep functionality (e.g. CSRF token access for fetch) we expose the token via a <meta> tag.
// Any previous inline script usage (like window.__CSRF_TOKEN assignment) must be removed.
require_once __DIR__.'/config.php';
require_once __DIR__.'/auth.php'; // ensure csrf_token() available before emitting <head>
$cfg = require __DIR__.'/config.php';
// Load branding overrides (site_name, logo_path, primary_color, accent_color, favicon_path)
try {
  require_once __DIR__.'/db.php';
  $branding = [];
  $st = $pdo->query("SELECT setting_key, setting_value FROM branding_settings");
  foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r){ $branding[$r['setting_key']] = $r['setting_value']; }
  // Override config values if present
  if(isset($branding['site_name'])) $cfg['site_name'] = $branding['site_name'];
  if(isset($branding['logo_path'])) $cfg['logo_path'] = $branding['logo_path'];
  if(isset($branding['favicon_path'])) $cfg['favicon_path'] = $branding['favicon_path'];
  if(isset($branding['primary_color'])) $cfg['primary_color'] = $branding['primary_color'];
  if(isset($branding['accent_color']))  $cfg['accent_color']  = $branding['accent_color'];
} catch(Throwable $e){ /* ignore DB/branding failures; fall back to config */ }

// --- Security headers (no inline scripts) ---
if (!headers_sent()) {
  header('Content-Security-Policy: default-src \'self\' blob:; script-src \'self\'; style-src \'self\'; img-src \'self\' data:; font-src \'self\'; object-src \'none\'; base-uri \'none\'; frame-ancestors \'none\'; form-action \'self\'; connect-src \'self\'; upgrade-insecure-requests');
  header('X-Content-Type-Options: nosniff');
  header('X-Frame-Options: DENY');
  header('Referrer-Policy: no-referrer');
  header('Permissions-Policy: geolocation=(), microphone=(), camera=(), interest-cohort=()');
  header('X-XSS-Protection: 0');
  // Strong HSTS should be set at nginx after stable HTTPS:
  // header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
}
$coordsJson = htmlspecialchars(json_encode($cfg['coords'] ?? [], JSON_UNESCAPED_UNICODE), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$orgCode = htmlspecialchars($cfg['org_code'] ?? 'ORG-CERT', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$infSent = htmlspecialchars($cfg['infinite_sentinel'] ?? '4000-01-01', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$csrfMeta = htmlspecialchars(csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$canonUrl = htmlspecialchars($cfg['canonical_verify_url'] ?? '/verify.php', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$tplPath = htmlspecialchars($cfg['cert_template_path'] ?? '/files/cert_template.jpg', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8">
  <?php
    $favicon = htmlspecialchars($cfg['favicon_path'] ?? '/assets/favicon.ico', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  ?>
  <link rel="icon" href="<?= $favicon ?>" type="image/x-icon">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= htmlspecialchars($cfg['site_name']) ?></title>
  <?php if(!empty($cfg['primary_color'])): ?>
    <meta name="theme-color" content="<?= htmlspecialchars($cfg['primary_color']) ?>">
    <style>
      :root { --color-primary: <?= htmlspecialchars($cfg['primary_color']) ?>; <?php if(!empty($cfg['accent_color'])): ?>--color-accent: <?= htmlspecialchars($cfg['accent_color']) ?>;<?php endif; ?> }
    </style>
  <?php endif; ?>
  <meta name="csrf" content="<?= $csrfMeta ?>">
  <?php if (isset($_GET['test_mode']) && $_GET['test_mode'] === '1'): ?>
    <meta name="test-mode" content="1">
  <?php endif; ?>
  <!-- Removed font preloads to avoid 'preloaded but not used' warnings; fonts load via @font-face -->
  <?php
    // Simple cache-busting version based on file modification time.
    $cssPath = $_SERVER['DOCUMENT_ROOT'] . '/assets/css/styles.css';
    $cssVer = @filemtime($cssPath) ?: time();
  ?>
  <link rel="stylesheet" href="/assets/css/styles.css?v=<?= $cssVer ?>">
<body<?= isset($isAdminPage) && $isAdminPage ? ' class="admin-page"' : '' ?> data-coords='<?= $coordsJson ?>' data-org='<?= $orgCode ?>' data-inf='<?= $infSent ?>' data-canon='<?= $canonUrl ?>' data-template='<?= $tplPath ?>' data-test='<?= (isset($_GET['test_mode']) && $_GET['test_mode'] === '1') ? '1' : '0' ?>'>
<header class="topbar">
  <div class="topbar__inner">
    <?php require_once __DIR__.'/auth.php'; ?>
    <?php
      $rawSiteName = (string)($cfg['site_name'] ?? '');
      // Replace literal backslash + n sequences with <br> for display only.
      $htmlSiteName = htmlspecialchars($rawSiteName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
      $htmlSiteName = str_replace('\\n','<br>',$htmlSiteName);
    ?>
    <?php if (is_admin_logged()): ?>
      <a href="/admin.php" class="brand link-plain">
        <div class="logo"><img src="<?= htmlspecialchars($cfg['logo_path']) ?>" alt="Логотип"></div>
        <div class="title"><?= $htmlSiteName ?></div>
      </a>
    <?php else: ?>
      <div class="brand">
        <div class="logo"><img src="<?= htmlspecialchars($cfg['logo_path']) ?>" alt="Логотип"></div>
        <div class="title"><?= $htmlSiteName ?></div>
      </div>
    <?php endif; ?>
    <nav class="topbar__actions">
  <?php // auth already required above ?>
    <?php if (is_admin_logged()): ?>
      <?php if (is_admin()): ?>
        <a class="btn btn-light mr-8" href="/settings.php">Налаштування</a>
      <?php endif; ?>
      <a class="btn btn-light mr-8" href="/issue_token.php">Видача</a>
      <a class="btn btn-light mr-8" href="/tokens.php">Нагороди</a>
      <a class="btn btn-light mr-8" href="/events.php">Журнал</a>
      <form action="/logout.php" method="post" class="d-inline">
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

<?php
$current_page = basename($_SERVER['SCRIPT_NAME']);
if ($current_page === 'verify.php' && empty($hideAlertBanner)):
?>
  <div class="alert-banner" role="alert" aria-live="polite">
    <div class="alert-banner__marquee">
      <div class="alert-banner__track">
  <span class="alert-banner__text">Увага! Якщо дані у вікні 'Технічні дані' не збігаються з даними роздрукованої нагороди, це свідчить про підробку документа.</span>
  <span class="alert-banner__text">Увага! Якщо дані у вікні 'Технічні дані' не збігаються з даними роздрукованої нагороди, це свідчить про підробку документа.</span>
      </div>
    </div>
  </div>
<?php endif; ?>

<main class="container">
