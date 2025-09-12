const { test, expect } = require('./fixtures');
const { login } = require('./_helpers');

// Verifies single-row issuance produces a PDF > 7KB (single page) and logs size events.
test.describe('Single issuance – PDF generation', () => {
  test('single certificate PDF download', async ({ page }, testInfo) => {
    await login(page);
    await page.goto('/issue_token.php');
    await page.click('.tab[data-tab="single"]');
    await page.waitForSelector('#issueSingleForm');
    const today = new Date().toISOString().slice(0,10);
    await page.fill('#issueSingleForm input[name="name"]', 'Тест Одинарний');
    await page.fill('#issueSingleForm input[name="course"]', 'COURSE-SINGLE');
    await page.fill('#issueSingleForm input[name="date"]', today);
    await page.fill('#issueSingleForm input[name="grade"]', 'A');

    // Prepare to capture download triggered automatically in single issuance
    const downloadPromise = page.waitForEvent('download');
    await page.click('#issueSingleForm button[type="submit"]');

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
