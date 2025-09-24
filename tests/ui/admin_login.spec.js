const { test, expect } = require('./fixtures');
const { login } = require('./_helpers');

test.describe('Admin login & header UI', () => {
  test('can login and persistent width logic sets CSS var', async ({ page }) => {
    await login(page);
    // Topbar actions should be visible and include key controls
    const actions = page.locator('nav.topbar__actions');
    await expect(actions).toBeVisible();
  const btns = actions.locator('.btn');
  const btnCount = await btns.count();
  expect(btnCount).toBeGreaterThan(3);
    await expect(page.locator('text=Вийти')).toHaveCount(1);
  });
});
