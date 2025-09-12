const { test, expect } = require('./fixtures');
const { login } = require('./_helpers');

async function issueToken(page, { course='COURSE-LIFE', grade='A', date=new Date().toISOString().slice(0,10), infinite=true, validUntil } = {}) {
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
	await page.waitForSelector('#summary:has-text("Нагороду створено")');
	await downloadPromise;
	const cid = await page.locator('#summary strong').first().textContent();
	const verifyLink = await page.locator('#summary a[href*="verify.php"]').first().getAttribute('href');
	return { cid, verifyLink };
}

async function revokeInline(page, cid){
	const rowSel = `tr[data-cid="${cid}"]`;
	await page.goto('/tokens.php');
	await page.waitForSelector(rowSel);
	const revokeBtn = page.locator(`${rowSel} form.revoke-form button[type="submit"]`);
	// Fill reason to satisfy backend validation
	const reasonInput = page.locator(`${rowSel} form.revoke-form input[name="reason"]`);
	if(await revokeBtn.count()>0){
		if(await reasonInput.count()>0){
			await reasonInput.fill('AUTOTEST lifecycle revoke');
		}
		page.once('dialog', d=>d.accept());
		await revokeBtn.click();
		await page.waitForSelector(`${rowSel} .badge-danger`, { timeout: 8000 });
		return;
	}
	// Fallback: direct POST if form/button missing
	const csrf = await page.locator('input[name="_csrf"]').first().inputValue();
	await page.evaluate(async ({cid, csrf})=>{
		const fd = new FormData(); fd.append('_csrf', csrf); fd.append('cid', cid); fd.append('reason','AUTOTEST lifecycle revoke');
		const res = await fetch('/api/revoke.php',{method:'POST', body:fd, credentials:'same-origin'});
		if(!res.ok) throw new Error('HTTP '+res.status);
		const js = await res.json(); if(!js.ok) throw new Error('API revoke failed');
	}, { cid, csrf });
	await page.goto('/tokens.php');
	await page.waitForSelector(`${rowSel} .badge-danger`, { timeout: 8000 });
}

async function unrevokeInline(page, cid){
	const rowSel = `tr[data-cid="${cid}"]`;
	// Ensure we are on tokens page with revoked state before locating unrevoke button
	await page.goto('/tokens.php');
	await page.waitForSelector(rowSel);
	await page.waitForSelector(`${rowSel} .badge-danger`, { timeout: 8000 });
	const unrevokeBtn = page.locator(`${rowSel} form.unrevoke-form button[type="submit"]`);
	if(await unrevokeBtn.count()>0){
		page.once('dialog', d=>d.accept());
		await unrevokeBtn.click();
		await page.waitForSelector(`${rowSel} .badge-success`, { timeout: 8000 });
		return;
	}
	// Fallback: API call
	const csrf = await page.locator('input[name="_csrf"]').first().inputValue();
	await page.evaluate(async ({cid, csrf})=>{
		const fd = new FormData(); fd.append('_csrf', csrf); fd.append('cid', cid);
		const res = await fetch('/api/unrevoke.php',{method:'POST', body:fd, credentials:'same-origin'});
		if(!res.ok) throw new Error('HTTP '+res.status);
		const js = await res.json(); if(!js.ok) throw new Error('API unrevoke failed');
	}, { cid, csrf });
	await page.goto('/tokens.php');
	await page.waitForSelector(`${rowSel} .badge-success`, { timeout: 8000 });
}

async function deleteViaBulk(page, cid){
	const rowSel = `tr[data-cid="${cid}"]`;
	await page.goto('/tokens.php');
	await page.waitForSelector(rowSel);
	await page.click(`${rowSel} input.rowChk`);
	await page.waitForSelector('#bulkBar:not(.d-none)');
	await page.selectOption('#bulkAction','delete');
	page.once('dialog', d=>d.accept());
	await page.click('#bulkExecute');
	await expect(page.locator(rowSel)).toHaveCount(0);
}

test.describe('Token lifecycle (revoke / unrevoke / delete)', () => {
	test('revoke then unrevoke returns certificate to active verification state', async ({ page }) => {
		await login(page);
		const { cid, verifyLink } = await issueToken(page, {});
		// Active
		await page.goto(verifyLink);
		await page.waitForSelector('#existBox');
		let txt = await page.locator('#existBox').textContent();
		expect(txt).toMatch(/чинний/i);
		// Revoke
		await revokeInline(page, cid);
		await page.goto(verifyLink);
		await page.waitForSelector('#existBox');
		txt = await page.locator('#existBox').innerText();
		expect(txt).toMatch(/ВІДКЛИКАНО/i);
		// Unrevoke
		await unrevokeInline(page, cid);
		await page.goto(verifyLink);
		await page.waitForSelector('#existBox');
		txt = await page.locator('#existBox').textContent();
		expect(txt).toMatch(/чинний/i);
	});

	test('delete removes certificate so verification reports not found', async ({ page }) => {
		await login(page);
		const { cid, verifyLink } = await issueToken(page, { course: 'COURSE-DEL' });
		await page.goto(verifyLink);
		await page.waitForSelector('#existBox');
		let txt = await page.locator('#existBox').textContent();
		expect(txt).toMatch(/чинний/i);
		await deleteViaBulk(page, cid);
		await page.goto(verifyLink);
		await page.waitForSelector('#existBox');
		// Wait until text updates to not found
		await page.waitForFunction(() => /не знайдено/i.test(document.querySelector('#existBox')?.textContent||''), null, { timeout: 5000 });
		txt = await page.locator('#existBox').textContent();
		expect(txt).toMatch(/не знайдено/i);
	});
});

