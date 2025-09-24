const { expect } = require('@playwright/test');

async function login(page, username='testadmin', password='testpass'){
  // First try tokens page (might already be logged in from previous test's session cookie)
  await page.goto('/tokens.php');
  if(/\/tokens\.php$/.test(page.url())){
    return; // already authenticated
  }
  // Navigate to admin login page
  await page.goto('/admin.php');
  // If redirect already placed us onto tokens, done
  if(/\/tokens\.php$/.test(page.url())) return;
  // Wait for form if present
  const form = page.locator('form[action="/login.php"]');
  if(await form.count()===0){
    // Unexpected page; attempt direct tokens again
    await page.goto('/tokens.php');
    if(/\/tokens\.php$/.test(page.url())) return;
  }
  await form.waitFor();
  await page.fill('input[name="username"]', username);
  await page.fill('input[name="password"]', password);
  await Promise.all([
    page.waitForNavigation({ url: /tokens\.php$/ }),
    page.click('button[type="submit"]')
  ]);
  await expect(page.url()).toMatch(/tokens\.php$/);
  // Allow flexibility: header may include Settings (admin-only), Issue, Tokens, Events, Logout
  const btnCount = await page.locator('.topbar__actions .btn').count();
  expect(btnCount).toBeGreaterThanOrEqual(4);
}

module.exports = { login };
