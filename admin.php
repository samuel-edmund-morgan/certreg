<?php
require_once __DIR__.'/auth.php';
if(!is_admin_logged()){
  $hideAlertBanner = true;
  require_once __DIR__.'/header.php';
  ?>
  <section class="centered">
    <div class="card card--narrow">
      <h1 class="card__title">Вхід адміністратора</h1>
      <?php if(!empty($_GET['err'])): ?><div class="alert alert-error">Невірні облікові дані.</div><?php endif; ?>
      <form class="form" method="post" action="/login.php" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
        <label>Логін <input type="text" name="username" required></label>
        <label>Пароль <input type="password" name="password" required></label>
        <button class="btn btn-primary" type="submit">Увійти</button>
      </form>
    </div>
  </section>
    <?php require_once __DIR__.'/footer.php';
    exit;
  }
  header('Location: /tokens.php');
  exit;