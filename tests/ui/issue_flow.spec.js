const { test, expect } = require('@playwright/test');
const { login } = require('./_helpers');

test.describe('Issuance flow (client-side generation + register)', () => {
  test('issue certificate end-to-end with PDF download & QR load', async ({ page }) => {
    await login(page);
    await page.goto('/issue_token.php');
    await page.waitForSelector('#issueForm');
    const today = new Date().toISOString().slice(0,10);
    await page.fill('input[name="pib"]', 'ТЕСТ КОРИСТУВАЧ');
    await page.fill('input[name="course"]', 'COURSE-PLAY');
    await page.fill('input[name="grade"]', 'A');
    await page.fill('input[name="date"]', today);
    // Ensure infinite checkbox is checked and hidden valid_until input disabled
    await expect(page.locator('input[name="infinite"]')).toBeChecked();
    const downloadPromise = page.waitForEvent('download');
    await page.click('#issueForm button[type="submit"]');
    // Wait summary
    await page.waitForSelector('#summary:has-text("Сертифікат створено")');
    // Wait for QR image natural size (may be hidden by CSS initially)
    await page.waitForFunction(() => {
      const img = document.getElementById('qrImg');
      return img && img.complete && img.naturalWidth > 0;
    });
    const download = await downloadPromise; // should resolve when PDF auto-download triggers
    const suggested = download.suggestedFilename();
    expect(suggested).toMatch(/certificate_/);
  });
});
