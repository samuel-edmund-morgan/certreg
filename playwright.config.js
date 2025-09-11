// Playwright configuration for UI automation of certreg
// Launches a PHP built-in server on localhost (secure context for Web Crypto)
const { defineConfig, devices } = require('@playwright/test');
const path = require('path');

module.exports = defineConfig({
  testDir: path.join(__dirname, 'tests/ui'),
  timeout: 60_000,
  expect: { timeout: 10_000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [['list']],
  use: {
    baseURL: 'http://localhost:8080',
  acceptDownloads: true,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'off'
  },
  webServer: {
    command: 'php -S localhost:8080 -t .',
    url: 'http://localhost:8080/admin.php',
    reuseExistingServer: true,
    stdout: 'ignore',
  stderr: 'pipe',
  // Disable rate limiting during automated UI tests for stability
  env: { ...process.env, CERTREG_TEST_MODE: '1' }
  },
  globalSetup: require.resolve('./tests/ui/globalSetup.js'),
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] }
    }
  ]
});
