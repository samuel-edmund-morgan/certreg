const { test, expect } = require('@playwright/test');
const { login } = require('./_helpers');

async function getCsrf(page){
  const meta = await page.locator('meta[name="csrf"]').first();
  if(await meta.count()) return await meta.getAttribute('content');
  return '';
}

test.describe('security fuzz register', () => {
  test('very long course truncated & invalid hash rejected', async ({ page }) => {
    await login(page);
    await page.goto('/issue_token.php');
    const csrf = await getCsrf(page);
    const longCourse = 'LONGCOURSE_'+ 'X'.repeat(300);
    const goodHash = 'b'.repeat(64);
  // Use high-resolution timestamp + random to minimize chance of CID collision between fast parallel tests
  const cid = 'Cfuzz-'+Date.now().toString(36)+'-'+Math.random().toString(16).slice(2,8);
    const today = new Date().toISOString().slice(0,10);
    // Valid request with long course
    let res = await page.request.post('/api/register.php', { data: { cid, v:2, h: goodHash, course: longCourse, grade:'A', date: today, valid_until: '4000-01-01'}, headers: { 'Content-Type':'application/json','X-CSRF-Token':csrf } });
    if(res.status()===200){
      const js = await res.json();
      expect(js.ok).toBeTruthy();
      // Fetch tokens list and ensure full 300 chars not present (truncated)
      const toks = await page.request.get('/tokens.php');
      const html = await toks.text();
      expect(html.includes(longCourse)).toBeFalsy();
      expect(html.includes('LONGCOURSE_')).toBeTruthy();
    } else if(res.status()===409){
      // Conflict acceptable for fuzz (duplicate CID). Skip truncation assertion in this edge case.
    } else {
      expect(res.status(), 'unexpected status for first fuzz register').toBe(200);
    }

    // Invalid hash length
    const bad = await page.request.post('/api/register.php', { data: { cid: cid+'x', v:2, h: '1234abcd', course: 'SHORT', grade:'A', date: today, valid_until: '4000-01-01'}, headers:{'Content-Type':'application/json','X-CSRF-Token':csrf} });
    expect(bad.status()).toBe(422);
    const bj = await bad.json();
    expect(bj.error).toBe('invalid_fields');
  });
});
