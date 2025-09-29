const { test, expect } = require('./fixtures');
const { login } = require('./_helpers');

function nowCode(){
  const base = Date.now().toString(36).toUpperCase();
  return `AT${base}`;
}

test('admin can manage organization lifecycle with validation and branding updates', async ({ page }) => {
  await login(page);
  await page.goto('/settings.php?tab=organizations', { waitUntil: 'networkidle' });

  const name = `Автотест організація ${Date.now()}`;
  const code = nowCode();
  const primaryHex = '#12ab34';
  const accentHex = '#bada55';

  const nameInput = page.locator('#orgCreateForm input[name="name"]');
  const codeInput = page.locator('#orgCreateForm input[name="code"]');
  const submitBtn = page.locator('#orgCreateBtn');
  const status = page.locator('#orgCreateStatus');

  await expect(nameInput).toBeVisible();
  // Validation: malformed code should surface server-side errors
  await page.evaluate(() => {
    const input = document.querySelector('#orgCreateForm input[name="code"]');
    if (input) input.removeAttribute('pattern');
  });
  await nameInput.fill(`${name} invalid`);
  await codeInput.fill('bad code');
  await submitBtn.click();
  await expect(status).toHaveText('Помилка', { timeout: 8000 });
  await expect(status).toHaveText('', { timeout: 8000 });
  await page.evaluate(() => {
    const input = document.querySelector('#orgCreateForm input[name="code"]');
    if (input) input.setAttribute('pattern', '[A-Z0-9_\\-]{2,32}');
  });

  // Successful creation path
  await nameInput.fill(name);
  await codeInput.fill(code);
  await submitBtn.click();
  await expect(status).toHaveText('Створено', { timeout: 8000 });

  const row = page.locator('#orgsTable tbody tr', { has: page.locator('td code', { hasText: code }) });
  await expect(row).toBeVisible({ timeout: 8000 });
  await expect(row).toContainText('Активна');

  // Duplicate code should be rejected with an error message and no extra row
  await nameInput.fill(`${name} копія`);
  await codeInput.fill(code);
  await submitBtn.click();
  await expect(status).toHaveText('Помилка', { timeout: 8000 });
  await expect(status).toHaveText('', { timeout: 8000 });
  await expect(page.locator('#orgsTable tbody tr', { has: page.locator('td code', { hasText: code }) })).toHaveCount(1);

  // Navigate to dedicated page and update branding colors (lowercase hex → uppercase normalization)
  await row.locator('.edit-org').click();
  await page.waitForURL(/\/organization\.php\?id=\d+$/);

  const primaryInput = page.locator('#orgUpdateForm input[name="primary_color"]');
  const accentInput = page.locator('#orgUpdateForm input[name="accent_color"]');
  const secondaryInput = page.locator('#orgUpdateForm input[name="secondary_color"]');
  const updateStatus = page.locator('#orgUpdateStatus');
  await expect(primaryInput).toBeVisible();

  await primaryInput.fill(primaryHex);
  await accentInput.fill(accentHex);
  await secondaryInput.fill('');
  await page.locator('#orgUpdateBtn').click();
  await expect(updateStatus).toHaveText('Збережено', { timeout: 8000 });
  await expect(updateStatus).toHaveText('', { timeout: 8000 });

  await page.goto('/settings.php?tab=organizations', { waitUntil: 'networkidle' });
  const updatedRow = page.locator('#orgsTable tbody tr', { has: page.locator('td code', { hasText: code }) });
  await expect(updatedRow).toBeVisible({ timeout: 8000 });
  await expect(updatedRow.locator('svg[aria-label="primary"] circle')).toHaveAttribute('fill', primaryHex.toUpperCase());
  await expect(updatedRow.locator('svg[aria-label="accent"] circle')).toHaveAttribute('fill', accentHex.toUpperCase());
  await expect(updatedRow.locator('svg[aria-label="secondary"] circle')).toHaveCount(0);

  const toggleBtn = updatedRow.locator('.toggle-org');
  await expect(toggleBtn).toHaveCount(1);

  await toggleBtn.click();
  await expect(updatedRow).toContainText('Вимкнена', { timeout: 6000 });

  await updatedRow.locator('.toggle-org').click();
  await expect(updatedRow).toContainText('Активна', { timeout: 6000 });

  const deleteBtn = updatedRow.locator('.del-org');
  await expect(deleteBtn).toHaveCount(1);
  page.once('dialog', dialog => dialog.accept());
  await deleteBtn.click();
  await expect(updatedRow).toHaveCount(0, { timeout: 8000 });
});
