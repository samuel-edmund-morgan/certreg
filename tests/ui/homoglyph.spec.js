const { test, expect } = require('./fixtures');
const { login } = require('./_helpers');

test.describe('Homoglyph detection', () => {
  test('mixing Latin and Cyrillic risks triggers alert and blocks registration', async ({ page }) => {
    await login(page);
    await page.goto('/issue_token.php');
    await page.waitForSelector('#issueForm');
    const today = new Date().toISOString().slice(0,10);
    // Name with Latin A + Cyrillic letters (first char Latin A U+0041, second Cyrillic А U+0410)
  await page.fill('input[name="pib"]', 'AАндрій Тест');
    await page.fill('input[name="date"]', today);
    const dialogPromise = new Promise(resolve => page.once('dialog', d => { d.accept(); resolve(d.message()); }));
    await page.click('#issueForm button[type="submit"]');
    const msg = await dialogPromise;
    expect(msg).toMatch(/латинські символи/i);
    // Result section should still be hidden
    const resultHidden = await page.locator('#result').evaluate(el => el.classList.contains('d-none'));
    expect(resultHidden).toBeTruthy();
  });
});
