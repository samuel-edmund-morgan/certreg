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
  <h2>Видача нагород (ПІБ не зберігається у БД)</h2>
  <div class="tabs" role="tablist" aria-label="Режим видачі">
  <button type="button" class="tab active" role="tab" aria-selected="true" data-tab="single">Одна нагорода</button>
    <button type="button" class="tab" role="tab" aria-selected="false" data-tab="bulk">Масова генерація</button>
  </div>
  <div id="singleTab" class="tab-panel" role="tabpanel" aria-labelledby="single" data-panel="single">
  <p class="maxw-760 fs-14 lh-14">ПІБ не зберігається на сервері. Після створення зображення нагорода автоматично завантажиться. За потреби можна відкрити технічні деталі (CID, hash, QR payload) для аудиту.</p>
  <form id="issueForm" class="form flex-col gap-12 maxw-760" autocomplete="off">
    <label>ПІБ (тільки в зображенні)
      <input type="text" name="pib" required placeholder="Прізвище Ім'я" autocomplete="off">
    </label>
    <label>Додаткова інформація (необов’язково)
      <input type="text" name="extra" placeholder="напр., Номінація — Стійкість" maxlength="255">
      <span class="fs-12 text-muted">Це поле зберігається у БД та додається у QR-пейлоад (без ПІБ). Якщо не потрібно — залиште порожнім.</span>
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
    <div id="summary" class="fs-14 maxw-760"></div>
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
  </div><!-- /singleTab -->

  <div id="bulkTab" class="tab-panel d-none" role="tabpanel" aria-labelledby="bulk" data-panel="bulk">
  <p class="maxw-760 fs-14 lh-14">Масова видача нагород: імена не зберігаються; для кожного рядка клієнт локально обчислює HMAC. Спільні поля застосовуються до всіх. Обмеження: максимум 100 записів за один запуск.</p>
    <form id="bulkForm" class="form flex-col gap-14 maxw-760" autocomplete="off">
  <fieldset class="flex flex-wrap gap-12">
        <label class="minw-200">Дата проходження
          <input type="date" name="date" required>
        </label>
        <label class="minw-200 flex-1">Додаткова інформація (спільна, необов’язково)
          <input type="text" name="extra" placeholder="напр., Номінація — Стійкість" maxlength="255">
        </label>
      </fieldset>
      <div class="flex flex-wrap gap-12 align-center" id="bulkExpiryWrap">
        <label class="flex mb-0 gap-6 align-center nowrap fs-13"><input type="checkbox" name="infinite" checked> <span>Безтерміновий</span></label>
        <div id="bulkValidUntilWrap" class="expiry-slot hidden-slot"><input type="date" name="valid_until" placeholder="YYYY-MM-DD" disabled></div>
      </div>
      <div class="bulk-table-wrapper">
  <table class="table" id="bulkTable" aria-label="Список нагород для генерації">
          <thead>
            <tr><th class="col-n">#</th><th>ПІБ</th><th class="col-status">Статус</th><th class="col-actions"></th></tr>
          </thead>
          <tbody></tbody>
        </table>
        <div class="flex gap-8 mt-8 flex-wrap">
          <button type="button" class="btn" id="addRowBtn">+ Рядок</button>
          <button type="button" class="btn" id="pasteMultiBtn">Вставити список</button>
          <button type="button" class="btn" id="clearAllBtn">Очистити</button>
        </div>
      </div>
      <div class="flex gap-10 flex-wrap mt-14 align-center">
        <button type="button" class="btn btn-success" id="bulkGenerateBtn" disabled>Згенерувати (0)</button>
        <button type="button" class="btn d-none" id="bulkRetryBtn">Повторити невдалі</button>
        <div class="progress-wrap progress-hidden" id="bulkProgressBarWrap" aria-hidden="true">
          <div class="progress-bar" id="bulkProgressBar"></div>
        </div>
        <span class="fs-13 text-muted" id="bulkProgressHint"></span>
      </div>
    </form>
    <div id="bulkResults" class="mt-18 d-none"></div>
  </div><!-- /bulkTab -->
 </section>
 <!-- CSRF token now provided via <meta name="csrf"> in header (no inline script allowed by CSP) -->
 <script src="/assets/js/issue_page.js"></script>
<script src="/assets/js/issue.js"></script>
<script src="/assets/js/issue_bulk.js" defer></script>
<?php require_once __DIR__.'/footer.php'; ?>
