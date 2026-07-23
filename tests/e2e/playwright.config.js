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
// The revision is resolved, never pinned: `npx playwright install` prunes the
// old revision directory, so a hardcoded one silently stops existing and the
// whole suite falls through to a browser that is not installed either. Pick the
// highest chromium-<rev> that actually carries an executable.
function localChromium() {
  const cache = process.env.PLAYWRIGHT_BROWSERS_PATH
    || path.join(process.env.LOCALAPPDATA || '', 'ms-playwright');
  let entries;
  try {
    entries = fs.readdirSync(cache);
  } catch {
    return undefined;
  }

  return entries
    .filter((name) => /^chromium-\d+$/.test(name))
    .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
    .map((name) => path.join(cache, name, 'chrome-win64', 'chrome.exe'))
    .find((exe) => fs.existsSync(exe));
}

const CHROMIUM = process.env.PLAYWRIGHT_CHROMIUM || localChromium();

// Trailing slash is load-bearing: the no-slash form triggers an nginx redirect
// that this Chromium fails with ERR_CONNECTION_REFUSED (portal-screenshot-setup).
const BASE_URL = process.env.VIRTUSPHERE_BASE_URL || 'http://127.0.0.1:8021/portal/';

// Chromium-engine launch options only: the resolved executable path and
// --no-sandbox would break Firefox/WebKit if they sat in the global `use`,
// and the msedge project must resolve through its channel, never a path.
const CHROMIUM_LAUNCH = {
  ...(CHROMIUM ? { executablePath: CHROMIUM } : {}),
  args: ['--no-sandbox'],
};

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
  },

  projects: [
    // Auth setup: logs in once per role and writes storageState, reused below.
    // Runs on the Chromium engine; the storageState it writes is engine-neutral.
    {
      name: 'setup',
      testMatch: /auth\.setup\.js/,
      use: { launchOptions: CHROMIUM_LAUNCH },
    },
    {
      name: 'chromium',
      // Spread instead of `executablePath: undefined`: Playwright treats the
      // present-but-undefined key as "no browser" instead of falling back to
      // its own registry install.
      use: { ...devices['Desktop Chrome'], channel: undefined, launchOptions: CHROMIUM_LAUNCH },
      dependencies: ['setup'],
    },
    // Release-lane browser matrix (ADR-0028 revision): Integration stays
    // Chromium-only (`e2e-portal` gate); these projects run via the
    // `e2e-browser-matrix`/`e2e-msedge` gates and `npm run test:matrix`.
    // Firefox/WebKit come from the Playwright cache (npx playwright install).
    {
      name: 'firefox',
      use: { ...devices['Desktop Firefox'] },
      dependencies: ['setup'],
    },
    {
      name: 'webkit',
      use: { ...devices['Desktop Safari'] },
      dependencies: ['setup'],
    },
    {
      name: 'msedge',
      use: { ...devices['Desktop Edge'], channel: 'msedge', launchOptions: { args: ['--no-sandbox'] } },
      dependencies: ['setup'],
    },
  ],
});
