<?php
require_once __DIR__.'/auth.php';
require_admin();
$isAdminPage = true; // for header body class & admin.js
require_once __DIR__.'/db.php';
$cfg = require __DIR__.'/config.php';
$csrf = csrf_token();

$id = (int)($_GET['id'] ?? 0);
if($id <= 0){ header('Location: /settings.php?tab=organizations'); exit; }

// Helper to fetch organization row
function load_org(PDO $pdo, int $id){
    $st = $pdo->prepare('SELECT id,name,code,logo_path,favicon_path,primary_color,accent_color,secondary_color,footer_text,support_contact,is_active,created_at,updated_at FROM organizations WHERE id=? LIMIT 1');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

$org = load_org($pdo,$id);
if(!$org){ header('Location: /settings.php?tab=organizations&msg=org_nf'); exit; }

// Determine default (immutable code + cannot delete/toggle?)
$defaultCode = $cfg['org_code'] ?? '';
$isDefault = ($org['code'] === $defaultCode);

$msg = $_GET['msg'] ?? '';

// POST actions (toggle active / delete) handled locally to avoid extra JS dependencies.
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!isset($_POST['_csrf']) || !hash_equals($_SESSION['csrf'] ?? '', $_POST['_csrf'])){ http_response_code(403); exit('CSRF'); }
    $action = $_POST['action'] ?? '';
    $pid = (int)($_POST['id'] ?? 0);
    if($pid !== $id){ header('Location: organization.php?id='.$id.'&msg=badid'); exit; }
    if($action==='toggle' && !$isDefault){
        try {
            $new = $org['is_active']?0:1;
            $pdo->prepare('UPDATE organizations SET is_active=? WHERE id=? LIMIT 1')->execute([$new,$id]);
            header('Location: organization.php?id='.$id.'&msg=toggled'); exit;
        } catch(Throwable $e){ header('Location: organization.php?id='.$id.'&msg=err'); exit; }
    } elseif($action==='delete' && !$isDefault){
        try {
            // Safety: block delete if operators or tokens exist referencing this org
            $chk1 = $pdo->prepare('SELECT 1 FROM creds WHERE org_id=? LIMIT 1'); $chk1->execute([$id]);
            if($chk1->fetch()){ header('Location: organization.php?id='.$id.'&msg=has_ops'); exit; }
            $chk2 = $pdo->prepare('SELECT 1 FROM certs WHERE org_id=? LIMIT 1'); $chk2->execute([$id]);
            if($chk2->fetch()){ header('Location: organization.php?id='.$id.'&msg=has_tokens'); exit; }
            $pdo->prepare('DELETE FROM organizations WHERE id=? LIMIT 1')->execute([$id]);
            header('Location: settings.php?tab=organizations&msg=org_deleted'); exit;
        } catch(Throwable $e){ header('Location: organization.php?id='.$id.'&msg=err'); exit; }
    } elseif($action==='update'){
        // We use org_update.php for validation/upload handling. Include it internally to reuse logic.
        // Because org_update.php echoes JSON, we'll call it via curl-like internal request? Simpler: replicate minimal logic here.
        // To keep DRY but avoid major refactor, submit form with enctype multipart to org_update.php via hidden iframe approach? Instead, we just redirect advising to use fetch soon.
        // For now we rely on separate AJAX script (defer) to process the main update form - POST here only for toggle/delete.
        header('Location: organization.php?id='.$id.'&msg=unsupported'); exit;
    } else {
        header('Location: organization.php?id='.$id.'&msg=unknown'); exit;
    }
}
// Refresh row in case of POST changes
$org = load_org($pdo,$id);

require_once __DIR__.'/header.php';
?>
<section class="section">
  <h1 class="mt-0">Організація #<?= (int)$org['id'] ?></h1>
  <p class="fs-14 text-muted maxw-760">Керування окремою організацією. Повернутися до списку – <a href="/settings.php?tab=organizations" class="link-accent">організації</a>.</p>
  <?php if($msg): ?>
    <div class="mb-12 fs-13 <?php if(in_array($msg,['err','badid'])) echo 'text-danger'; ?>">
      <?php
        $map = [
          'toggled'=>'Статус змінено',
          'org_nf'=>'Не знайдено',
          'org_deleted'=>'Видалено',
          'has_ops'=>'Неможливо видалити: оператори ще привʼязані',
          'has_tokens'=>'Неможливо: сертифікати існують',
          'badid'=>'ID не збігається',
          'err'=>'Внутрішня помилка',
          'unknown'=>'Невідома дія',
          'unsupported'=>'Оновлення виконується через форму нижче (AJAX)'
        ];
        echo htmlspecialchars($map[$msg] ?? $msg);
      ?>
    </div>
  <?php endif; ?>
  <div class="details-grid mb-24">
    <div>ID</div><div class="mono"><?= (int)$org['id'] ?></div>
    <div>Код</div><div><code><?= htmlspecialchars($org['code']) ?></code><?= $isDefault? ' <span class="badge" title="Базова організація">Основна</span>':'' ?></div>
    <div>Назва</div><div><?= nl2br(htmlspecialchars($org['name'])) ?></div>
    <div>Статус</div><div><?= $org['is_active']?'<span class="badge badge-success">активна</span>':'<span class="badge badge-danger">вимкнена</span>' ?></div>
    <div>Створено</div><div><?= htmlspecialchars(str_replace('T',' ',$org['created_at'])) ?></div>
    <div>Оновлено</div><div><?= htmlspecialchars(str_replace('T',' ',$org['updated_at'])) ?></div>
  </div>
  <h2 class="mt-0 fs-18">Оновити властивості</h2>
  <form id="orgUpdateForm" class="form" method="post" action="/api/org_update.php" enctype="multipart/form-data" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="id" value="<?= (int)$org['id'] ?>">
    <label>Назва
      <input class="input" type="text" name="name" value="<?= htmlspecialchars($org['name']) ?>" required maxlength="255">
    </label>
    <label>Код (immutable)
      <input class="input" type="text" value="<?= htmlspecialchars($org['code']) ?>" disabled readonly>
    </label>
    <label>Primary колір
      <div class="flex gap-8 align-center color-field">
        <input class="input color-hex" data-color-peer="org_u_primary_picker" type="text" name="primary_color" value="<?= htmlspecialchars($org['primary_color'] ?? '') ?>" pattern="#?[0-9A-Fa-f]{6}" maxlength="7" placeholder="#102d4e">
        <input type="color" id="org_u_primary_picker" class="color-picker" value="<?= htmlspecialchars($org['primary_color'] ?: '#102d4e') ?>" aria-label="Primary color picker">
      </div>
    </label>
    <label>Accent колір
      <div class="flex gap-8 align-center color-field">
        <input class="input color-hex" data-color-peer="org_u_accent_picker" type="text" name="accent_color" value="<?= htmlspecialchars($org['accent_color'] ?? '') ?>" pattern="#?[0-9A-Fa-f]{6}" maxlength="7" placeholder="#2563eb">
        <input type="color" id="org_u_accent_picker" class="color-picker" value="<?= htmlspecialchars($org['accent_color'] ?: '#2563eb') ?>" aria-label="Accent color picker">
      </div>
    </label>
    <label>Secondary колір
      <div class="flex gap-8 align-center color-field">
        <input class="input color-hex" data-color-peer="org_u_secondary_picker" type="text" name="secondary_color" value="<?= htmlspecialchars($org['secondary_color'] ?? '') ?>" pattern="#?[0-9A-Fa-f]{6}" maxlength="7" placeholder="#6b7280">
        <input type="color" id="org_u_secondary_picker" class="color-picker" value="<?= htmlspecialchars($org['secondary_color'] ?: '#6b7280') ?>" aria-label="Secondary color picker">
      </div>
    </label>
    <label>Footer текст
      <input class="input" type="text" name="footer_text" maxlength="255" value="<?= htmlspecialchars($org['footer_text'] ?? '') ?>" placeholder="© <?= date('Y') ?> Організація">
    </label>
    <label>Контакт підтримки
      <input class="input" type="text" name="support_contact" maxlength="255" value="<?= htmlspecialchars($org['support_contact'] ?? '') ?>" placeholder="support@example.com">
    </label>
    <div class="mb-12">
      <div class="fw-600 fs-14 mb-4">Логотип (PNG/JPG/SVG, ≤2MB)</div>
      <?php if(!empty($org['logo_path'])): ?>
        <div class="mb-8 branding-preview branding-preview--logo"><img src="<?= htmlspecialchars($org['logo_path']) ?>" alt="Логотип"></div>
      <?php endif; ?>
      <input class="input" type="file" name="logo_file" accept="image/png,image/jpeg,image/svg+xml">
    </div>
    <div class="mb-12">
      <div class="fw-600 fs-14 mb-4">Favicon (ICO/PNG/SVG, ≤128KB)</div>
      <?php if(!empty($org['favicon_path'])): ?>
        <div class="mb-8 branding-preview branding-preview--favicon"><img src="<?= htmlspecialchars($org['favicon_path']) ?>" alt="Favicon"></div>
      <?php endif; ?>
      <input class="input" type="file" name="favicon_file" accept="image/x-icon,image/png,image/svg+xml">
    </div>
    <div class="flex gap-10 align-center">
      <button class="btn btn-primary" type="submit" id="orgUpdateBtn">Зберегти</button>
      <span id="orgUpdateStatus" class="fs-13 text-muted"></span>
    </div>
  </form>
  <h2 class="fs-18 mt-32">Дії</h2>
  <?php if($isDefault): ?>
    <div class="alert">Це базова організація (визначається у config.php як <code>org_code</code>). Видалення чи вимкнення заборонені.</div>
  <?php else: ?>
    <form method="post" class="form d-inline mb-12">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="id" value="<?= (int)$org['id'] ?>">
      <input type="hidden" name="action" value="toggle">
      <button class="btn btn-light" type="submit"><?= $org['is_active']? 'Вимкнути' : 'Увімкнути' ?></button>
    </form>
    <form method="post" class="form d-inline mb-12" onsubmit="return confirm('Видалити організацію безповоротно?');">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="id" value="<?= (int)$org['id'] ?>">
      <input type="hidden" name="action" value="delete">
      <button class="btn btn-danger" type="submit">Видалити</button>
    </form>
  <?php endif; ?>
  <div class="mt-18"><a href="/settings.php?tab=organizations" class="btn btn-light">← Назад до списку</a></div>
</section>
<script defer src="/assets/js/org_edit.js"></script>
<?php require_once __DIR__.'/footer.php'; ?>
