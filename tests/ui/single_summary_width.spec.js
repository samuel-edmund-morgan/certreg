const { test, expect } = require('./fixtures');
const { login } = require('./_helpers');

test('single issuance summary container has constrained width class', async ({ page }) => {
  await login(page);
  await page.goto('/issue_token.php');
  await page.waitForSelector('#issueForm');
  const today = new Date().toISOString().slice(0,10);
  await page.fill('input[name="pib"]', 'ШИРИНА ТЕСТ');
  await page.fill('input[name="course"]', 'COURSE-WIDTH');
  await page.fill('input[name="grade"]', 'A');
  await page.fill('input[name="date"]', today);
  const downloadPromise = page.waitForEvent('download');
  await page.click('#issueForm button[type="submit"]');
  await page.waitForSelector('#summary:has-text("Нагороду створено")');
  await downloadPromise;
  const hasClass = await page.evaluate(()=> document.getElementById('summary').classList.contains('maxw-760'));
  expect(hasClass).toBeTruthy();
});
