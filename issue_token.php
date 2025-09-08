<?php
require_once __DIR__.'/auth.php';
require_admin();
require_csrf();
$isAdminPage = true;
$cfg = require __DIR__.'/config.php';
$csrf = csrf_token();
require_once __DIR__.'/header.php';
// Expose coords to JS (fallback defaults if missing)
$coords = $cfg['coords'] ?? [];
?>
<section class="section">
  <h2>Видача (анонімна модель без ПІБ у БД)</h2>
  <p class="maxw-720 fs-14 lh-14">ПІБ не зберігається на сервері. Після створення зображення сертифікат автоматично завантажиться. За потреби можна відкрити технічні деталі (CID, hash, QR payload) для аудиту.</p>
  <form id="issueForm" class="form flex-col gap-12 maxw-520" autocomplete="off">
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
    <div class="mb-12" id="expiryBlock">
      <div class="fw-600 fs-14 mb-4">Дійсний до</div>
      <div class="expiry-row" id="expiryRow">
        <label class="flex mb-0 gap-6 align-center nowrap fs-13" id="infiniteWrap">
          <input type="checkbox" name="infinite" checked>
          <span class="nowrap">Безтерміновий</span>
        </label>
        <div id="validUntilWrap" class="expiry-slot hidden-slot">
          <input type="date" name="valid_until" placeholder="YYYY-MM-DD" disabled>
        </div>
      </div>
    </div>
    <div class="flex flex-wrap gap-10">
      <button class="btn btn-success" type="submit" id="generateBtn">Згенерувати</button>
      <button class="btn d-none" type="button" id="toggleDetails">Показати технічні деталі</button>
      <button class="btn" type="button" id="resetBtn">Новий</button>
    </div>
  </form>
  <div id="result" class="mt-24 d-none">
    <div id="summary" class="fs-14"></div>
    <div id="advanced" class="d-none mt-18">
      <h3 class="mt-0">Технічні деталі</h3>
      <div id="regMeta" class="fs-13 lh-14 mb-12"></div>
      <div class="flex flex-wrap gap-32 align-start">
        <div>
          <canvas id="certCanvas" width="1000" height="700" class="canvas-preview"></canvas>
          <p class="fs-12 text-muted mt-4">Попередній перегляд (локально сформовано)</p>
        </div>
        <div>
          <h4 class="mt-0 mb-12">QR payload</h4>
          <code id="qrPayload" class="code-box"></code>
          <h4 class="mt-18 mb-12">QR</h4>
          <img id="qrImg" alt="QR" class="qr-img" />
        </div>
      </div>
    </div>
  </div>
</section>
<script>window.__CSRF_TOKEN = '<?= htmlspecialchars($csrf) ?>';</script>
<script src="/assets/js/issue_page.js"></script>
<script src="/assets/js/issue.js"></script>
<?php require_once __DIR__.'/footer.php'; ?>
