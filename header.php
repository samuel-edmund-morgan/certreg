<?php
// Central header include: sets strict CSP that forbids inline scripts.
// To keep functionality (e.g. CSRF token access for fetch) we expose the token via a <meta> tag.
// Any previous inline script usage (like window.__CSRF_TOKEN assignment) must be removed.
// Allow header to render and send security headers even if DB is temporarily unavailable
if (!defined('ALLOW_DB_FAIL_SOFT')) { define('ALLOW_DB_FAIL_SOFT', true); }
require_once __DIR__.'/auth.php'; // ensure csrf_token() & session org context available
$cfg = require __DIR__.'/config.php';
// --- Per-organization branding resolution ---
// Updated precedence (highest last):
// 1. Global config defaults (config.php)
// 2. Global instance overrides (branding_settings table) – baseline shared look
// 3. Organization row (organizations table) for current operator org_id – FINAL override for that operator (logo, name, colours, footer, support)
//    This lets per-org identity supersede a global theme.
try {
  require_once __DIR__.'/db.php';
  // Global overrides (system-wide baseline) FIRST
  $branding = [];
  if ($pdo) {
    $st = $pdo->query("SELECT setting_key, setting_value FROM branding_settings");
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r){ $branding[$r['setting_key']] = $r['setting_value']; }
  }
  foreach(['site_name','logo_path','favicon_path','primary_color','accent_color','secondary_color','footer_text','support_contact'] as $k){
    if(isset($branding[$k]) && $branding[$k] !== '') $cfg[$k] = $branding[$k];
  }
  // THEN apply per-organization overrides (final layer). Allow an explicit forced org id (e.g., verification context) to override session org.
  $orgId = null;
  if(isset($forced_org_id) && is_int($forced_org_id) && $forced_org_id>0){
    $orgId = $forced_org_id;
  } else {
    $orgId = current_org_id();
  }
  if($orgId && $pdo){
    $stOrg = $pdo->prepare('SELECT id,name,code,logo_path,favicon_path,primary_color,accent_color,secondary_color,footer_text,support_contact FROM organizations WHERE id=? AND is_active=1');
    $stOrg->execute([$orgId]);
    if($orgRow = $stOrg->fetch(PDO::FETCH_ASSOC)){
      foreach(['name'=>'site_name','logo_path'=>'logo_path','favicon_path'=>'favicon_path','primary_color'=>'primary_color','accent_color'=>'accent_color','secondary_color'=>'secondary_color','footer_text'=>'footer_text','support_contact'=>'support_contact'] as $col=>$mapKey){
        if(isset($orgRow[$col]) && $orgRow[$col] !== null && $orgRow[$col] !== ''){
          if($col==='name') $cfg['site_name'] = $orgRow[$col]; else $cfg[$mapKey] = $orgRow[$col];
        }
      }
      if(!empty($orgRow['code'])){ $cfg['org_code'] = $orgRow['code']; }
      $cfg['__active_org_id'] = (int)$orgRow['id'];
    }
  }
} catch(Throwable $e){ /* ignore DB/branding failures; fall back to best-effort */ }

// --- Security headers (no inline scripts) ---
if (!headers_sent()) {
  // Allow inline styles (style attributes) needed for dynamic color swatches in admin tables.
  // Scripts remain strictly non-inline.
  // Emit multiple short CSP headers (combined by browsers) to avoid line folding/wrapping by proxies/servers.
  $cspDirectives = [
    "default-src 'self' blob:",
    "script-src 'self'",
    "style-src 'self' 'unsafe-inline'",
    "img-src 'self' data:",
    "font-src 'self'",
    "object-src 'none'",
    "base-uri 'none'",
    "frame-ancestors 'none'",
    "form-action 'self'",
    "connect-src 'self'",
    'upgrade-insecure-requests',
  ];
  foreach ($cspDirectives as $dir) {
    header('Content-Security-Policy: ' . $dir, false);
  }
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
    $rawFavicon = $cfg['favicon_path'] ?? '/assets/favicon.ico';
    if(!$rawFavicon || !is_string($rawFavicon)) $rawFavicon = '/assets/favicon.ico';
    $absFav = $_SERVER['DOCUMENT_ROOT'] . $rawFavicon;
    if(!is_file($absFav)) { // fallback hard
      $rawFavicon = '/assets/favicon.ico';
      $absFav = $_SERVER['DOCUMENT_ROOT'] . $rawFavicon;
    }
    $favVer = @filemtime($absFav) ?: time();
    $ext = strtolower(pathinfo(parse_url($rawFavicon,PHP_URL_PATH), PATHINFO_EXTENSION));
    $mime = 'image/x-icon';
    if($ext==='png') $mime='image/png'; elseif($ext==='svg') $mime='image/svg+xml';
    $faviconEsc = htmlspecialchars($rawFavicon.'?v='.$favVer, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  ?>
  <link rel="icon" href="<?= $faviconEsc ?>" type="<?= $mime ?>">
  <link rel="shortcut icon" href="<?= $faviconEsc ?>" type="<?= $mime ?>">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <?php
    $rawTitle = (string)($cfg['site_name'] ?? '');
    // Replace literal \n sequences with a space for <title> to avoid showing backslash+n
    $titleFlat = str_replace('\\n',' ', $rawTitle);
  ?>
  <title><?= htmlspecialchars($titleFlat, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
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
  <script src="/assets/js/password_toggle.js" defer></script>
  <?php
    $primaryBrand = $cfg['primary_color'] ?? '';
    if($primaryBrand){ echo '<meta name="theme-color" content="'.htmlspecialchars($primaryBrand,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'">'; }
    // Link external branding colors css if it exists
    // Load global CSS first (baseline), then per-org CSS (overrides)
    $globalCss = $_SERVER['DOCUMENT_ROOT'].'/files/branding/branding_colors.css';
    if(is_file($globalCss)){
      $ver = @filemtime($globalCss) ?: time();
      echo '<link rel="stylesheet" href="/files/branding/branding_colors.css?v='.$ver.'">';
    }
    if(!empty($cfg['__active_org_id'])){
      $orgCssPath = $_SERVER['DOCUMENT_ROOT'].'/files/branding/org_'.$cfg['__active_org_id'].'/branding_colors.css';
      if(is_file($orgCssPath)){
        $ver2 = @filemtime($orgCssPath) ?: time();
        echo '<link rel="stylesheet" href="/files/branding/org_'.$cfg['__active_org_id'].'/branding_colors.css?v='.$ver2.'">';
      }
    }
  ?>
<body<?= isset($isAdminPage) && $isAdminPage ? ' class="admin-page"' : '' ?> data-coords='<?= $coordsJson ?>' data-org='<?= $orgCode ?>' data-orgname='<?= htmlspecialchars($cfg['site_name'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>' data-inf='<?= $infSent ?>' data-canon='<?= $canonUrl ?>' data-template='<?= $tplPath ?>' data-test='<?= (isset($_GET['test_mode']) && $_GET['test_mode'] === '1') ? '1' : '0' ?>'>
<header class="topbar">
  <div class="topbar__inner">
    <?php
      $rawSiteName = (string)($cfg['site_name'] ?? '');
      // Replace literal backslash + n sequences with <br> for display only.
  $htmlSiteName = htmlspecialchars($rawSiteName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $htmlSiteName = str_replace('\\n','<br>',$htmlSiteName); // Keep multiline display in header
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
