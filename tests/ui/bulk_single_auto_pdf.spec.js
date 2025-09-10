const { test, expect } = require('./fixtures');
const { login } = require('./_helpers');

test('single-row bulk auto PDF (not batch) triggers certificate_ download', async ({ page }) => {
  await login(page);
  await page.goto('/issue_token.php');
  await page.click('.tab[data-tab="bulk"]');
  await page.waitForSelector('#bulkForm');
  const today = new Date().toISOString().slice(0,10);
  await page.fill('#bulkForm input[name="course"]', 'COURSE-SINGLEBULK');
  await page.fill('#bulkForm input[name="date"]', today);
  // Per-row grade is required now (no default grade field)
  await page.fill('#bulkTable tbody tr:nth-child(1) input[name="grade"]', 'A');
  await page.fill('#bulkTable tbody tr:nth-child(1) input[name="name"]', 'Одно Рядок');
  const downloadPromise = page.waitForEvent('download');
  await page.click('#bulkGenerateBtn');
  await page.waitForSelector('#bulkProgressBar.done', { timeout: 15000 });
  await page.waitForSelector('#bulkTable tbody tr:nth-child(1) .status-badge.ok');
  const dl = await downloadPromise;
  expect(dl.suggestedFilename()).toMatch(/certificate_/);
  // Batch PDF button should NOT appear for single success
  await expect(page.locator('#bulkBatchPdfBtn')).toHaveCount(0);
});
