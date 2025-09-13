const { test, expect } = require('@playwright/test');
const { login } = require('./_helpers');

// Forge: mutate the salt in payload so user validation with correct name mismatches (v3).
test('verify: forged salt causes HMAC mismatch for correct name', async ({ page }) => {
  await login(page);
  await page.goto('/issue_token.php');
  const today = new Date().toISOString().slice(0,10);
  const name = 'Іван Форджа';
  await page.fill('#issueForm input[name="pib"]', name);
  await page.fill('#issueForm input[name="extra"]', 'FORGE-EXTRA');
  await page.fill('#issueForm input[name="date"]', today);
  await page.check('#issueForm input[name="infinite"]');
  await page.click('#issueForm button[type="submit"]');
  const payloadEl = page.locator('#qrPayload');
  await expect(payloadEl).toContainText('verify.php?p=');
  const full = await payloadEl.textContent();
  const firstLine = full.split('\n')[0].trim();
  const url = new URL(firstLine);
  const p = url.searchParams.get('p');
  function b64urlDecode(str){ let b64=str.replace(/-/g,'+').replace(/_/g,'/'); const pad=b64.length%4; if(pad) b64+='='.repeat(4-pad); return Buffer.from(b64,'base64').toString('utf8'); }
  function b64urlEncode(str){ return Buffer.from(str,'utf8').toString('base64').replace(/=+$/,'').replace(/\+/g,'-').replace(/\//g,'_'); }
  const obj = JSON.parse(b64urlDecode(p));
  // Mutate salt: flip a byte
  if(typeof obj.s === 'string' && obj.s.length > 5){
    obj.s = (obj.s[0] === 'A' ? 'B' : 'A') + obj.s.slice(1);
  } else {
    obj.s = (obj.s || '') + 'A';
  }
  const forgedParam = b64urlEncode(JSON.stringify(obj));
  await page.goto('/verify.php?p='+forgedParam);
  await expect(page.locator('#existBox')).toContainText('існує');
  // Submit correct (original) name => should mismatch
  await page.fill('#ownForm input[name="pib"]', name);
  await page.click('#ownForm button[type="submit"]');
  const fail = page.locator('#ownResult .verify-fail');
  await expect(fail).toBeVisible();
});
