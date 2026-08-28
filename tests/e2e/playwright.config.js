// E2E config (ADR-0028, revised 2026-07-16): runs on the dev host against the
// running Docker stack AND as the e2e-portal gate of the Integration lane
// against the throwaway QA stack. Never part of the shipped artifact.
const { defineConfig, devices } = require('@playwright/test');
const { resolveBrowser } = require('./lib/browser-resolver');
const visualContract = require('./visual/runner-contract.json');

// One resolver owns both the runner preflight and Playwright launch path. It
// asks the installed, lockfile-pinned playwright-core for its exact revision;
// no user/revision literal and no competing "highest cache revision" scan.
const CHROMIUM_RESOLUTION = resolveBrowser('chromium');
if (!CHROMIUM_RESOLUTION.exists) {
  throw new Error(`Playwright Chromium missing: ${CHROMIUM_RESOLUTION.executablePath}`);
}
const CHROMIUM = CHROMIUM_RESOLUTION.executablePath;

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
      testIgnore: /[\\/]visual[\\/]/,
      use: { ...devices['Desktop Chrome'], channel: undefined, launchOptions: CHROMIUM_LAUNCH },
      dependencies: ['setup'],
    },
    {
      name: 'visual',
      testMatch: /[\\/]visual[\\/].*\.spec\.js/,
      use: {
        ...devices['Desktop Chrome'],
        channel: undefined,
        launchOptions: CHROMIUM_LAUNCH,
        locale: visualContract.locale,
        timezoneId: visualContract.timezoneId,
        viewport: {
          width: visualContract.viewports[0].width,
          height: visualContract.viewports[0].height,
        },
        deviceScaleFactor: visualContract.deviceScaleFactor,
        reducedMotion: visualContract.reducedMotion,
      },
      dependencies: ['setup'],
    },
    // Release-lane browser matrix (ADR-0028 revision): Integration stays
    // Chromium-only (`e2e-portal` gate); these projects run via the
    // `e2e-browser-matrix`/`e2e-msedge` gates and `npm run test:matrix`.
    // Firefox/WebKit come from the Playwright cache (npx playwright install).
    {
      name: 'firefox',
      testIgnore: /[\\/]visual[\\/]/,
      use: { ...devices['Desktop Firefox'] },
      dependencies: ['setup'],
    },
    {
      name: 'webkit',
      testIgnore: /[\\/]visual[\\/]/,
      use: { ...devices['Desktop Safari'] },
      dependencies: ['setup'],
    },
    {
      name: 'msedge',
      testIgnore: /[\\/]visual[\\/]/,
      use: { ...devices['Desktop Edge'], channel: 'msedge', launchOptions: { args: ['--no-sandbox'] } },
      dependencies: ['setup'],
    },
  ],
});
