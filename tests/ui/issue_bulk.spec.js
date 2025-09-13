const { test, expect } = require('./fixtures');
const { login } = require('./_helpers');

// Basic bulk issuance test: two rows
// Validates statuses become OK and CSV export appears.

test('bulk issuance 2 rows produces OK statuses and CSV export', async ({ page }) => {
  await login(page);
  await page.goto('/issue_token.php');
  await page.click('.tab[data-tab="bulk"]');
  await page.waitForSelector('#bulkForm');
  // Fill shared fields (v3 extra)
  const today = new Date().toISOString().slice(0,10);
  await page.fill('#bulkForm input[name="extra"]', 'COURSE-BULK');
  await page.fill('#bulkForm input[name="date"]', today);
  // Ensure infinite checked
  const infinite = page.locator('#bulkForm input[name="infinite"]');
  if(await infinite.isChecked() === false) await infinite.check();
  // Row 1 (already exists), Row 2 add
  await page.click('#addRowBtn'); // now 2 rows
  const row1Name = "Тест Перший";
  const row2Name = "Тест Другий";
  await page.fill('#bulkTable tbody tr:nth-child(1) input[name="name"]', row1Name);
  await page.fill('#bulkTable tbody tr:nth-child(2) input[name="name"]', row2Name);
  // no per-row grade in v3
  // Generate
  await page.click('#bulkGenerateBtn');
  // Wait for both rows OK
  await page.waitForSelector('#bulkTable tbody tr:nth-child(1) .status-badge.ok', { timeout: 15000 });
  await page.waitForSelector('#bulkTable tbody tr:nth-child(2) .status-badge.ok', { timeout: 15000 });
  // Progress hint should contain 'успішно'
  await expect(page.locator('#bulkProgressHint')).toContainText('успішно');
  // Ensure result lines show two INT tokens
  const lines = page.locator('#bulkResultLines div');
  await expect(lines).toHaveCount(2);
  // Check CSV export button appears
  await page.waitForSelector('#bulkCsvBtn');
  // Sanity: button disabled state of generate becomes false again (can rerun)
  await expect(page.locator('#bulkGenerateBtn')).not.toBeDisabled();
});
