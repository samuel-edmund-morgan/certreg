const { test, expect } = require('./fixtures');
const { login } = require('./_helpers');

async function issueToken(page, { course = 'COURSE-LIFE', grade='A', date=new Date().toISOString().slice(0,10), infinite=true, validUntil } = {}) {
  await page.goto('/issue_token.php');
  await page.waitForSelector('#issueForm');
  await page.fill('input[name="pib"]', 'ТЕСТ ЖИТТЄВИЙ');
  await page.fill('input[name="course"]', course);
  await page.fill('input[name="grade"]', grade);
  await page.fill('input[name="date"]', date);
  if(!infinite){
    await page.uncheck('input[name="infinite"]');
    if(validUntil){
      await page.fill('input[name="valid_until"]', validUntil);
    }
  }
  const downloadPromise = page.waitForEvent('download');
  await page.click('#issueForm button[type="submit"]');
  await page.waitForSelector('#summary:has-text("Сертифікат створено")');
  await downloadPromise;
  const cid = await page.locator('#summary strong').first().textContent();
  const verifyLink = await page.locator('#summary a[href*="verify.php"]').first().getAttribute('href');
  return { cid, verifyLink };
}

async function revokeInline(page, cid){
  const rowSel = `tr[data-cid="${cid}"]`;
  await page.goto('/tokens.php');
  await page.waitForSelector(rowSel);
  // Extract CSRF + cid directly; some browsers may mutate table cell innerHTML omitting <form> wrapper.
  const data = await page.evaluate(sel=>{
    const tr = document.querySelector(sel);
    if(!tr) return null;
    const csrf = tr.querySelector('input[name="_csrf"]')?.value;
    const cidVal = tr.querySelector('input[name="cid"]')?.value;
    return { csrf, cid: cidVal };
  }, rowSel);
  if(!data || !data.csrf || !data.cid){
    const html = await page.locator(rowSel).innerHTML().catch(()=>'<no row html>');
    throw new Error('Unable to extract revoke form data. Row HTML: '+html);
  }
  await page.evaluate(async ({csrf, cid})=>{
    const fd = new FormData();
    fd.append('_csrf', csrf);
    fd.append('cid', cid);
    fd.append('reason','AUTOTEST lifecycle revoke');
    const res = await fetch('/api/revoke.php',{method:'POST',body:fd,credentials:'same-origin'});
    if(!res.ok) throw new Error('HTTP '+res.status);
    const js = await res.json();
    if(!js.ok) throw new Error('API error');
  }, data);
  // Reload page to reflect new status (ajax path would reload normally)
  await page.reload();
  await page.waitForSelector(`${rowSel} .badge-danger`);
}

async function unrevokeInline(page, cid){
  const rowSel = `tr[data-cid="${cid}"]`;
  await page.goto('/tokens.php');
  await page.waitForSelector(rowSel);
  await page.waitForSelector(`${rowSel} .badge-danger`, { timeout: 5000 });
  const data = await page.evaluate(sel=>{
    const tr = document.querySelector(sel);
    if(!tr) return null;
    const csrf = tr.querySelector('input[name="_csrf"]')?.value;
    const cidVal = tr.querySelector('input[name="cid"]')?.value;
    return { csrf, cid: cidVal };
  }, rowSel);
  if(!data || !data.csrf || !data.cid){
    const html = await page.locator(rowSel).innerHTML().catch(()=>'<no row html>');
    throw new Error('Unable to extract unrevoke form data. Row HTML: '+html);
  }
  await page.evaluate(async ({csrf, cid})=>{
    const fd = new FormData();
    fd.append('_csrf', csrf);
    fd.append('cid', cid);
    const res = await fetch('/api/unrevoke.php',{method:'POST',body:fd,credentials:'same-origin'});
    if(!res.ok) throw new Error('HTTP '+res.status);
    const js = await res.json();
    if(!js.ok) throw new Error('API error');
  }, data);
  await page.reload();
  await page.waitForSelector(`${rowSel} .badge-success`);
}

async function deleteViaBulk(page, cid){
  await page.goto('/tokens.php');
  const rowSel = `tr[data-cid="${cid}"]`;
  await page.waitForSelector(rowSel);
  await page.click(`${rowSel} input.rowChk`);
  // bulk bar appears
  await page.waitForSelector('#bulkBar:not(.d-none)');
  await page.selectOption('#bulkAction', 'delete');
  page.once('dialog', d=>d.accept());
  await page.click('#bulkExecute');
  // row should disappear
  await expect(page.locator(rowSel)).toHaveCount(0);
}

test.describe('Token lifecycle (revoke/unrevoke/delete)', () => {
  test('revoke then unrevoke returns certificate to active verification state', async ({ page }) => {
    await login(page);
    const { cid, verifyLink } = await issueToken(page, {});
    // initial verify active
    await page.goto(verifyLink);
    await page.waitForSelector('#existBox');
    const activeTxt = await page.locator('#existBox').textContent();
    expect(activeTxt).toMatch(/чинний/i);
    // revoke
    await revokeInline(page, cid);
    await page.goto(verifyLink);
    await page.waitForSelector('#existBox');
    const revokedTxt = await page.locator('#existBox').innerText();
    expect(revokedTxt).toMatch(/ВІДКЛИКАНО/i);
    // unrevoke
    await unrevokeInline(page, cid);
    await page.goto(verifyLink);
    await page.waitForSelector('#existBox');
    const backTxt = await page.locator('#existBox').textContent();
    expect(backTxt).toMatch(/чинний/i);
  });

  test('delete removes certificate so verification reports not found', async ({ page }) => {
    await login(page);
    const { cid, verifyLink } = await issueToken(page, { course:'COURSE-DEL' });
    // ensure active
    await page.goto(verifyLink);
    await page.waitForSelector('#existBox');
    let txt = await page.locator('#existBox').textContent();
    expect(txt).toMatch(/чинний/i);
    // delete via bulk
    await deleteViaBulk(page, cid);
    // verification now should say not found
    await page.goto(verifyLink);
    await page.waitForSelector('#existBox');
    txt = await page.locator('#existBox').textContent();
    expect(txt).toMatch(/не знайдено/i);
  });
});
