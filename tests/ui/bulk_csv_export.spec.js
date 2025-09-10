const { test, expect } = require('./fixtures');
const { login } = require('./_helpers');

test('bulk CSV export has BOM and correct headers', async ({ page }) => {
  await login(page);
  await page.goto('/issue_token.php');
  await page.click('.tab[data-tab="bulk"]');
  await page.waitForSelector('#bulkForm');
  const today = new Date().toISOString().slice(0,10);
  await page.fill('#bulkForm input[name="course"]', 'COURSE-CSV');
  await page.fill('#bulkForm input[name="date"]', today);
  // Add second row
  await page.click('#addRowBtn');
  // Avoid Latin letters that trigger mixed script risk detection
  await page.fill('#bulkTable tbody tr:nth-child(1) input[name="name"]', 'Кириличний Перший');
  await page.fill('#bulkTable tbody tr:nth-child(1) input[name="grade"]', 'A');
  await page.fill('#bulkTable tbody tr:nth-child(2) input[name="name"]', 'Кириличний Другий');
  await page.fill('#bulkTable tbody tr:nth-child(2) input[name="grade"]', 'B');
  // Wait until button enabled and shows count (2)
  await page.waitForFunction(() => {
    const btn = document.getElementById('bulkGenerateBtn');
    return btn && !btn.disabled && /\(2\)/.test(btn.textContent);
  });
  await page.click('#bulkGenerateBtn');
  await page.waitForSelector('#bulkProgressBar.done', { timeout: 20000 });
  // Wait both OK
  await page.waitForSelector('#bulkTable tbody tr:nth-child(1) .status-badge.ok');
  await page.waitForSelector('#bulkTable tbody tr:nth-child(2) .status-badge.ok');
  // Batch PDF auto-download may occur; ignore it (don't wait) – proceed directly
  // Trigger CSV export
  const csvPromise = page.waitForEvent('download');
  await page.click('#bulkCsvBtn');
  const csvDownload = await csvPromise;
  const filename = csvDownload.suggestedFilename();
  expect(filename).toMatch(/bulk_issue_/);
  const path = await csvDownload.path();
  if(path){
    const fs = require('fs');
    const buf = fs.readFileSync(path);
    // BOM check (0xEF 0xBB 0xBF)
    expect(buf[0]).toBe(0xEF);
    expect(buf[1]).toBe(0xBB);
    expect(buf[2]).toBe(0xBF);
  const text = buf.toString('utf8');
  // Remove potential BOM from the decoded string (already validated raw bytes above)
  const firstLine = text.split(/\r?\n/)[0].replace(/^\uFEFF/, '');
  expect(firstLine).toBe('NAME_ORIG,CID,INT,COURSE,GRADE,ISSUED_DATE,VALID_UNTIL');
  }
});
