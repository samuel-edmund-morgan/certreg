const { test, expect } = require('@playwright/test');
const { login } = require('./_helpers');

// API negative / validation tests for register, revoke, status endpoints.
// Uses direct API requests with an authenticated session + CSRF token.

async function getCsrf(page) {
  const meta = await page.locator('meta[name="csrf"]').first();
  if (await meta.count()) return await meta.getAttribute('content');
  return '';
}

test.describe('API negative cases', () => {
  test('register: bad json & invalid fields', async ({ page }) => {
    await login(page);
    await page.goto('/issue_token.php');
    const csrf = await getCsrf(page);

    // Bad JSON
    const bad = await page.request.fetch('/api/register.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      data: 'not-json'
    });
    expect(bad.status()).toBe(400);
    const badBody = await bad.json();
    expect(badBody.error).toBe('bad_json');

    // Invalid fields (missing / malformed hash)
    const invalid = await page.request.fetch('/api/register.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      data: JSON.stringify({ cid: 'Cinvalid-zz', v: 2, h: '1234', course: 'TEST', grade: 'A', date: '2025-09-09', valid_until: '4000-01-01' })
    });
    expect(invalid.status()).toBe(422);
    const invBody = await invalid.json();
    expect(invBody.error).toBe('invalid_fields');

    // Expiry before issue date
    const shortHash = 'a'.repeat(64);
    const badExpiry = await page.request.fetch('/api/register.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      data: JSON.stringify({ cid: 'Cbadexp-'+Date.now().toString(36), v: 2, h: shortHash, course: 'TEST', grade: 'A', date: '2025-09-09', valid_until: '2025-09-01' })
    });
    expect(badExpiry.status()).toBe(422);
    const beBody = await badExpiry.json();
    expect(beBody.error).toBe('expiry_before_issue');
  });

  test('status: missing & nonexistent', async ({ page }) => {
    const resMissing = await page.request.get('/api/status.php');
    expect(resMissing.status()).toBe(400);
    const jsMissing = await resMissing.json();
    expect(jsMissing.error).toBe('missing_cid');

    const resNone = await page.request.get('/api/status.php?cid=DoesNotExistXYZ');
    expect(resNone.status()).toBe(200);
    const jsNone = await resNone.json();
    expect(jsNone.exists).toBe(false);
  });

  test('revoke: validation + not_found', async ({ page }) => {
    await login(page);
    await page.goto('/tokens.php');
    const csrf = await getCsrf(page);

    // Missing cid
    const miss = await page.request.post('/api/revoke.php', { form: { _csrf: csrf, reason: 'Test reason' } });
    expect(miss.status()).toBe(400);
    const missBody = await miss.json();
    expect(missBody.error).toBe('missing_cid');

    // Too short reason
    const short = await page.request.post('/api/revoke.php', { form: { _csrf: csrf, cid: 'Cnope-1234', reason: 'abc' } });
    expect(short.status()).toBe(422);
    const shortB = await short.json();
    expect(shortB.error).toBe('too_short');

    // Not found
    const nf = await page.request.post('/api/revoke.php', { form: { _csrf: csrf, cid: 'Cmissing-'+Date.now().toString(36), reason: 'Valid reason text' } });
    expect(nf.status()).toBe(404);
    const nfB = await nf.json();
    expect(nfB.error).toBe('not_found');
  });
});
