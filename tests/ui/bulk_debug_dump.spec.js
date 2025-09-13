const { test } = require('./fixtures');
const { login } = require('./_helpers');

test.describe('Bulk debug dump', () => {
  test('dump __BULK_DEBUG after 3-row generate', async ({ page }) => {
    await login(page);
    await page.goto('/issue_token.php');
    await page.click('.tab[data-tab="bulk"]');
    await page.waitForSelector('#bulkForm');
    const today = new Date().toISOString().slice(0,10);
  await page.fill('#bulkForm input[name="extra"]', 'COURSE-DBG');
    await page.fill('#bulkForm input[name="date"]', today);
    // Add rows (2 extra; 1 default)
    await page.click('#addRowBtn');
    await page.click('#addRowBtn');
    const names = ['Dbg Один','Dbg Два','Dbg Три'];
    for(let i=0;i<3;i++){
      await page.fill(`#bulkTable tbody tr:nth-child(${i+1}) input[name="name"]`, names[i]);
    }
    await page.click('#bulkGenerateBtn');
    await page.waitForTimeout(4000); // give some time for workers (should have progressed)
    const debugData = await page.evaluate(() => ({
      clickCount: window.__BULK_CLICK_COUNT || 0,
      procStarts: window.__BULK_PROCESS_STARTS || 0,
      events: (window.__BULK_DEBUG||[]).slice(-40),
      rowStatuses: Array.from(document.querySelectorAll('#bulkTable tbody tr')).map(tr=>({
        id: tr.querySelector('td')?.textContent?.trim(),
        badge: tr.querySelector('.status-badge')?.textContent?.trim(),
        cls: tr.querySelector('.status-badge')?.className
      }))
    }));
    console.log('BULK_DEBUG_DUMP_START');
    console.log(JSON.stringify(debugData,null,2));
    console.log('BULK_DEBUG_DUMP_END');
  });
});
