<?php
require_once __DIR__.'/auth.php';
require_admin();
$isAdminPage = true;
$cfg = require __DIR__.'/config.php';
require_once __DIR__.'/header.php';
// Expose coords to JS (fallback defaults if missing)
$coords = $cfg['coords'] ?? [];
?>
<section class="section">
  <h2>Видача (анонімна модель без ПІБ у БД)</h2>
  <p style="max-width:720px;font-size:14px;line-height:1.4">Ця форма НЕ надсилає ПІБ на сервер. Хеш та метадані (курс, оцінка, дата) реєструються через /api/register. QR та зображення сертифіката формуються локально. Надрукуйте або збережіть результат — відновити ПІБ з бази буде неможливо.</p>
  <form id="issueForm" class="form" autocomplete="off" style="max-width:520px;display:flex;flex-direction:column;gap:12px">
    <label>ПІБ (буде лише у зображенні)
      <input type="text" name="pib" required placeholder="Прізвище Ім'я" autocomplete="off">
    </label>
    <label>Курс (ідентифікатор / коротко)
      <input type="text" name="course" required placeholder="COURSE-101">
    </label>
    <label>Оцінка
      <input type="text" name="grade" required placeholder="A" maxlength="16">
    </label>
    <label>Дата проходження
      <input type="date" name="date" required>
    </label>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <button class="btn btn-success" type="submit">Згенерувати</button>
      <button class="btn" type="button" id="downloadJpg" disabled>Завантажити JPG</button>
      <button class="btn" type="button" id="resetBtn">Скинути</button>
    </div>
  </form>
  <div id="result" style="margin-top:24px;display:none">
    <h3>Результат</h3>
    <div id="regMeta" style="font-size:14px"></div>
    <div style="display:flex;flex-wrap:wrap;gap:32px;margin-top:16px;align-items:flex-start">
      <div>
        <canvas id="certCanvas" width="1000" height="700" style="border:1px solid #e2e8f0;border-radius:8px;max-width:100%;height:auto"></canvas>
        <p style="font-size:12px;color:#475569;margin-top:4px">Попередній перегляд сертифіката (локально сформовано)</p>
      </div>
      <div>
        <h4>QR payload</h4>
        <code id="qrPayload" style="display:block;max-width:340px;white-space:pre-wrap;word-break:break-all;font-size:11px;background:#f1f5f9;padding:8px;border-radius:6px"></code>
        <h4 style="margin-top:16px">QR</h4>
        <img id="qrImg" alt="QR" style="width:220px;height:220px;border:1px solid #e2e8f0;border-radius:8px;object-fit:contain" />
      </div>
    </div>
  </div>
</section>
<script>
window.__CERT_COORDS = <?= json_encode($coords, JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="/assets/js/issue.js"></script>
<?php require_once __DIR__.'/footer.php'; ?>
