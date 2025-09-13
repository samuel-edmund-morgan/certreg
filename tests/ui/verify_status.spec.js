const { test, expect } = require('./fixtures');
const { login } = require('./_helpers');

test.describe('Verify page status states', () => {
  test('valid then revoked certificate reflects correct messages', async ({ page }) => {
    await login(page);
    await page.goto('/issue_token.php');
    await page.waitForSelector('#issueForm');
    const today = new Date().toISOString().slice(0,10);
    await page.fill('input[name="pib"]', 'ТЕСТ КОР КОРИСТУВАЧ');
  await page.fill('input[name="extra"]', 'COURSE-VERIFY');
    await page.fill('input[name="date"]', today);
    const downloadPromise = page.waitForEvent('download');
    await page.click('#issueForm button[type="submit"]');
  await page.waitForSelector('#summary:has-text("Нагороду створено")');
    await downloadPromise; // ignore file validation here
  const cid = await page.locator('#summary strong').first().textContent();
  const externalLink = await page.locator('#summary a[href*="verify.php"]').first().getAttribute('href');
  const verifyLink = externalLink ? ('/verify.php?p=' + new URL(externalLink).searchParams.get('p')) : '';
    expect(verifyLink).toBeTruthy();
    // Open verify page and confirm active message
    await page.goto(verifyLink);
    await page.waitForSelector('#existBox');
  const txtActive = await page.locator('#existBox').textContent();
  expect(txtActive).toMatch(/чинн(ий|а)/i);
    // Revoke via tokens page
    await page.goto('/tokens.php');
    // Find row with CID
    const rowSel = `tr[data-cid="${cid}"]`;
    await page.waitForSelector(rowSel);
    // Attempt to submit revoke form inside that row (preferred UX path)
    let revoked = false;
    const revokeBtnLocator = page.locator(`${rowSel} form.revoke-form button[type="submit"]`);
    try {
      if(await revokeBtnLocator.count() > 0) {
        // Provide a valid reason before submitting (API requires >=5 chars & alnum)
        const reasonInput = page.locator(`${rowSel} form.revoke-form input[name="reason"]`);
        if(await reasonInput.count()>0){
          await reasonInput.fill('AUTOTEST inline revoke reason');
        }
        page.once('dialog', d => d.accept());
        await revokeBtnLocator.click({ timeout: 4000 });
        await page.waitForSelector(`${rowSel} .badge-danger`, { timeout: 8000 });
        revoked = true;
      }
    } catch (e) {
      // Fall through to API fallback
    }
    if(!revoked){
      // Fallback: perform revoke via direct POST to API using CSRF token present on page
      const csrf = await page.locator('input[name="_csrf"]').first().inputValue();
      await page.evaluate(async ({ cid, csrf }) => {
        const fd = new FormData();
        fd.append('_csrf', csrf);
        fd.append('cid', cid);
  fd.append('reason', 'AUTOTEST revoke reason');
        const res = await fetch('/api/revoke.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        if(!res.ok) throw new Error('Fallback revoke HTTP '+res.status);
        const js = await res.json();
        if(!js.ok) throw new Error('Fallback revoke API error');
      }, { cid, csrf });
      // Reload tokens page to reflect status
      await page.goto('/tokens.php');
      await page.waitForSelector(`${rowSel} .badge-danger`);
    }
    // Re-open verify page
    await page.goto(verifyLink);
    await page.waitForSelector('#existBox');
  const txtRevoked = await page.locator('#existBox').innerText();
  expect(txtRevoked).toMatch(/ВІДКЛИКАН(О|А)/i);
  });

  test('expired certificate displays expiry message', async ({ page }) => {
    await login(page);
    await page.goto('/issue_token.php');
    await page.waitForSelector('#issueForm');
    const today = new Date();
    const past = new Date(today.getTime() - 86400000); // yesterday
    const todayStr = today.toISOString().slice(0,10);
    const pastStr = past.toISOString().slice(0,10);
  await page.fill('input[name="pib"]', 'ТЕСТ ПРОСРОЧЕНИЙ');
  await page.fill('input[name="extra"]', 'COURSE-EXP');
    await page.fill('input[name="date"]', pastStr); // issue date yesterday
    // Disable infinite and set valid_until to yesterday (so considered expired today)
    await page.uncheck('input[name="infinite"]');
    await page.fill('input[name="valid_until"]', pastStr);
    const downloadPromise = page.waitForEvent('download');
    await page.click('#issueForm button[type="submit"]');
  await page.waitForSelector('#summary:has-text("Нагороду створено")');
    await downloadPromise;
  const externalLink = await page.locator('#summary a[href*="verify.php"]').first().getAttribute('href');
  const verifyLink = externalLink ? ('/verify.php?p=' + new URL(externalLink).searchParams.get('p')) : '';
  await page.goto(verifyLink);
    await page.waitForSelector('#existBox');
    const txt = await page.locator('#existBox').textContent();
    expect(txt).toMatch(/строк дії минув/i);
  });
});
