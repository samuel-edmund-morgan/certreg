const { test, expect } = require('./fixtures');
const { login } = require('./_helpers');

test.describe('CSP console', () => {
  test('no CSP style-src errors on issuance submit', async ({ page }) => {
    await login(page);
    const cspErrors = [];
    page.on('console', msg => {
      const t = msg.text();
      if(/Content Security Policy/i.test(t) || /Refused to apply a stylesheet/i.test(t)) cspErrors.push(t);
    });
    await page.goto('/issue_token.php');
    await page.waitForSelector('#issueForm');
    const today = new Date().toISOString().slice(0,10);
    await page.fill('input[name="pib"]', 'ТЕСТ КОРИСТУВАЧ');
    await page.fill('input[name="course"]', 'COURSE-CSP');
    await page.fill('input[name="grade"]', 'A');
    await page.fill('input[name="date"]', today);
    const downloadPromise = page.waitForEvent('download');
    await page.click('#issueForm button[type="submit"]');
  await page.waitForSelector('#summary:has-text("Нагороду створено")');
    await downloadPromise;
    expect(cspErrors).toEqual([]);
  });
});
