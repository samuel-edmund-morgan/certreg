const { test, expect } = require('./fixtures');
const { login } = require('./_helpers');
const { execSync } = require('child_process');

test.describe('Issuance with template selection', () => {
  test('single issuance uses selected template and appears on token detail', async ({ page }) => {
    // Seed an active template; capture output to get label and id if needed
    const seedOut = execSync('php tests/seed_template.php').toString();
    const idMatch = seedOut.match(/TPL_ID:\s*(\d+)/);
    expect(idMatch).not.toBeNull();
    await login(page);
    await page.goto('/issue_token.php');
    await page.waitForSelector('#issueForm');
    // Wait for templates to load and select the first non-empty option
    await page.waitForSelector('#templateSelect:not(:disabled)');
  // Ensure there is at least one template option (beyond the placeholder)
  const opts = page.locator('#templateSelect option');
  const optCount = await opts.count();
  expect(optCount).toBeGreaterThan(1);
    // Select the first actual template (value not empty)
    const values = await opts.evaluateAll(nodes => nodes.map(n => n.value));
    const firstTplValue = values.find(v => v && v.trim() !== '');
    expect(firstTplValue).toBeTruthy();
    await page.selectOption('#templateSelect', firstTplValue);
    // Fill issuance fields
    const today = new Date().toISOString().slice(0,10);
    await page.fill('input[name="pib"]', 'ПЕРЕВІРОЧНИЙ КОРИСТУВАЧ');
    await page.fill('input[name="extra"]', 'TEMPLATE-E2E');
    await page.fill('input[name="date"]', today);
    // Ensure infinite checked
    const inf = page.locator('#expiryBlock input[name="infinite"]');
    if(await inf.isChecked() === false) await inf.check();
    const downloadPromise = page.waitForEvent('download');
    await page.click('#issueForm button[type="submit"]');
    await page.waitForSelector('#summary:has-text("Нагороду створено")');
    const download = await downloadPromise; // pdf
    const fname = download.suggestedFilename();
    expect(fname).toMatch(/certificate_/);
    // Extract CID from regMeta dataset for navigation
    const cid = await page.locator('#regMeta').getAttribute('data-cid');
    expect(cid).toBeTruthy();
    // Open token detail and check Template row contains a link
    await page.goto('/token.php?cid='+encodeURIComponent(cid));
    await page.waitForSelector('.details-grid');
    const tplRow = page.locator('.details-grid >> text=Шаблон');
    await expect(tplRow).toHaveCount(1);
    const link = page.locator('.details-grid a[href^="/template.php?id="]');
    await expect(link).toHaveCount(1);
  });
});
