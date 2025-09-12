const { test } = require('./fixtures');
const { login } = require('./_helpers');

// Temporary diagnostic test to capture [bulk] console state logs to understand why validCount stays 0.
test('bulk console capture', async ({ page }) => {
  const logs = [];
  page.on('console', msg => {
    const txt = msg.text();
    if(/\[bulk]/.test(txt)) logs.push(txt);
  });
  await login(page);
  await page.goto('/issue_token.php');
  await page.click('.tab[data-tab="bulk"]');
  await page.waitForSelector('#bulkForm');
  // Wait a bit for auto row creation logic
  await page.waitForTimeout(1000);
  // Add two more rows
  await page.click('#addRowBtn');
  await page.click('#addRowBtn');
  const names=['Cons Один','Cons Два','Cons Три'];
  const grades=['A','B','C'];
  for(let i=0;i<3;i++){
    await page.fill(`#bulkTable tbody tr:nth-child(${i+1}) input[name="name"]`, names[i]);
    await page.fill(`#bulkTable tbody tr:nth-child(${i+1}) input[name="grade"]`, grades[i]);
    await page.waitForTimeout(50);
  }
  // Give updateGenerateState time to run
  await page.waitForTimeout(600);
  const btnDisabled = await page.getAttribute('#bulkGenerateBtn','disabled');
  console.log('BTN_DISABLED?', btnDisabled);
  console.log('--- BULK_LOGS_START ---');
  console.log(JSON.stringify(logs,null,2));
  console.log('--- BULK_LOGS_END ---');
});
