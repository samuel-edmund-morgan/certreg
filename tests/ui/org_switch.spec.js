const { test, expect } = require('./fixtures');
const { login } = require('./_helpers');

function nowCode(){
  const base = Date.now().toString(36).toUpperCase();
  return `AT${base}`;
}

test('admin can create, toggle and delete an organization', async ({ page }) => {
  await login(page);
  await page.goto('/settings.php?tab=organizations', { waitUntil: 'networkidle' });

  const name = `Автотест організація ${Date.now()}`;
  const code = nowCode();

  const nameInput = page.locator('#orgCreateForm input[name="name"]');
  const codeInput = page.locator('#orgCreateForm input[name="code"]');
  const submitBtn = page.locator('#orgCreateBtn');
  const status = page.locator('#orgCreateStatus');

  await expect(nameInput).toBeVisible();
  await nameInput.fill(name);
  await codeInput.fill(code);

  await submitBtn.click();
  await expect(status).toHaveText('Створено', { timeout: 8000 });

  const row = page.locator('#orgsTable tbody tr', { has: page.locator('td code', { hasText: code }) });
  await expect(row).toBeVisible({ timeout: 8000 });
  await expect(row).toContainText('Активна');

  const toggleBtn = row.locator('.toggle-org');
  await expect(toggleBtn).toHaveCount(1);

  await toggleBtn.click();
  await expect(row).toContainText('Вимкнена', { timeout: 6000 });

  await row.locator('.toggle-org').click();
  await expect(row).toContainText('Активна', { timeout: 6000 });

  const deleteBtn = row.locator('.del-org');
  await expect(deleteBtn).toHaveCount(1);
  page.once('dialog', dialog => dialog.accept());
  await deleteBtn.click();
  await expect(row).toHaveCount(0, { timeout: 8000 });
});
