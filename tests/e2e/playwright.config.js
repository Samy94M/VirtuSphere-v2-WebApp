// Dev-only E2E config (ADR-0028). Runs on the dev host against the running
// Docker stack; never part of the shipped artifact and never in CI.
const { defineConfig, devices } = require('@playwright/test');
const path = require('node:path');

// The known-good local Chromium (portal-screenshot-setup). Overridable so the
// suite is not pinned to one machine, but Playwright never downloads a browser
// (PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 in the environment for an offline install).
const CHROMIUM =
  process.env.PLAYWRIGHT_CHROMIUM ||
  'C:\\Users\\Samy\\AppData\\Local\\ms-playwright\\chromium-1223\\chrome-win64\\chrome.exe';

// Trailing slash is load-bearing: the no-slash form triggers an nginx redirect
// that this Chromium fails with ERR_CONNECTION_REFUSED (portal-screenshot-setup).
const BASE_URL = process.env.VIRTUSPHERE_BASE_URL || 'http://127.0.0.1:8021/portal/';

module.exports = defineConfig({
  testDir: './specs',
  // A shared dev DB is not safe to hammer in parallel, and destructive specs
  // seed their own rows; keep it single-worker and serial for determinism.
  fullyParallel: false,
  workers: 1,
  forbidOnly: !!process.env.CI,
  retries: 0,
  reporter: [['list'], ['html', { open: 'never' }]],
  timeout: 30000,
  expect: { timeout: 7000 },

  use: {
    baseURL: BASE_URL,
    // 'retain-on-failure', not 'on-first-retry': with retries at 0 there is never
    // a first retry, so the trace could never be written and the one artefact
    // worth having after a red run was silently never produced.
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    // Every test asserts the air-gap, so a stray external request is a failure,
    // not a hang: fail fast instead of waiting out the connect timeout.
    navigationTimeout: 15000,
    launchOptions: {
      executablePath: CHROMIUM,
      args: ['--no-sandbox'],
    },
  },

  projects: [
    // Auth setup: logs in once per role and writes storageState, reused below.
    { name: 'setup', testMatch: /auth\.setup\.js/ },
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'], channel: undefined },
      dependencies: ['setup'],
    },
  ],
});
