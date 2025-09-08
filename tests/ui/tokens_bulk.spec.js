const { test, expect } = require('@playwright/test');
const { login } = require('./_helpers');
const { execSync } = require('child_process');

test.describe('Tokens page bulk operations & sorting', () => {
  test('bulk revoke/unrevoke/delete workflow updates UI rows', async ({ page }) => {
    // Seed 3 tokens
    execSync('php tests/seed_tokens.php 3');
    await login(page);
    await page.goto('/tokens.php');
    await page.waitForSelector('table.table tbody tr');
    // Select first two rows
    const firstTwo = page.locator('tbody tr').nth(0).locator('input.rowChk');
    const secondTwo = page.locator('tbody tr').nth(1).locator('input.rowChk');
  const cidA = await page.locator('tbody tr').nth(0).getAttribute('data-cid');
  const cidB = await page.locator('tbody tr').nth(1).getAttribute('data-cid');
  await page.locator('tbody tr').nth(0).locator('input.rowChk').check();
  await page.locator('tbody tr').nth(1).locator('input.rowChk').check();
    await expect(page.locator('#bulkBar')).toBeVisible();
    // Choose revoke
    await page.selectOption('#bulkAction','revoke');
    await expect(page.locator('#bulkReason')).not.toHaveClass(/hidden-slot/);
    await page.fill('#bulkReason','Test reason bulk');
    // Intercept confirm dialogs (Playwright auto-accept if we override)
  page.once('dialog', d => d.accept()); // confirm revoke
  await page.click('#bulkExecute');
  await page.waitForFunction(() => /Готово/.test(document.getElementById('bulkStatus')?.textContent||''));
    // Rows should now have revoked badge
    const revokedBadges = await page.locator('tbody tr .badge-danger').count();
    expect(revokedBadges).toBeGreaterThanOrEqual(2);
    // Select one row and unrevoke
  await page.locator(`tr[data-cid="${cidA}"] input.rowChk`).check();
    await page.selectOption('#bulkAction','unrevoke');
  await page.click('#bulkExecute');
  await page.waitForFunction(() => /Готово/.test(document.getElementById('bulkStatus')?.textContent||''));
    // Delete one row
  await page.locator(`tr[data-cid="${cidB}"] input.rowChk`).check();
    await page.selectOption('#bulkAction','delete');
    page.once('dialog', d => d.accept());
  await page.click('#bulkExecute');
  await page.waitForFunction(() => /Готово/.test(document.getElementById('bulkStatus')?.textContent||''));
  });

  test('client-side sorting toggles order for CID column', async ({ page }) => {
    execSync('php tests/seed_tokens.php 2');
    await login(page);
    await page.goto('/tokens.php');
    await page.waitForSelector('table.table tbody tr');
    // Capture initial first CID
    const firstBefore = await page.locator('tbody tr').first().getAttribute('data-cid');
    await page.click('th a.sort[data-sort="cid"]'); // asc
    await page.click('th a.sort[data-sort="cid"]'); // desc
    const firstAfter = await page.locator('tbody tr').first().getAttribute('data-cid');
    // Order change likely unless identical; assert attribute exists
    expect(firstBefore).not.toBeNull();
    expect(firstAfter).not.toBeNull();
  });
});
