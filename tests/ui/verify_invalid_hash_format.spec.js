import { test, expect } from '@playwright/test';
import { login } from './_helpers';

// Negative: server returns malformed/invalid h values (empty, wrong length, non-hex)
// Expected: verification should not pass; UI must show mismatch/error state.

function toB64Url(obj){
	const json = JSON.stringify(obj);
	let b64 = Buffer.from(json,'utf8').toString('base64');
	return b64.replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'');
}

test('verify: invalid hash formats do not validate and show mismatch', async ({ page }) => {
	await login(page);
	await page.goto('/issue_token.php');

	// Issue a single certificate (v2 with infinite validity)
	const today = new Date().toISOString().slice(0,10);
	const name = 'Іван Тестовий';
	await page.fill('#issueForm input[name="pib"]', name);
	await page.fill('#issueForm input[name="course"]', 'Format Check');
	await page.fill('#issueForm input[name="grade"]', 'A');
	await page.fill('#issueForm input[name="date"]', today);
	await page.locator('#issueForm input[name="infinite"]').check();
	await page.click('#issueForm button[type="submit"]');

	// Reveal meta and grab data attributes
	const toggle = page.locator('#toggleDetails');
	if(await toggle.count()) await toggle.click();
	await page.waitForSelector('#regMeta[data-salt][data-cid][data-h]');
	const meta = page.locator('#regMeta');
	const cid = await meta.getAttribute('data-cid');
	const saltB64 = await meta.getAttribute('data-salt');
	const grade = await meta.getAttribute('data-grade');
	const course = await meta.getAttribute('data-course');
	const date = await meta.getAttribute('data-date');
	const vu = (await meta.getAttribute('data-valid-until')) || '4000-01-01';
	const org = 'ORG-CERT';

	// Prepare QR payload param p
	const p = toB64Url({ v: 2, cid, s: saltB64, org, course, grade, date, valid_until: vu });

	const cases = [
		{ label: 'empty', h: '' },
		{ label: 'too short (60)', h: 'a'.repeat(60) },
		{ label: 'too long (66)', h: 'b'.repeat(66) },
		{ label: 'non-hex chars', h: 'z'.repeat(64) }
	];

	for(const c of cases){
		await test.step(`case: ${c.label}`, async () => {
			// Intercept status.php for this cid and inject malformed h
			await page.route(new RegExp(`/api/status.php\\?cid=${cid}`), async (route) => {
				const real = await page.request.fetch(route.request());
				const js = await real.json();
				js.h = c.h;
				await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(js) });
			});

			await page.goto(`/verify.php?p=${p}`);
			await page.waitForSelector('#ownForm');
			await page.fill('#ownForm input[name="pib"]', name);
			await page.click('#ownForm button[type="submit"]');
			await page.waitForSelector('#ownResult .verify-fail');
			const verdict = await page.locator('#ownResult .verify-fail').innerText();
			expect(verdict).toMatch(/Не збігається|помилка/i);

			// Clean up route for next case
			await page.unroute(new RegExp(`/api/status.php\\?cid=${cid}`));
		});
	}
});

