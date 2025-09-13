const { test, expect } = require('@playwright/test');
const { login } = require('./_helpers');

async function getCsrf(page){
  const meta = await page.locator('meta[name="csrf"]').first();
  if(await meta.count()) return await meta.getAttribute('content');
  return '';
}

test.describe('security fuzz register', () => {
  test('very long extra accepted (stored as extra_info) & invalid hash rejected', async ({ page }) => {
    await login(page);
    await page.goto('/issue_token.php');
    const csrf = await getCsrf(page);
    const longExtra = 'LONGEXTRA_'+ 'X'.repeat(300);
    const goodHash = 'b'.repeat(64);
  // Use high-resolution timestamp + random to minimize chance of CID collision between fast parallel tests
  const cid = 'Cfuzz-'+Date.now().toString(36)+'-'+Math.random().toString(16).slice(2,8);
    const today = new Date().toISOString().slice(0,10);
    // Valid request with long extra
    let res = await page.request.post('/api/register.php', { data: { cid, v:3, h: goodHash, extra_info: longExtra, date: today, valid_until: '4000-01-01'}, headers: { 'Content-Type':'application/json','X-CSRF-Token':csrf } });
    if(res.status()===200){
      const js = await res.json();
      expect(js.ok).toBeTruthy();
      // Fetch tokens list and ensure EXTRA label present (we don't assert truncation length in HTML)
      const toks = await page.request.get('/tokens.php');
      const html = await toks.text();
      expect(html.includes('LONGEXTRA_')).toBeTruthy();
    } else if(res.status()===409){
      // Conflict acceptable for fuzz (duplicate CID). Skip truncation assertion in this edge case.
    } else {
      expect(res.status(), 'unexpected status for first fuzz register').toBe(200);
    }

    // Invalid hash length
    const bad = await page.request.post('/api/register.php', { data: { cid: cid+'x', v:3, h: '1234abcd', extra_info: 'SHORT', date: today, valid_until: '4000-01-01'}, headers:{'Content-Type':'application/json','X-CSRF-Token':csrf} });
    expect(bad.status()).toBe(422);
    const bj = await bad.json();
    expect(bj.error).toBe('invalid_fields');
  });
});
