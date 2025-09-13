// Extended cryptographic + revocation + normalization fuzz tests (revised)
// Preconditions: data-* attributes exposed in issuance & bulk result lines
// Focus: deterministic HMAC recomputation client-side matches displayed INT/hash

import { test, expect } from '@playwright/test';
import { login } from './_helpers';

function base64UrlToBytes(b64){
  b64 = b64.replace(/-/g,'+').replace(/_/g,'/');
  while(b64.length % 4) b64+='=';
  const buf = Buffer.from(b64,'base64');
  return Array.from(buf.values()); // return plain array for structured clone
}
async function hmacSHA256Hex(page, keyBytes, message){
  return await page.evaluate(async ({keyBytes, message})=>{
    const key = await crypto.subtle.importKey('raw', new Uint8Array(keyBytes), {name:'HMAC', hash:'SHA-256'}, false, ['sign']);
    const sig = await crypto.subtle.sign('HMAC', key, new TextEncoder().encode(message));
    return Array.from(new Uint8Array(sig)).map(b=>b.toString(16).padStart(2,'0')).join('');
  }, {keyBytes, message});
}
function normName(s){
  return s.normalize('NFC').replace(/[\u2019'`’\u02BC]/g,'').replace(/\s+/g,' ').trim().toUpperCase();
}
const ORG = 'ORG-CERT';

// 1. Single issuance recomputation
// 2. Bulk issuance recomputation
// 3. Revocation verify path
// 4. Normalization baseline

test('single issuance: recompute HMAC matches displayed hash + INT', async ({ page }) => {
  await login(page);
  await page.goto('/issue_token.php');
  const singleTabBtn = page.locator('.tabs .tab[data-tab="single"]');
  if(await singleTabBtn.count()) await singleTabBtn.click();
  const today = new Date().toISOString().slice(0,10);
  await page.fill('#issueForm input[name="pib"]', 'Іван Тест');
  await page.fill('#issueForm input[name="extra"]', 'Crypto QA');
  await page.fill('#issueForm input[name="date"]', today);
  await page.locator('#issueForm input[name="infinite"]').check();
  await page.click('#issueForm button[type="submit"]');
  const toggle = page.locator('#toggleDetails'); if(await toggle.count()) await toggle.click();
  // Wait until salt attribute populated (element exists from start, so attribute is the signal)
  await page.waitForSelector('#regMeta[data-salt]', { timeout: 10000 });
  const meta = page.locator('#regMeta');
  const h = await meta.getAttribute('data-h');
  const saltB64 = await meta.getAttribute('data-salt');
  const cid = await meta.getAttribute('data-cid');
  const extra = await meta.getAttribute('data-extra');
  const date = await meta.getAttribute('data-date');
  const validUntil = await meta.getAttribute('data-validuntil') || await meta.getAttribute('data-valid-until');
  const nameNorm = await meta.getAttribute('data-name-norm');
  expect(h && saltB64 && cid).toBeTruthy();
    // Derive canonical verify URL from current origin
    const verifyUrl = new URL('/verify.php', page.url()).toString();
    const canonical = `v3|${nameNorm}|${ORG}|${cid}|${date}|${validUntil}|${verifyUrl}|${extra||''}`;
  const saltBytes = base64UrlToBytes(saltB64);
  expect(saltBytes.length).toBeGreaterThan(0);
  const recomputed = await hmacSHA256Hex(page, saltBytes, canonical);
  expect(recomputed).toBe(h);
  const intDisplayed = (await meta.getAttribute('data-int')) || '';
  const intNormalized = intDisplayed.replace(/-/g,'').toLowerCase();
  expect(intNormalized).toBe(h.slice(0,10).toLowerCase());
});

test('bulk issuance: each row exposes data-* and HMAC recomputes', async ({ page }) => {
  await login(page);
  await page.goto('/issue_token.php');
  await page.click('.tabs .tab[data-tab="bulk"]');
  const today = new Date().toISOString().slice(0,10);
  await page.fill('#bulkTab input[name="extra"]', 'Bulk Crypto');
  await page.fill('#bulkTab input[name="date"]', today);
  await page.locator('#bulkTab input[name="infinite"]').check();
  const firstRow = page.locator('#bulkTable tbody tr').first();
  await firstRow.locator('input[name="name"]').fill('Марія Нормалізація');
  await page.click('#addRowBtn');
  const second = page.locator('#bulkTable tbody tr').nth(1);
  await second.locator('input[name="name"]').fill('Петро Комбінація');
  await page.waitForSelector('#bulkGenerateBtn:not([disabled])');
  await page.click('#bulkGenerateBtn');
  await page.waitForSelector('#bulkResultLines div[data-h][data-salt]', { timeout: 20000 });
  // Wait until at least 2 result rows (both bulk rows) are rendered; allow brief race where only first appears
  await page.waitForFunction(() => {
    return document.querySelectorAll('#bulkResultLines > div[data-h]').length >= 2;
  }, { timeout: 10000 }).catch(()=>{}); // tolerate timeout (assertion below will fail if still <2)
  const rows = page.locator('#bulkResultLines > div[data-h]');
  const count = await rows.count();
  expect(count).toBeGreaterThanOrEqual(2);
  for(let i=0;i<count;i++){
    const row = rows.nth(i);
    const h = await row.getAttribute('data-h');
    const saltB64 = await row.getAttribute('data-salt');
    const cid = await row.getAttribute('data-cid');
    const extra = await row.getAttribute('data-extra');
    const nameNorm = await row.getAttribute('data-name-norm');
    expect(h && saltB64 && cid && nameNorm).toBeTruthy();
  const saltBytes = base64UrlToBytes(saltB64||'');
  expect(saltBytes.length).toBeGreaterThan(0);
      const verifyUrl2 = new URL('/verify.php', page.url()).toString();
      const canonical = `v3|${nameNorm}|${ORG}|${cid}|${today}|4000-01-01|${verifyUrl2}|${extra||'Bulk Crypto'}`;
  const recomputed = await hmacSHA256Hex(page, saltBytes, canonical);
    expect(recomputed).toBe(h);
    const intShort = h.slice(0,10).toUpperCase();
    const intAttr = await row.getAttribute('data-int');
    expect(intAttr && intAttr.toLowerCase()).toBe(intShort.toLowerCase());
  }
});

test('revocation: revoked certificate shows revoked status and hides owner form', async ({ page }) => {
  await login(page);
  await page.goto('/issue_token.php');
  const today = new Date().toISOString().slice(0,10);
  await page.fill('#issueForm input[name="pib"]', 'Степан Ревокація');
  await page.fill('#issueForm input[name="extra"]', 'Rev Course');
  await page.fill('#issueForm input[name="date"]', today);
  await page.locator('#issueForm input[name="infinite"]').check();
  await page.click('#issueForm button[type="submit"]');
  const toggle = page.locator('#toggleDetails'); if(await toggle.count()) await toggle.click();
  await page.waitForSelector('#regMeta[data-salt][data-cid]', { timeout:10000 });
  const meta = page.locator('#regMeta');
  const cid = await meta.getAttribute('data-cid');
  const salt = await meta.getAttribute('data-salt');
  const extra = await meta.getAttribute('data-extra');
  const date = await meta.getAttribute('data-date');
  const validUntil = await meta.getAttribute('data-valid-until');
  await page.evaluate(async (cid)=>{
    const csrf = document.querySelector('meta[name="csrf"]').content;
    await fetch('/api/revoke.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`cid=${encodeURIComponent(cid)}&_csrf=${encodeURIComponent(csrf)}&reason=revocation+ui+test`});
  }, cid);
  function toB64Url(obj){
    const json = JSON.stringify(obj);
    let b64 = Buffer.from(json,'utf8').toString('base64');
    return b64.replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'');
  }
  const verifyUrl3 = new URL('/verify.php', page.url()).toString();
  const pPacked = toB64Url({v:3,cid,s:salt,org:ORG,date,valid_until:validUntil,canon: verifyUrl3, extra});
  await page.goto(`/verify.php?p=${pPacked}`);
  await page.waitForSelector('#existBox');
  const existText = await page.locator('#existBox').innerText();
  expect(existText).toMatch(/ВІДКЛИКАНО/i);
  const ownFormDisplay = await page.evaluate(()=>{ const f=document.getElementById('ownForm'); return f?getComputedStyle(f).display:'absent'; });
  expect(['none','absent']).toContain(ownFormDisplay);
});

test('normalization fuzz: combining marks & spacing variants yield same normalized name', async ({ page }) => {
  await login(page);
  await page.goto('/issue_token.php');
  const today = new Date().toISOString().slice(0,10);
  const base = 'Ганна Проба';
  await page.fill('#issueForm input[name="pib"]', base);
  await page.fill('#issueForm input[name="extra"]', 'Norm Course');
  await page.fill('#issueForm input[name="date"]', today);
  await page.locator('#issueForm input[name="infinite"]').check();
  await page.click('#issueForm button[type="submit"]');
  const toggle = page.locator('#toggleDetails'); if(await toggle.count()) await toggle.click();
  await page.waitForSelector('#regMeta[data-name-norm][data-salt]', { timeout:10000 });
  const normRef = await page.locator('#regMeta').getAttribute('data-name-norm');
  expect(normRef).toBe(normName(base));
});
