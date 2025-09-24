const { test, expect } = require('./fixtures');
const { login } = require('./_helpers');
const { execSync } = require('child_process');

test('bulk issuance with template and tokens list shows template column', async ({ page }) => {
  // Seed template and capture ID
  const out = execSync('php tests/seed_template.php').toString();
  const m = out.match(/TPL_ID:\s*(\d+)/);
  expect(m).not.toBeNull();
  const tplId = m ? m[1] : null;
  await login(page);
  await page.goto('/issue_token.php');
  await page.click('.tab[data-tab="bulk"]');
  await page.waitForSelector('#bulkForm');
  // Wait for bulk template select ready and pick a template
  await page.waitForSelector('#bulkTemplateSelect:not(:disabled)');
  const opts = page.locator('#bulkTemplateSelect option');
  const optCount = await opts.count();
  expect(optCount).toBeGreaterThan(1);
  const values = await opts.evaluateAll(nodes => nodes.map(n => n.value));
  const firstTplValue = values.find(v => v && v.trim() !== '');
  expect(firstTplValue).toBeTruthy();
  await page.selectOption('#bulkTemplateSelect', firstTplValue);

  // Fill shared fields
  const today = new Date().toISOString().slice(0,10);
  await page.fill('#bulkForm input[name="extra"]', 'BULK-TPL');
  await page.fill('#bulkForm input[name="date"]', today);
  const infinite = page.locator('#bulkForm input[name="infinite"]');
  if(await infinite.isChecked() === false) await infinite.check();
  // Two rows
  await page.click('#addRowBtn');
  await page.fill('#bulkTable tbody tr:nth-child(1) input[name="name"]', 'Перший Шаблон');
  await page.fill('#bulkTable tbody tr:nth-child(2) input[name="name"]', 'Другий Шаблон');
  await page.click('#bulkGenerateBtn');
  await page.waitForSelector('#bulkTable tbody tr:nth-child(1) .status-badge.ok', { timeout: 20000 });
  await page.waitForSelector('#bulkTable tbody tr:nth-child(2) .status-badge.ok', { timeout: 20000 });
  // Navigate to tokens and filter by this template id (if we parsed it)
  await page.goto('/tokens.php'+(tplId? ('?tpl='+encodeURIComponent(tplId)) : ''));
  await page.waitForSelector('table.table');
  // Ensure template column is present
  const ths = page.locator('table thead th');
  const thText = await ths.allTextContents();
  expect(thText.join(' ')).toMatch(/Шаблон/);
  // There should be at least 2 rows, each with a link to template.php?id=
  const links = page.locator('tbody tr td a[href^="/template.php?id="]');
  const linkCount = await links.count();
  expect(linkCount).toBeGreaterThanOrEqual(2);
});
