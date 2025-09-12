const { test, expect } = require('./fixtures');
const { login } = require('./_helpers');

test('bulk per-row PDF & JPG buttons appear for each successful row', async ({ page }) => {
  await login(page);
  await page.goto('/issue_token.php');
  await page.click('.tab[data-tab="bulk"]');
  await page.waitForSelector('#bulkForm');
  const today = new Date().toISOString().slice(0,10);
  await page.fill('#bulkForm input[name="course"]', 'COURSE-BTNS');
  await page.fill('#bulkForm input[name="date"]', today);
  await page.click('#addRowBtn');
  await page.fill('#bulkTable tbody tr:nth-child(1) input[name="name"]', 'Кнопка Один');
  await page.fill('#bulkTable tbody tr:nth-child(1) input[name="grade"]', 'A');
  await page.fill('#bulkTable tbody tr:nth-child(2) input[name="name"]', 'Кнопка Два');
  await page.fill('#bulkTable tbody tr:nth-child(2) input[name="grade"]', 'B');
  await page.click('#bulkGenerateBtn');
  await page.waitForSelector('#bulkProgressBar.done', { timeout: 20000 });
  await page.waitForSelector('#bulkTable tbody tr:nth-child(1) .status-badge.ok');
  await page.waitForSelector('#bulkTable tbody tr:nth-child(2) .status-badge.ok');
  // Manual batch PDF generation now
  await page.waitForSelector('#bulkBatchPdfBtn');
  await page.waitForTimeout(2000);
  const downloadPromise = page.waitForEvent('download', { timeout: 120000 });
  await page.click('#bulkBatchPdfBtn');
  await downloadPromise;
  // In results list, each successful line should have two buttons (PDF + JPG)
  const line1 = page.locator('#bulkResultLines div').nth(0);
  const line2 = page.locator('#bulkResultLines div').nth(1);
  await expect(line1.locator('button[data-act="pdf"]')).toBeVisible();
  await expect(line1.locator('button[data-act="jpg"]')).toBeVisible();
  await expect(line2.locator('button[data-act="pdf"]')).toBeVisible();
  await expect(line2.locator('button[data-act="jpg"]')).toBeVisible();
});
