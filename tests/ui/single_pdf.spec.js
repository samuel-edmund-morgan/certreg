const { test, expect } = require('./fixtures');
const { login } = require('./_helpers');

// Verifies single-row issuance produces a PDF > 7KB (single page) and logs size events.
test.describe('Single issuance – PDF generation', () => {
  test('single certificate PDF download', async ({ page }, testInfo) => {
    await login(page);
    await page.goto('/issue_token.php');
  // Single tab is active by default, but click defensively.
  await page.click('.tab[data-tab="single"]');
  await page.waitForSelector('#issueForm');
    const today = new Date().toISOString().slice(0,10);
  await page.fill('#issueForm input[name="pib"]', 'Тест Одинарний');
  await page.fill('#issueForm input[name="course"]', 'COURSE-SINGLE');
  await page.fill('#issueForm input[name="date"]', today);
  await page.fill('#issueForm input[name="grade"]', 'A');

    // Prepare to capture download triggered automatically in single issuance
  const downloadPromise = page.waitForEvent('download');
  await page.click('#issueForm button[type="submit"]');

    const download = await downloadPromise;
    const filename = download.suggestedFilename();
    expect(filename).toMatch(/certificate_/);
    const path = await download.path();
    if(path){
      const fs = require('fs');
      const stat = fs.statSync(path);
      expect(stat.size).toBeGreaterThan(7000); // heuristic single-page PDF size
    }

    // Ensure no console errors gathered by global handler
    // (Fixtures already enforce this; optional explicit check could be added.)
  });
});
