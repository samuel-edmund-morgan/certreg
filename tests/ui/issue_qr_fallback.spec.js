const { test, expect } = require('@playwright/test');
const { login } = require('./_helpers');

// Simulate QR generation failure (404) and ensure UI still shows download buttons.
test('issue: fallback when QR 404 still provides manual download', async ({ page }) => {
  await login(page);
  // Intercept QR requests and return 404
  await page.route('**/qr.php**', route => route.fulfill({ status: 404, body: 'NF' }));
  await page.goto('/issue_token.php');
  await page.fill('#issueForm input[name="pib"]', 'Петро БезQR');
  await page.fill('#issueForm input[name="extra"]', 'NOQR');
  const today = new Date().toISOString().slice(0,10);
  await page.fill('#issueForm input[name="date"]', today);
  await page.check('#issueForm input[name="infinite"]');
  await page.click('#issueForm button[type="submit"]');
  // Even if QR fails to load, ensure manual buttons appear (ensureDownloadButtons)
  await expect(page.locator('#summary #manualPdfBtn')).toBeVisible();
  await expect(page.locator('#summary #manualJpgBtn')).toBeVisible();
});
