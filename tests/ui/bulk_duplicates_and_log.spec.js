const { test, expect } = require('./fixtures');
const { login } = require('./_helpers');

test('duplicate detection adds dup class & error log visible (0)', async ({ page }) => {
  test.setTimeout(120000); // 2 minute timeout for this test

  await login(page);
  await page.goto('/issue_token.php?test_mode=1');
  await page.click('.tab[data-tab="bulk"]');
  await page.waitForSelector('#bulkForm');
  const today = new Date().toISOString().slice(0,10);
  await page.fill('#bulkForm input[name="course"]', 'COURSE-DUPS');
  await page.fill('#bulkForm input[name="date"]', today);
  // Add second row
  await page.click('#addRowBtn');
  await page.fill('#bulkTable tbody tr:nth-child(1) input[name="name"]', 'Дуплікейт Тест');
  await page.fill('#bulkTable tbody tr:nth-child(1) input[name="grade"]', 'A');
  await page.fill('#bulkTable tbody tr:nth-child(2) input[name="name"]', 'дуплікейт   тест'); // normalized same
  await page.fill('#bulkTable tbody tr:nth-child(2) input[name="grade"]', 'A');
  // Trigger generation (will block until names valid, both valid)
  await page.click('#bulkGenerateBtn');
  await page.waitForSelector('#bulkProgressBar.done', { timeout: 20000 });
  await page.waitForSelector('#bulkTable tbody tr:nth-child(1).dup');
  await page.waitForSelector('#bulkTable tbody tr:nth-child(2).dup');

  // Manual batch PDF: click after dup detection
  await expect(page.locator('#bulkBatchPdfBtn')).toBeVisible();
  await expect(page.locator('#bulkBatchPdfBtn')).toBeEnabled();

  // New robust download logic
  const downloadPromise = page.waitForEvent('download', { timeout: 60000 });
  await page.locator('#bulkBatchPdfBtn').click();
  const downloadLink = page.locator('#manualBatchDownloadLink');
  await downloadLink.waitFor({ state: 'attached', timeout: 30000 });
  const downloadUrl = await downloadLink.getAttribute('href');
  expect(downloadUrl).not.toBeNull();
  try {
    await page.goto(downloadUrl, { waitUntil: 'domcontentloaded', timeout: 5000 });
  } catch (error) {
    if (!error.message.includes('Download is starting')) {
      throw error;
    }
  }
  await downloadPromise;

  // Error log box always visible now
  await page.waitForSelector('#bulkErrorLog');
  const logText = await page.locator('#bulkErrorLog').innerText();
  expect(logText).toMatch(/Лог помилок/);
});
