const { test, expect } = require('./fixtures');
const { login } = require('./_helpers');

test.describe('Bulk issuance – batch PDF & UI elements', () => {
  test('manual batch PDF download after multi-row success', async ({ page }) => {
    test.setTimeout(120000); // 2 minute timeout for this test

    await login(page);
    await page.goto('/issue_token.php');
    await page.click('.tab[data-tab="bulk"]');
    await page.waitForSelector('#bulkForm');
    const today = new Date().toISOString().slice(0,10);
    await page.fill('#bulkForm input[name="course"]', 'COURSE-BATCH');
    await page.fill('#bulkForm input[name="date"]', today);
    // create 3 rows (1 already present)
    await page.click('#addRowBtn');
    await page.click('#addRowBtn');
    const names = ['Тест Один','Тест Два','Тест Три'];
    for(let i=0;i<3;i++){
      await page.fill(`#bulkTable tbody tr:nth-child(${i+1}) input[name="name"]`, names[i]);
  // Provide per-row grades explicitly
  const grade = i===0 ? 'A' : (i===1 ? 'B' : 'C');
  await page.fill(`#bulkTable tbody tr:nth-child(${i+1}) input[name="grade"]`, grade);
    }
  // Start generation (manual batch PDF will be triggered after OK badges)
    const enabled = await page.isEnabled('#bulkGenerateBtn');
    if(!enabled){
      console.log('PRECLICK_DEBUG_BTN_DISABLED');
      const preDebug = await page.evaluate(() => ({
        validBtnText: document.querySelector('#bulkGenerateBtn')?.textContent,
        rows: Array.from(document.querySelectorAll('#bulkTable tbody tr')).map(tr=>({id:tr.querySelector('td')?.textContent?.trim(), name: tr.querySelector('input[name="name"]').value, grade: tr.querySelector('input[name="grade"]').value}))
      }));
      console.log(JSON.stringify(preDebug,null,2));
      throw new Error('Generate button disabled before click');
    }
    await page.click('#bulkGenerateBtn');
    // Wait progress bar done with fallback debug after 12s if not yet
    try {
      await page.waitForSelector('#bulkProgressBar.done', { timeout: 25000 });
    } catch(e){
      console.log('TIMEOUT_WAITING_PROGRESS_DONE');
      const debugData = await page.evaluate(() => ({
        events: (window.__BULK_DEBUG||[]).slice(-80),
        clickCount: window.__BULK_CLICK_COUNT||0,
        procStarts: window.__BULK_PROCESS_STARTS||0,
        rows: Array.from(document.querySelectorAll('#bulkTable tbody tr')).map(tr=>({
          id: tr.querySelector('td')?.textContent?.trim(),
          badge: tr.querySelector('.status-badge')?.textContent?.trim(),
          cls: tr.querySelector('.status-badge')?.className
        }))
      }));
      console.log('BULK_DEBUG_TIMEOUT_DUMP_START');
      console.log(JSON.stringify(debugData,null,2));
      console.log('BULK_DEBUG_TIMEOUT_DUMP_END');
      throw e;
    }
    // All 3 OK badges
    // Quick pre-check: collect current row badge states right after progress bar completion
    const preOkSnapshot = await page.evaluate(() => Array.from(document.querySelectorAll('#bulkTable tbody tr')).map((tr,i)=>({
      idx: i+1,
      badge: tr.querySelector('.status-badge')?.textContent?.trim(),
      cls: tr.querySelector('.status-badge')?.className || ''
    })));
    console.log('ROW_BADGE_SNAPSHOT_AFTER_PROGRESS', JSON.stringify(preOkSnapshot,null,2));

    try {
      for(let i=1;i<=3;i++){
        await page.waitForSelector(`#bulkTable tbody tr:nth-child(${i}) .status-badge.ok`, { timeout: 20000 });
      }
    } catch(e){
      console.log('TIMEOUT_WAITING_OK_BADGES');
      const debugData2 = await page.evaluate(() => ({
        events: (window.__BULK_DEBUG||[]).slice(-120),
        clickCount: window.__BULK_CLICK_COUNT||0,
        procStarts: window.__BULK_PROCESS_STARTS||0,
        rows: Array.from(document.querySelectorAll('#bulkTable tbody tr')).map(tr=>({
          id: tr.querySelector('td')?.textContent?.trim(),
          badge: tr.querySelector('.status-badge')?.textContent?.trim(),
          cls: tr.querySelector('.status-badge')?.className
        }))
      }));
      console.log('BULK_DEBUG_OK_BADGE_TIMEOUT_DUMP_START');
      console.log(JSON.stringify(debugData2,null,2));
      console.log('BULK_DEBUG_OK_BADGE_TIMEOUT_DUMP_END');
      throw e;
    }
    // Batch PDF button should be present for manual download
    await expect(page.locator('#bulkBatchPdfBtn')).toBeVisible();
    await expect(page.locator('#bulkBatchPdfBtn')).toBeEnabled();

    // Trigger download, retrying click if needed
    const download = await (async () => {
      const downloadPromise = page.waitForEvent('download', { timeout: 60000 });
      let attempts = 0;
      while (attempts < 10) {
        await page.locator('#bulkBatchPdfBtn').click().catch(e => console.log(`Click failed on attempt ${attempts}: ${e.message}`));
        const result = await Promise.race([
          downloadPromise,
          page.waitForTimeout(2000).then(() => null) // Wait 2s for download to start
        ]);
        if (result) return result; // Download started
        attempts++;
        console.log(`Download did not start, retrying click (attempt ${attempts})`);
      }
      // Final attempt: wait for the full duration
      return await downloadPromise;
    })();

    const filename = download.suggestedFilename();
    expect(filename).toBe('certificates.pdf');
    const path = await download.path();
    expect(path).not.toBeNull();
    const stat = fs.statSync(path);
    expect(stat.size).toBeGreaterThan(5500);
    // CSV export button present
    await expect(page.locator('#bulkCsvBtn')).toBeVisible();
  });
});
