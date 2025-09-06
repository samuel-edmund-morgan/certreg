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
  <p style="max-width:720px;font-size:14px;line-height:1.4">ПІБ не зберігається на сервері. Після створення зображення сертифікат автоматично завантажиться. За потреби можна відкрити технічні деталі (CID, hash, QR payload) для аудиту.</p>
  <form id="issueForm" class="form" autocomplete="off" style="max-width:520px;display:flex;flex-direction:column;gap:12px">
    <label>ПІБ (тільки в зображенні)
      <input type="text" name="pib" required placeholder="Прізвище Ім'я" autocomplete="off">
    </label>
    <label>Курс
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
      <button class="btn" type="button" id="toggleDetails" style="display:none">Показати технічні деталі</button>
      <button class="btn" type="button" id="resetBtn">Новий</button>
    </div>
  </form>
  <div id="result" style="margin-top:24px;display:none">
    <div id="summary" style="font-size:14px"></div>
    <div id="advanced" style="display:none;margin-top:18px">
      <h3 style="margin-top:0">Технічні деталі</h3>
      <div id="regMeta" style="font-size:13px;line-height:1.4;margin-bottom:12px"></div>
      <div style="display:flex;flex-wrap:wrap;gap:32px;align-items:flex-start">
        <div>
          <canvas id="certCanvas" width="1000" height="700" style="border:1px solid #e2e8f0;border-radius:8px;max-width:100%;height:auto"></canvas>
          <p style="font-size:12px;color:#475569;margin-top:4px">Попередній перегляд (локально сформовано)</p>
        </div>
        <div>
          <h4 style="margin:0 0 6px">QR payload</h4>
          <code id="qrPayload" style="display:block;max-width:340px;white-space:pre-wrap;word-break:break-all;font-size:11px;background:#f1f5f9;padding:8px;border-radius:6px"></code>
          <h4 style="margin:16px 0 6px">QR</h4>
          <img id="qrImg" alt="QR" style="width:220px;height:220px;border:1px solid #e2e8f0;border-radius:8px;object-fit:contain" />
        </div>
      </div>
    </div>
  </div>
</section>
<script>
window.__CERT_COORDS = <?= json_encode($coords, JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="/assets/js/issue.js"></script>
<?php require_once __DIR__.'/footer.php'; ?>
