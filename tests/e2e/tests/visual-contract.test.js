'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');
const { resolveBrowser, revisionFromExecutablePath } = require('../lib/browser-resolver');
const { assertVisualQaIsolation } = require('../lib/visual-seed');
const { BASE_IMAGE_LABEL, buildActual, compareMetadata, platformRelease, validateMetadata } = require('../visual/metadata');
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
  VIRTUSPHERE_VISUAL_BROWSER_CONTAINER: 'virtusphere-qa-visual-browser-1',
  VIRTUSPHERE_VISUAL_BROWSER_ENDPOINT: 'ws://127.0.0.1:8034/',
};

const REPO = path.join(__dirname, '..', '..', '..');
const read = (relative) => fs.readFileSync(path.join(REPO, relative), 'utf8').replace(/\r\n/g, '\n');

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

test('Linux visual metadata pins the distribution release, not a rolling host kernel', () => {
  const release = 'NAME="Ubuntu"\nID=ubuntu\nVERSION_ID="24.04"\n';
  assert.equal(platformRelease('linux', '6.17.0-1022-azure', release), 'ubuntu-24.04');
  assert.equal(platformRelease('linux', 'fallback-kernel', 'NAME=unknown\n'), 'fallback-kernel');
  assert.equal(platformRelease('win32', '10.0.26200', release), '10.0.26200');
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

test('visual capture applies the badge fixture before the first paint, never after load', () => {
  // BK1 (02.11.2026): changing an already painted badge left Chromium's partial
  // re-raster to draw the round badge caps in several byte-different ways; the
  // fixture set while the document is parsed gave one image in 40 replays.
  const spec = fs.readFileSync(path.join(__dirname, '..', 'specs', 'visual', 'portal.visual.spec.js'), 'utf8');
  assert.match(spec, /new MutationObserver\(applyOverviewBadges\)\.observe\(document, \{ childList: true, subtree: true, characterData: true \}\)/);
  assert.match(spec, /contract\.pages\.flatMap\(\(entry\) => entry\.fixture\?\.overviewBadges \|\| \[\]\)/);
  assert.doesNotMatch(spec, /badge\.evaluate\(/);
  assert.match(spec, /await expect\(badge\)\.toHaveClass\(badgeFixture\.className\)/);
  assert.match(spec, /await expect\(badge\)\.toHaveText\(badgeFixture\.text\)/);
});

test('visual runner pins the deterministic software compositor arguments', () => {
  // --disable-partial-raster (BK2, 03.11.2026): whether Chromium re-rastered a
  // painted tile in part or whole decided the round corners of the session
  // button (deploy-desktop) and the queue card (deploy-mobile); with whole-tile
  // raster every one of the 18 images had one form in 30 of 30 replays per theme.
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
    '--disable-partial-raster',
  ]);
  const config = fs.readFileSync(path.join(__dirname, '..', 'playwright.config.js'), 'utf8');
  // VC1: no host executable path travels to the container browser.
  assert.match(config, /launchOptions: \{ args: visualContract\.launchArgs \}/);
  assert.match(config, /connectOptions: \{ wsEndpoint: VISUAL_BROWSER_ENDPOINT, exposeNetwork: '<loopback>' \}/);
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
  for (const key of ['runnerId', 'container', 'browser', 'fonts', 'fontSet', 'renderedFonts', 'viewports', 'launchArgs', 'masks', 'pages']) {
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

// --- VC1: ein Sollbildsatz im gepinnten Linux-Container -----------------------

const escapeRegExp = (value) => value.replace(/[.*+?^${}()|[\]\\/]/g, '\\$&');

// What a container that matches the contract would report, so each case below
// changes exactly one measured value.
function measuredFromContract(overrides = {}) {
  const [osId, ...osVersion] = contract.osRelease.split('-');
  const container = {
    platform: contract.platform,
    arch: contract.arch,
    osReleaseText: `ID=${osId}\nVERSION_ID="${osVersion.join('-')}"\n`,
    playwrightVersion: contract.playwrightVersion,
    chromiumExecutable: `/ms-playwright/chromium-${contract.browser.revision}/chrome-linux/chrome`,
    chromiumExists: true,
    fonts: contract.fonts.map((font) => ({ ...font })),
    fontSet: { ...contract.fontSet },
    image: contract.container.image,
    ...(overrides.container || {}),
  };
  const browser = {
    version: contract.browser.version,
    commandLine: ['/ms-playwright/chrome', '--remote-debugging-pipe', ...contract.launchArgs],
    renderedFonts: JSON.parse(JSON.stringify(contract.renderedFonts)),
    ...(overrides.browser || {}),
  };
  return buildActual(contract, {
    container: { value: container },
    browser: { value: browser },
    clientVersion: overrides.clientVersion || contract.playwrightVersion,
  });
}

const mismatchesOf = (measured) => [...measured.errors, ...compareMetadata(contract, measured.actual)];

test('the contract describes the pinned Linux container, not a host', () => {
  assert.equal(contract.platform, 'linux');
  assert.match(contract.osRelease, /^ubuntu-\d+\.\d+$/);
  assert.match(contract.container.image, /^mcr\.microsoft\.com\/playwright:v\d+\.\d+\.\d+-noble@sha256:[0-9a-f]{64}$/);
  // A host-file font entry (the old Windows contract) would hash the host again.
  for (const font of contract.fonts) {
    assert.equal(typeof font.query, 'string', 'every contract font is a fontconfig query measured in the container');
    assert.match(font.sha256, /^[0-9a-f]{64}$/);
  }
  assert.ok(Number.isInteger(contract.fontSet.count) && contract.fontSet.count > 0);
  assert.match(contract.fontSet.sha256, /^[0-9a-f]{64}$/);
  // The face Chromium really renders the portal body with, regular and bold;
  // never a web font (the portal ships none, ADR air gap).
  assert.deepEqual(contract.renderedFonts.map((entry) => entry.weight), ['regular', 'bold']);
  for (const entry of contract.renderedFonts) {
    assert.ok(entry.fonts.length > 0);
    for (const font of entry.fonts) assert.equal(font.isCustomFont, false);
  }
});

test('the rendered-font probe asks with the portal body stack', () => {
  const { portalFontStack } = require('../visual/metadata');
  assert.match(portalFontStack(), /^system-ui, /);
  assert.throws(() => portalFontStack('body { color: red; }'), /portal body font stack not found/);
});

test('image tag, lockfile Playwright and the Dockerfile name one build', () => {
  const lock = JSON.parse(fs.readFileSync(path.join(__dirname, '..', 'package-lock.json'), 'utf8'));
  const locked = lock.packages['node_modules/playwright-core'].version;
  assert.equal(contract.playwrightVersion, locked);
  assert.equal(contract.container.image.match(/:v(\d+\.\d+\.\d+)-/)[1], locked, 'the image must carry the lockfile Playwright');
  const dockerfile = read('Docker/qa/visual-browser/Dockerfile');
  assert.match(dockerfile, new RegExp(`^FROM ${escapeRegExp(contract.container.image)}$`, 'm'));
  assert.match(dockerfile, new RegExp(`^LABEL ${escapeRegExp(BASE_IMAGE_LABEL)}="${escapeRegExp(contract.container.image)}"$`, 'm'));
  // The probe requires the server's playwright-core from the image WORKDIR.
  assert.match(dockerfile, /^WORKDIR \/opt\/visual$/m);
  const probe = fs.readFileSync(path.join(__dirname, '..', 'visual', 'container-probe.js'), 'utf8');
  assert.match(probe, /'\/opt\/visual\/node_modules\/playwright-core'/);
});

test('the visual browser service has no mount, no QA network and only a loopback port', () => {
  const compose = read('Docker/qa/docker-compose.qa.yml');
  const start = compose.indexOf('\n  visual-browser:\n');
  assert.ok(start > 0, 'visual-browser service missing');
  const rest = compose.slice(start + 1);
  const block = rest.slice(0, rest.search(/\n(?=\S)/));
  assert.doesNotMatch(block, /^\s+volumes:/m);
  assert.match(block, /^    ports:\n      - "127\.0\.0\.1:8034:3000"$/m);
  assert.match(block, /^    networks:\n      - visual$/m);
  assert.match(block, /^    cap_drop:\n      - ALL$/m);
  // The identity the runner starts and the identity the seed accepts are one.
  const identity = read('scripts/lib/check/qa-identity.ps1');
  assert.match(identity, new RegExp(`VisualBrowserContainer = '${escapeRegExp(isolatedEnv.VIRTUSPHERE_VISUAL_BROWSER_CONTAINER)}'`));
  assert.match(identity, new RegExp(`VisualBrowserEndpoint  = '${escapeRegExp(isolatedEnv.VIRTUSPHERE_VISUAL_BROWSER_ENDPOINT)}'`));
  assert.match(identity, /VisualBrowserService   = 'visual-browser'/);
});

test('a container that matches the contract measures no mismatch', () => {
  assert.deepEqual(mismatchesOf(measuredFromContract()), []);
  assert.equal(revisionFromExecutablePath('/ms-playwright/chromium_headless_shell-1228/chrome-linux/headless_shell'), '1228');
});

test('every measured runner difference is a mismatch, never a guess', () => {
  const cases = [
    [{ container: { image: contract.container.image.replace(/sha256:[0-9a-f]{4}/, 'sha256:zzzz') } }, /container\.image/],
    [{ container: { osReleaseText: 'ID=ubuntu\nVERSION_ID="26.04"\n' } }, /osRelease/],
    [{ container: { platform: 'win32' } }, /platform/],
    [{ container: { fontSet: { ...contract.fontSet, sha256: '0'.repeat(64) } } }, /fontSet\.sha256/],
    [{ container: { fonts: contract.fonts.map((font, i) => (i === 0 ? { ...font, sha256: '0'.repeat(64) } : font)) } }, /fonts\[0\]\.sha256/],
    [{ container: { chromiumExecutable: '/ms-playwright/chromium-1300/chrome-linux/chrome' } }, /browser\.revision/],
    [{ container: { chromiumExists: false } }, /container Chromium missing/],
    [{ browser: { version: `${contract.browser.version}-other` } }, /browser\.version/],
    [{ browser: { renderedFonts: [{ weight: 'regular', fonts: [{ familyName: 'WenQuanYi Zen Hei', postScriptName: 'WenQuanYiZenHei', isCustomFont: false }] }, contract.renderedFonts[1]] } }, /renderedFonts\[0\]\.fonts\[0\]\.familyName/],
    [{ clientVersion: '1.99.0' }, /playwrightVersion: expected .*client 1\.99\.0/],
  ];
  for (const [overrides, expected] of cases) {
    const found = mismatchesOf(measuredFromContract(overrides));
    assert.ok(found.some((line) => expected.test(line)), `${expected} expected in ${JSON.stringify(found)}`);
  }
});

test('the started switches come from the one browser main process in the container', () => {
  const { readBrowserProcessArgs } = require('../visual/metadata');
  const main = ['/ms-playwright/chrome', '--remote-debugging-pipe', '--disable-gpu'];
  const renderer = ['/ms-playwright/chrome', '--type=renderer', '--remote-debugging-pipe'];
  const server = ['node', 'cli.js', 'run-server'];
  const fake = (processes) => (exe, args) => {
    assert.deepEqual(args.slice(0, 2), ['exec', 'virtusphere-qa-visual-browser-1']);
    return JSON.stringify(processes);
  };
  assert.deepEqual(readBrowserProcessArgs('virtusphere-qa-visual-browser-1', fake([server, main, renderer])), main);
  assert.throws(() => readBrowserProcessArgs('virtusphere-qa-visual-browser-1', fake([server])), /found 0/);
  assert.throws(() => readBrowserProcessArgs('virtusphere-qa-visual-browser-1', fake([main, main])), /found 2/);
});

test('a browser started without a contract switch is refused by name', () => {
  const commandLine = ['/ms-playwright/chrome', ...contract.launchArgs.filter((arg) => arg !== '--disable-partial-raster')];
  const found = mismatchesOf(measuredFromContract({ browser: { commandLine } }));
  assert.ok(found.some((line) => /without contract switches: --disable-partial-raster/.test(line)), JSON.stringify(found));
});

test('a run outside the container measures nothing and is refused', async () => {
  const outside = await validateMetadata(contract, {}, {
    probeContainer: () => { throw new Error('must not be reached'); },
    probeBrowser: async () => { throw new Error('must not be reached'); },
  });
  assert.equal(outside.ok, false);
  assert.match(outside.mismatches[0], /VIRTUSPHERE_VISUAL_BROWSER_CONTAINER and VIRTUSPHERE_VISUAL_BROWSER_ENDPOINT are required/);
  assert.throws(() => requireMatchingMetadata(outside), /^Error: infrastructure_error: visual runner metadata mismatch/);
  assert.throws(() => requireMatchingRunner(outside), /refusing to overwrite reviewed baselines/);

  const stopped = await validateMetadata(contract, isolatedEnv, {
    probeContainer: () => { throw new Error('Error response from daemon: No such container'); },
    probeBrowser: async () => { throw new Error('connect ECONNREFUSED 127.0.0.1:8034'); },
  });
  assert.equal(stopped.ok, false);
  assert.ok(stopped.mismatches.some((line) => /container not measurable: Error response from daemon/.test(line)));
  assert.ok(stopped.mismatches.some((line) => /not reachable: connect ECONNREFUSED/.test(line)));
});

test('the visual seed and the spec refuse a host browser', () => {
  assert.throws(
    () => assertVisualQaIsolation({ ...isolatedEnv, VIRTUSPHERE_VISUAL_BROWSER_ENDPOINT: 'ws://192.0.2.10:3000/' }),
    /VIRTUSPHERE_VISUAL_BROWSER_ENDPOINT must equal ws:\/\/127\.0\.0\.1:8034\//
  );
  assert.throws(
    () => assertVisualQaIsolation({ ...isolatedEnv, VIRTUSPHERE_VISUAL_BROWSER_CONTAINER: '' }),
    /VIRTUSPHERE_VISUAL_BROWSER_CONTAINER must equal/
  );
  const spec = fs.readFileSync(path.join(__dirname, '..', 'specs', 'visual', 'portal.visual.spec.js'), 'utf8');
  assert.match(spec, /if \(!process\.env\.VIRTUSPHERE_VISUAL_BROWSER_ENDPOINT\) \{\r?\n\s+throw new Error\('infrastructure_error: /);
});

test('harness and writer start the same container before they measure', () => {
  const gate = read('scripts/lib/check/gates-integration.ps1');
  const writer = read('scripts/update-visual-baselines.ps1');
  for (const source of [gate, writer]) {
    assert.match(source, /\$browserEnv = Get-QaVisualBrowserEnv/);
    assert.match(source, /\$browser = Start-QaVisualBrowser/);
  }
  const runtime = read('scripts/lib/check/runtime.ps1');
  assert.match(runtime, /Invoke-QaCompose @\('up', '-d', '--build', '--wait', \$qaIdentity\.VisualBrowserService\)/);
});
