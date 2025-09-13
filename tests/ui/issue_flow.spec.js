const { test, expect } = require('./fixtures');
const { login } = require('./_helpers');

test.describe('Issuance flow (client-side generation + register)', () => {
  test('issue certificate end-to-end with PDF download & QR load', async ({ page }) => {
    await login(page);
    await page.goto('/issue_token.php');
    await page.waitForSelector('#issueForm');
    const today = new Date().toISOString().slice(0,10);
    await page.fill('input[name="pib"]', 'ТЕСТ КОРИСТУВАЧ');
  await page.fill('input[name="extra"]', 'COURSE-PLAY');
    await page.fill('input[name="date"]', today);
  // Ensure single-issuance infinite checkbox (in expiryBlock) is checked
  await expect(page.locator('#expiryBlock input[name="infinite"]')).toBeChecked();
    const downloadPromise = page.waitForEvent('download');
    await page.click('#issueForm button[type="submit"]');
    // Wait summary
  await page.waitForSelector('#summary:has-text("Нагороду створено")');
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
