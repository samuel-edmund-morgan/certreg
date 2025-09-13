const { test, expect } = require('@playwright/test');
const { login } = require('./_helpers');

// Tamper with payload (extra) so canonical derivation breaks while status hash remains original.
test('verify: tampered payload causes mismatch when user verifies name', async ({ page }) => {
  await login(page);
  await page.goto('/issue_token.php');
  // Fill minimal fields (single issuance form)
  await page.fill('#issueForm input[name="pib"]', 'Іван Тестовий');
  await page.fill('#issueForm input[name="extra"]', 'EXTRA-ORIG');
  const today = new Date().toISOString().slice(0,10);
  await page.fill('#issueForm input[name="date"]', today);
  await page.check('#issueForm input[name="infinite"]');
  await page.click('#issueForm button[type="submit"]');
  // Wait for QR payload text area populated
  const payloadEl = page.locator('#qrPayload');
  await expect(payloadEl).toContainText('verify.php?p=');
  const full = await payloadEl.textContent();
  const firstLine = full.split('\n')[0].trim();
  const url = new URL(firstLine);
  const p = url.searchParams.get('p');
  // Decode payload
  function b64urlDecode(str){
    let b64 = str.replace(/-/g,'+').replace(/_/g,'/');
    const pad = b64.length % 4; if(pad) b64 += '='.repeat(4-pad);
    return Buffer.from(b64,'base64').toString('utf8');
  }
  const obj = JSON.parse(b64urlDecode(p));
  // Tamper extra (v3)
  obj.extra = 'EXTRA-TAMPERED';
  const tamperedStr = JSON.stringify(obj);
  function b64urlEncode(str){return Buffer.from(str,'utf8').toString('base64').replace(/=+$/,'').replace(/\+/g,'-').replace(/\//g,'_');}
  const tamperedParam = b64urlEncode(tamperedStr);
  await page.goto('/verify.php?p='+tamperedParam);
  await expect(page.locator('#existBox')).toContainText('існує');
  // Provide correct original name (will mismatch due to altered course in canonical string)
  await page.fill('#ownForm input[name="pib"]', 'Іван Тестовий');
  await page.click('#ownForm button[type="submit"]');
  await expect(page.locator('#ownResult .alert.alert-error')).toBeVisible();
});
