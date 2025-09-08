// Global test fixtures adding console error guard for every test.
const base = require('@playwright/test');

const IGNORED_PATTERNS = [
  /Failed to load resource: the server responded with a status of 404/,
];

const test = base.test.extend({
  page: async ({ page }, use) => {
    const consoleErrors = [];
    page.on('console', msg => {
      const text = msg.text();
      // Filter benign
      if(IGNORED_PATTERNS.some(r=>r.test(text))) return;
      if(msg.type() === 'error' || /Content Security Policy/i.test(text) || /Refused to/i.test(text)) {
        consoleErrors.push(`[${msg.type()}] ${text}`);
      }
    });
    await use(page);
    if(consoleErrors.length) {
      throw new Error('Console errors detected (guard):\n'+consoleErrors.join('\n'));
    }
  }
});

module.exports = { test, expect: base.expect };
