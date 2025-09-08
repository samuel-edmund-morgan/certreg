const { expect } = require('@playwright/test');

async function login(page, username='testadmin', password='testpass'){
  await page.goto('/admin.php');
  await page.waitForSelector('form[action="/login.php"]');
  await page.fill('input[name="username"]', username);
  await page.fill('input[name="password"]', password);
  await Promise.all([
    page.waitForNavigation(),
    page.click('button[type="submit"]')
  ]);
  // After login we expect redirect to tokens.php
  await expect(page.url()).toMatch(/tokens\.php$/);
  // Confirm topbar buttons visible (Видача / Токени / Аудит / Вийти)
  await expect(page.locator('.topbar__actions .btn')).toHaveCount(4);
}

module.exports = { login };
