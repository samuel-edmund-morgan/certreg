const { test, expect } = require('@playwright/test');
const { login } = require('./_helpers');

test.describe('Admin login & header UI', () => {
  test('can login and persistent width logic sets CSS var', async ({ page }) => {
    await login(page);
    // The JS should have set a --topbar-btn-width variable or stored localStorage key
    const stored = await page.evaluate(() => localStorage.getItem('topbarBtnWidth'));
    expect(stored).not.toBeNull();
    // Check the CSS variable applied to documentElement
    const cssVar = await page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--topbar-btn-width'));
    expect(cssVar.trim()).not.toBe('');
  });
});
