const { test, expect } = require('./fixtures');
const { login } = require('./_helpers');

// Simple direct POST to /api/register.php to ensure endpoint responds and not hanging.
test('register endpoint smoke', async ({ page }) => {
  await login(page);
  // Need CSRF meta: load issue page quickly
  await page.goto('/issue_token.php');
  const csrf = await page.getAttribute('meta[name="csrf"]','content');
  const today = new Date().toISOString().slice(0,10);
  const payload = {
    cid: 'TESTCID'+Date.now().toString(36),
    v: 2,
    h: '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
    course: 'SMOKE',
    grade: 'A',
    date: today,
    valid_until: today
  };
  const res = await page.evaluate(async (pl) => {
    const meta = document.querySelector('meta[name="csrf"]');
    const csrfTok = meta && meta.content;
    const r = await fetch('/api/register.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrfTok},body:JSON.stringify(pl)});
    let txt=''; try{ txt=await r.text(); }catch(_e){}
    return {status:r.status, body:txt};
  }, payload);
  console.log('REGISTER_SMOKE_RESPONSE', res.status, res.body.slice(0,120));
  expect(res.status).toBe(200);
  expect(res.body).toMatch(/"ok"\s*:\s*true/);
});
