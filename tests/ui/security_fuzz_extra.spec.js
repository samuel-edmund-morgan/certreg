const { test, expect } = require('@playwright/test');
const { login } = require('./_helpers');

// Additional fuzz vectors: weird CIDs, unicode & emoji, path-like, overlong, whitespace-only extra.

async function getCsrf(page){ const meta = await page.locator('meta[name="csrf"]').first(); if(await meta.count()) return await meta.getAttribute('content'); return ''; }

function randHex(n){ return [...crypto.getRandomValues(new Uint8Array(n))].map(b=>b.toString(16).padStart(2,'0')).join(''); }

// Fuzz cases array of {cid, extra, expectStatus}
const CASES = [
  { cid: 'C space '+Date.now().toString(36), extra: 'EXTRA', note: 'space in cid' },
  { cid: 'C..dots..'+Date.now().toString(36), extra: 'EXTRA', note: 'dots' },
  { cid: 'C../trv'+Date.now().toString(36), extra: 'EXTRA', note: 'path traversal style' },
  { cid: 'C🔥'+Date.now().toString(36), extra: 'EMOJI', note: 'emoji in cid' },
  { cid: 'CUNICODE'+Date.now().toString(36), extra: 'ЕКСТРА Юнікод', note: 'unicode extra' },
  { cid: 'CVERY-LONG-'+Date.now().toString(36), extra: 'X'.repeat(500), note: 'overlong extra truncation' },
  { cid: 'CEMPTYEXTRA'+Date.now().toString(36), extra: '   ', note: 'whitespace extra -> null' },
];

// We expect register.php to trim & truncate; only reject if hash invalid or cid empty. All cids are non-empty so status mostly 200 or 409 (duplicate) or 422 if internal validation decides.

const today = new Date().toISOString().slice(0,10);

CASES.forEach(c => {
  test('fuzz register variant: '+c.note, async ({ page }) => {
    await login(page);
    await page.goto('/issue_token.php');
    const csrf = await getCsrf(page);
    const h = 'a'.repeat(64);
  const res = await page.request.post('/api/register.php', { data: { cid: c.cid, v:3, h, extra_info: c.extra, date: today, valid_until: '4000-01-01' }, headers: { 'Content-Type':'application/json','X-CSRF-Token':csrf } });
    // Acceptable statuses: 200 (ok), 409 (conflict), 422 (if validation rejects dangerous pattern)
    expect([200,409,422]).toContain(res.status());
    if(res.status()===200){
      const js = await res.json();
      expect(js.ok).toBeTruthy();
    }
  });
});
