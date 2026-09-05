'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');
const { resolveBrowser } = require('../lib/browser-resolver');
const { assertVisualQaIsolation } = require('../lib/visual-seed');
const { compareMetadata } = require('../visual/metadata');
const { comparePngs, refuseUpdateRequest, requireCleanCaptureScope, requireMatchingMetadata, requireUsableBaselines, validateHarnessEnvironment, UPDATE_ENV_NAMES } = require('../visual/harness');
const { expectedBaselineFiles, expectedRunFiles, runnerIdentity, verifyBaselineSet, writeDiffImage } = require('../visual/baselines');
const { MINIMUM_REASON_LENGTH, parseReason, requireExplicitAllowance, requireMatchingRunner } = require('../visual/update-baselines');
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

// --- Etappe 17: reviewte Sollbaselines und Release-Gate -----------------------

test('the QA harness refuses every way an update could reach it', () => {
  assert.deepEqual(UPDATE_ENV_NAMES, [
    'UPDATE_SNAPSHOTS',
    'VIRTUSPHERE_UPDATE_VISUAL_BASELINES',
    'VIRTUSPHERE_VISUAL_BASELINE_UPDATE',
  ]);
  assert.equal(refuseUpdateRequest({}), true);
  for (const name of UPDATE_ENV_NAMES) {
    assert.throws(
      () => refuseUpdateRequest({ [name]: '1' }),
      new RegExp('^Error: infrastructure_error: .*never updates baselines \\(' + name + ' is set\\)')
    );
  }
});

test('no lane hands an update variable to the harness', () => {
  const gate = fs.readFileSync(
    path.join(__dirname, '..', '..', '..', 'scripts', 'lib', 'check', 'gates-integration.ps1'),
    'utf8'
  );
  for (const name of UPDATE_ENV_NAMES) {
    assert.match(gate, new RegExp(name + "\\s*=\\s*''"), `${name} must be cleared before the harness runs`);
  }
  assert.doesNotMatch(gate, /--update-snapshots/);
});

test('updating requires an explicit allowance and a written reason', () => {
  assert.throws(() => requireExplicitAllowance({}), /VIRTUSPHERE_VISUAL_BASELINE_UPDATE=1 is required/);
  assert.equal(requireExplicitAllowance({ VIRTUSPHERE_VISUAL_BASELINE_UPDATE: '1' }), true);
  assert.throws(() => parseReason([]), /--reason/);
  assert.throws(() => parseReason(['--reason', 'zu kurz']), new RegExp(`${MINIMUM_REASON_LENGTH} characters`));
  assert.equal(parseReason(['--reason', 'Etappe 16 Slate-Refresh']), 'Etappe 16 Slate-Refresh');
});

test('a runner or font mismatch never overwrites a reviewed image', () => {
  assert.throws(
    () => requireMatchingRunner({ ok: false, actual: {}, mismatches: ['fonts[0].sha256: expected "a", got "b"'] }),
    /refusing to overwrite reviewed baselines[\s\S]*fonts\[0\]\.sha256/
  );
  assert.deepEqual(requireMatchingRunner({ ok: true, actual: { pinned: true }, mismatches: [] }), { pinned: true });
});

test('the expected image set is derived from the contract, not from the directory', () => {
  const expected = expectedBaselineFiles(contract);
  assert.equal(expected.length, contract.themes.length * contract.viewports.length * contract.pages.length);
  for (const theme of contract.themes) {
    for (const viewport of contract.viewports) {
      for (const page of contract.pages) {
        assert.ok(expected.includes(`${theme}/${page.name}-${viewport.name}.png`));
      }
    }
  }
  assert.equal(expectedRunFiles(contract).length, contract.viewports.length * contract.pages.length);
});

test('the contract carries desktop, the wrap breakpoint and mobile', () => {
  const names = contract.viewports.map((viewport) => viewport.name);
  assert.deepEqual(names, ['desktop', 'wrap', 'mobile']);
  const wrap = contract.viewports.find((viewport) => viewport.name === 'wrap');
  const css = fs.readFileSync(
    path.join(__dirname, '..', '..', '..', 'Docker', 'WebAPI', 'portal', 'assets', 'css', 'layout.css'),
    'utf8'
  );
  // The wrap viewport is only meaningful while it sits exactly on the shell's
  // own breakpoint; a stylesheet that moves it must move this number too.
  assert.match(css, new RegExp('@media \\(max-width: ' + wrap.width + 'px\\)'));
});

test('a mask is declared with its reason and covers only browser-drawn chrome', () => {
  assert.ok(Array.isArray(contract.masks) && contract.masks.length > 0);
  for (const entry of contract.masks) {
    assert.equal(typeof entry.selector, 'string');
    assert.ok(entry.reason && entry.reason.length > 40, 'every mask states why the area is not ours');
  }
  assert.deepEqual(contract.masks.map((entry) => entry.selector), ['input[type="file"]']);
  const spec = fs.readFileSync(path.join(__dirname, '..', 'specs', 'visual', 'portal.visual.spec.js'), 'utf8');
  assert.match(spec, /maskColor: contract\.maskColor/);
  // A mask that stops matching is a dead exemption, and a dead exemption is how
  // a masked area silently grows over something the portal does own.
  assert.match(spec, /matched nothing in this capture/);
});

test('the baseline set is refused without a manifest and on a foreign runner', () => {
  const emptyContract = { ...contract, baselines: { ...contract.baselines, dir: 'baselines-does-not-exist' } };
  const missing = verifyBaselineSet(emptyContract, contract);
  assert.equal(missing.ok, false);
  assert.equal(missing.problems[0].kind, 'baseline');
  assert.throws(() => requireUsableBaselines(missing), /reviewed visual baselines unusable/);
  assert.throws(() => requireUsableBaselines(missing), /npm run visual:update/);

  const foreign = {
    ok: false,
    manifest: {},
    problems: [{ kind: 'infrastructure', message: 'baseline manifest was produced by a different runner' }],
  };
  assert.throws(() => requireUsableBaselines(foreign), /^Error: infrastructure_error: /);
});

test('the runner identity a baseline is bound to excludes review prose', () => {
  const identity = runnerIdentity(contract);
  assert.equal('reason' in identity, false);
  assert.equal('updatedAt' in identity, false);
  for (const key of ['runnerId', 'browser', 'fonts', 'viewports', 'launchArgs', 'masks', 'pages']) {
    assert.ok(key in identity, `${key} decides how a pixel is rasterised and belongs to the identity`);
  }
});

test('a page that lists every row states its data precondition', () => {
  assert.equal(requireCleanCaptureScope({ missions: [], jobs: 0 }), true);
  // The first run against a committed image found a mission that
  // field-roundtrip.spec.js believed it had deleted, in ten of twelve images.
  // Naming the row is the whole point: without it the operator sees pixel diffs.
  assert.throws(
    () => requireCleanCaptureScope({ missions: ['43:e2ert-x'], jobs: 0 }),
    /^Error: infrastructure_error: [\s\S]*43:e2ert-x/
  );
  assert.throws(() => requireCleanCaptureScope({ missions: [], jobs: 2 }), /2 deploy job\(s\)/);
});

test('the committed baseline set matches its manifest and this contract', () => {
  const verification = verifyBaselineSet(contract, contract);
  assert.deepEqual(verification.problems.map((problem) => problem.message), []);
});

test('a diff image names the changed pixels and their bounding box', () => {
  const coreRoot = path.dirname(require.resolve('playwright-core'));
  const { PNG } = require(path.join(coreRoot, 'lib', 'utilsBundle.js'));
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'virtusphere-visual-diff-'));
  try {
    const make = (mutate) => {
      const image = new PNG({ width: 4, height: 4 });
      image.data.fill(255);
      if (mutate) image.data.set([0, 0, 0, 255], (4 * 2 + 1) << 2);
      return image;
    };
    const baseline = path.join(dir, 'baseline.png');
    const actual = path.join(dir, 'actual.png');
    const diff = path.join(dir, 'diff.png');
    fs.writeFileSync(baseline, PNG.sync.write(make(false)));
    fs.writeFileSync(actual, PNG.sync.write(make(true)));
    const report = writeDiffImage(baseline, actual, diff);
    assert.equal(report.diffPixels, 1);
    assert.deepEqual(report.box, { x: 1, y: 2, width: 1, height: 1 });
    assert.equal(fs.existsSync(diff), true);
  } finally {
    fs.rmSync(dir, { recursive: true, force: true });
  }
});
