// E2E config (ADR-0028, revised 2026-07-16): runs on the dev host against the
// running Docker stack AND as the e2e-portal gate of the Integration lane
// against the throwaway QA stack. Never part of the shipped artifact.
const { defineConfig, devices } = require('@playwright/test');
const path = require('node:path');
const fs = require('node:fs');

// Browser resolution, in order: explicit env path; the known-good local
// Chromium (portal-screenshot-setup) when it exists; otherwise undefined so
// Playwright uses its own installed browser (npx playwright install chromium,
// the CI path). Dev hosts keep PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 offline
// installs working because the local path resolves first.
const LOCAL_DEFAULT =
  'C:\\Users\\Samy\\AppData\\Local\\ms-playwright\\chromium-1223\\chrome-win64\\chrome.exe';
const CHROMIUM =
  process.env.PLAYWRIGHT_CHROMIUM ||
  (fs.existsSync(LOCAL_DEFAULT) ? LOCAL_DEFAULT : undefined);

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
      // Spread instead of `executablePath: undefined`: Playwright treats the
      // present-but-undefined key as "no browser" instead of falling back to
      // its own registry install.
      ...(CHROMIUM ? { executablePath: CHROMIUM } : {}),
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
