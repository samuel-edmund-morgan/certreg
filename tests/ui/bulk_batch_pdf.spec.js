const { test, expect } = require('./fixtures');
const { login } = require('./_helpers');

test.describe('Bulk issuance – batch PDF & UI elements', () => {
  test('auto batch PDF download after multi-row success', async ({ page }, testInfo) => {
    await login(page);
    await page.goto('/issue_token.php');
    await page.click('.tab[data-tab="bulk"]');
    await page.waitForSelector('#bulkForm');
    const today = new Date().toISOString().slice(0,10);
    await page.fill('#bulkForm input[name="course"]', 'COURSE-BATCH');
    await page.fill('#bulkForm input[name="date"]', today);
    // default grade for inherited rows
    await page.fill('#bulkForm input[name="default_grade"]', 'B');
    // create 3 rows (1 already present)
    await page.click('#addRowBtn');
    await page.click('#addRowBtn');
    const names = ['Тест Один','Тест Два','Тест Три'];
    for(let i=0;i<3;i++){
      await page.fill(`#bulkTable tbody tr:nth-child(${i+1}) input[name="name"]`, names[i]);
      // Give explicit grade only to first row
      if(i===0){ await page.fill(`#bulkTable tbody tr:nth-child(${i+1}) input[name="grade"]`, 'A'); }
    }
    // Start generation; wait for download (batch PDF auto)
    const downloadPromise = page.waitForEvent('download');
    await page.click('#bulkGenerateBtn');
    // Wait progress bar done
    await page.waitForSelector('#bulkProgressBar.done', { timeout: 25000 });
    // All 3 OK badges
    for(let i=1;i<=3;i++){ await page.waitForSelector(`#bulkTable tbody tr:nth-child(${i}) .status-badge.ok`, { timeout: 20000 }); }
    const download = await downloadPromise;
    const filename = download.suggestedFilename();
    expect(filename).toMatch(/batch_certificates_/);
    const path = await download.path();
    // Basic size heuristic (> 20KB for 3 JPEG pages embedded)
    if(path){
      const fs = require('fs');
      const stat = fs.statSync(path);
      expect(stat.size).toBeGreaterThan(20000);
    }
    // Batch PDF button should be present for manual re-download
    await expect(page.locator('#bulkBatchPdfBtn')).toBeVisible();
    // CSV export button present
    await expect(page.locator('#bulkCsvBtn')).toBeVisible();
  });
});
