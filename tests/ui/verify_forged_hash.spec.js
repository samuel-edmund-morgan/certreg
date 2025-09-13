import { test, expect } from '@playwright/test';
import { login } from './_helpers';

// Negative: server-side forged h (status.php response) must not validate a correct name
// Approach: issue a normal cert (v3) -> capture QR payload (with salt + canon + extra) ->
// compute a forged h using the SAME salt but altered canonical (change extra), intercept /api/status.php
// to return the forged h. Then open verify page with original payload and enter the correct name -> expect mismatch.

function base64UrlToBytes(b64){
  b64 = b64.replace(/-/g,'+').replace(/_/g,'/');
  while(b64.length % 4) b64+='=';
  const buf = Buffer.from(b64,'base64');
  return Array.from(buf.values());
}
async function hmacSHA256Hex(page, keyBytes, message){
  return await page.evaluate(async ({keyBytes, message})=>{
    const key = await crypto.subtle.importKey('raw', new Uint8Array(keyBytes), {name:'HMAC', hash:'SHA-256'}, false, ['sign']);
    const sig = await crypto.subtle.sign('HMAC', key, new TextEncoder().encode(message));
    return Array.from(new Uint8Array(sig)).map(b=>b.toString(16).padStart(2,'0')).join('');
  }, {keyBytes, message});
}
function normName(s){ return s.normalize('NFC').replace(/[\u2019'`’\u02BC]/g,'').replace(/\s+/g,' ').trim().toUpperCase(); }

const ORG = 'ORG-CERT';

test('verify: forged server hash response does not validate correct name', async ({ page }) => {
  await login(page);
  await page.goto('/issue_token.php');
  // Issue a single certificate
  const today = new Date().toISOString().slice(0,10);
  const name = 'Олег Підпис';
  await page.fill('#issueForm input[name="pib"]', name);
  await page.fill('#issueForm input[name="extra"]', 'HMAC Deep');
  await page.fill('#issueForm input[name="date"]', today);
  await page.locator('#issueForm input[name="infinite"]').check();
  await page.click('#issueForm button[type="submit"]');
  const toggle = page.locator('#toggleDetails'); if(await toggle.count()) await toggle.click();
  await page.waitForSelector('#regMeta[data-salt][data-cid][data-h]');
  const meta = page.locator('#regMeta');
  const cid = await meta.getAttribute('data-cid');
  const saltB64 = await meta.getAttribute('data-salt');
  const date = await meta.getAttribute('data-date');
  const vu = (await meta.getAttribute('data-validuntil')) || (await meta.getAttribute('data-valid-until')) || '4000-01-01';
  const nameNorm = await meta.getAttribute('data-name-norm');
  // Extract canon and extra from QR payload JSON shown on page for consistency
  const qrText = await page.locator('#qrPayload').textContent();
  const payloadJson = qrText.split('\n\n')[1];
  const payload = JSON.parse(payloadJson);
  const canonUrl = payload.canon;
  const extra = payload.extra || '';

  // Build a forged h by changing canonical (extra -> TAM) using same salt
  const saltBytes = base64UrlToBytes(saltB64||'');
  const forgedCanonical = `v3|${nameNorm || normName(name)}|${ORG}|${cid}|${date}|${vu}|${canonUrl}|TAM-P${Date.now().toString(36)}`;
  const forgedH = await hmacSHA256Hex(page, saltBytes, forgedCanonical);

  // Intercept status.php and return forged h
  await page.route(new RegExp(`/api/status.php\\?cid=${cid}`), async (route) => {
    const real = await page.request.fetch(route.request());
    const js = await real.json();
    js.h = forgedH;
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(js) });
  });

  // Open verify page and attempt with correct name
  function toB64Url(obj){ const json=JSON.stringify(obj); let b64=Buffer.from(json,'utf8').toString('base64'); return b64.replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,''); }
  const p = toB64Url({v:3,cid,s:saltB64,org:ORG,date,valid_until:vu,canon:canonUrl,extra});
  await page.goto(`/verify.php?p=${p}`);
  await page.waitForSelector('#ownForm');
  await page.fill('#ownForm input[name="pib"]', name);
  await page.click('#ownForm button[type="submit"]');
  await page.waitForSelector('#ownResult .verify-fail');
  const verdict = await page.locator('#ownResult .verify-fail').innerText();
  expect(verdict).toMatch(/Не збігається/i);
});
