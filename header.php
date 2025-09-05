<?php $cfg = require __DIR__.'/config.php'; ?>
<!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8">
  <link rel="shortcut icon" href="/assets/favicon.ico" type="image/x-icon">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= htmlspecialchars($cfg['site_name']) ?></title>
  <link rel="stylesheet" href="/assets/css/styles.css">
</head>
<body>
<header class="topbar">
  <div class="topbar__inner">
    <div class="brand">
      <div class="logo" style="background-image:url('<?= htmlspecialchars($cfg['logo_path']) ?>')"></div>
      <div class="title"><?= htmlspecialchars($cfg['site_name']) ?></div>
    </div>
    <nav class="topbar__actions">
      <?php require_once __DIR__.'/auth.php'; ?>
      <?php if (is_admin_logged()): ?>
        <form action="/logout.php" method="post">
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

<div class="alert-banner" role="alert" aria-live="polite">
  <div class="alert-banner__marquee">
    <div class="alert-banner__track">
      <span class="alert-banner__text">Увага! Якщо данні в цьому вікні, або в завантаженому сертифікаті не відповідають тому, що написано на роздрукованому сертифікаті — сертифікат є підробкою!</span>
      <span class="alert-banner__text">Увага! Якщо данні в цьому вікні, або в завантаженому сертифікаті не відповідають тому, що написано на роздрукованому сертифікаті — сертифікат є підробкою!</span>
    </div>
  </div>
</div>

<main class="container">
