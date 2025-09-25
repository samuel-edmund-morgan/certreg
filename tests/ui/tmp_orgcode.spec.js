const { test, expect } = require('./fixtures');
const { login } = require('./_helpers');

// Temporary diagnostic: POST register with org_code ORG-CERT
test('tmp: register with org_code ORG-CERT', async ({ page }) => {
  await login(page);
  await page.goto('/issue_token.php');
  const today = new Date().toISOString().slice(0,10);
  const res = await page.evaluate(async (today) => {
    const meta = document.querySelector('meta[name="csrf"]');
    const csrfTok = meta && meta.content;
    function toHex(bytes){ return Array.from(bytes).map(b=>b.toString(16).padStart(2,'0')).join(''); }
    const salt = crypto.getRandomValues(new Uint8Array(32));
    const cid = 'T'+Date.now().toString(36);
    const ORG = 'ORG-CERT';
    const canonical = `v3|TEST USER|${ORG}|${cid}|${today}|4000-01-01|`+location.origin+`/verify.php|COURSE-PLAY`;
    const key = await crypto.subtle.importKey('raw', salt, {name:'HMAC', hash:'SHA-256'}, false, ['sign']);
    const sig = await crypto.subtle.sign('HMAC', key, new TextEncoder().encode(canonical));
    const h = toHex(new Uint8Array(sig));
    const r = await fetch('/api/register.php', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfTok}, body: JSON.stringify({ cid, v:3, h, date: today, valid_until: '4000-01-01', extra_info:'COURSE-PLAY', org_code: ORG }) });
    const text = await r.text();
    return { status: r.status, body: text };
  }, today);
  console.log('TMP_ORG_CODE_STATUS', res.status, res.body);
  // not asserting to keep it flexible for debug
});
