'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');
const { resolveBrowser } = require('../lib/browser-resolver');
const { assertVisualQaIsolation } = require('../lib/visual-seed');
const { compareMetadata } = require('../visual/metadata');
const { comparePngs, requireMatchingMetadata, validateHarnessEnvironment } = require('../visual/harness');
const contract = require('../visual/runner-contract.json');

const isolatedEnv = {
  VIRTUSPHERE_VISUAL_QA_ALLOWED: '1',
  VIRTUSPHERE_QA_PROJECT: 'virtusphere-qa',
  VIRTUSPHERE_BASE_URL: 'http://127.0.0.1:8031/portal/',
  VIRTUSPHERE_PHP_CONTAINER: 'virtusphere-qa-php-1',
  VIRTUSPHERE_MYSQL_CONTAINER: 'virtusphere-qa-mysql-1',
  DB_NAME: 'deploymentcenter',
};

test('browser resolver honors only an existing explicit Chromium override', () => {
  const result = resolveBrowser('chromium', {
    env: { PLAYWRIGHT_CHROMIUM: 'C:\\qa\\chrome.exe' },
    exists: (file) => file.endsWith('chrome.exe'),
    browserTypes: { chromium: { executablePath: () => { throw new Error('must not be called'); } } },
  });
  assert.equal(result.exists, true);
  assert.equal(result.source, 'PLAYWRIGHT_CHROMIUM');
});

test('browser resolver uses the lockfile Playwright revision instead of the highest cache directory', () => {
  const result = resolveBrowser('chromium');
  assert.equal(result.exists, true);
  assert.equal(result.source, 'playwright-core');
  assert.match(result.revision, /^\d+$/);
  assert.equal(result.executablePath, require('playwright-core').chromium.executablePath());
});

test('browser resolver rejects an unknown engine', () => {
  assert.throws(() => resolveBrowser('edge'), /unsupported Playwright engine/);
});

test('metadata comparison reports an exact runner mismatch', () => {
  const expected = { osRelease: 'fixed', browser: { revision: '1' } };
  assert.deepEqual(compareMetadata(expected, expected), []);
  assert.match(compareMetadata(expected, { osRelease: 'other', browser: { revision: '1' } })[0], /osRelease/);
});

test('metadata mismatch is an infrastructure error before any visual run', () => {
  assert.throws(
    () => requireMatchingMetadata({ ok: false, actual: {}, mismatches: ['osRelease: mismatch'] }),
    /^Error: infrastructure_error: visual runner metadata mismatch/
  );
  assert.deepEqual(requireMatchingMetadata({ ok: true, actual: { pinned: true }, mismatches: [] }), { pinned: true });
});

test('visual seed accepts only the exact throwaway QA identity', () => {
  assert.equal(assertVisualQaIsolation(isolatedEnv), true);
  assert.throws(
    () => assertVisualQaIsolation({ ...isolatedEnv, VIRTUSPHERE_BASE_URL: 'https://shared.example/portal/' }),
    /visual seed isolation refused/
  );
});

test('visual harness refuses a dev fallback before authentication starts', () => {
  assert.equal(validateHarnessEnvironment(isolatedEnv), true);
  assert.throws(
    () => validateHarnessEnvironment({ ...isolatedEnv, VIRTUSPHERE_PHP_CONTAINER: 'virtusphere-v2-webapp-php-1' }),
    /infrastructure_error: visual seed isolation refused/
  );
});

test('visual capture pins the session countdown contract before navigation', () => {
  const spec = fs.readFileSync(path.join(__dirname, '..', 'specs', 'visual', 'portal.visual.spec.js'), 'utf8');
  assert.equal(Number.isInteger(contract.sessionRemainingSeconds), true);
  assert.ok(contract.sessionRemainingSeconds > 300);
  assert.match(spec, /page\.clock\.setFixedTime\(new Date\(contract\.clock\)\)/);
  assert.doesNotMatch(spec, /page\.clock\.install/);
  assert.match(spec, /timer\.setAttribute\('data-expires-in', String\(sessionRemainingSeconds\)\)/);
  assert.match(spec, /sessionRemainingSeconds: contract\.sessionRemainingSeconds/);
  assert.match(spec, /expect\(page\.locator\('\[data-session-clock\]'\)\)\.toHaveText\(sessionClockText\)/);
});

test('visual runner pins the deterministic software compositor arguments', () => {
  assert.deepEqual(contract.launchArgs, [
    '--no-sandbox',
    '--disable-gpu',
    '--run-all-compositor-stages-before-draw',
    '--force-color-profile=srgb',
    '--force-device-scale-factor=1',
    '--disable-lcd-text',
    '--disable-threaded-animation',
    '--disable-threaded-scrolling',
    '--disable-checker-imaging',
  ]);
  const config = fs.readFileSync(path.join(__dirname, '..', 'playwright.config.js'), 'utf8');
  assert.match(config, /launchOptions: \{ \.\.\.CHROMIUM_LAUNCH, args: visualContract\.launchArgs \}/);
});

test('pixel comparison decodes PNG pixels and rejects one changed channel', () => {
  const coreRoot = path.dirname(require.resolve('playwright-core'));
  const { PNG } = require(path.join(coreRoot, 'lib', 'utilsBundle.js'));
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'virtusphere-visual-pixels-'));
  const first = path.join(dir, 'first.png');
  const second = path.join(dir, 'second.png');
  try {
    const one = new PNG({ width: 1, height: 1 });
    one.data.set([10, 20, 30, 255]);
    fs.writeFileSync(first, PNG.sync.write(one));
    fs.writeFileSync(second, PNG.sync.write(one));
    assert.equal(comparePngs(first, second, { channelThreshold: 0, maxDiffPixelRatio: 0 }).ok, true);
    const changed = new PNG({ width: 1, height: 1 });
    changed.data.set([11, 20, 30, 255]);
    fs.writeFileSync(second, PNG.sync.write(changed));
    assert.equal(comparePngs(first, second, { channelThreshold: 0, maxDiffPixelRatio: 0 }).ok, false);
  } finally {
    fs.rmSync(dir, { recursive: true, force: true });
  }
});
