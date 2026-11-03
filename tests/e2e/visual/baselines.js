'use strict';

// Etappe 17 owns the reviewed target images. Etappe 11 only proved that two runs
// of the SAME build are pixel-identical, which says nothing about whether the
// build looks right; this module adds the second half: every capture is compared
// against a committed PNG that a person looked at, at the same zero tolerance.
//
// Three rules are load-bearing rather than convenience:
//   * The expected file set is DERIVED from the contract (pages x viewports x
//     themes), never from whatever landed on disk. A capture that silently stops
//     being taken would otherwise pass as "no mismatch".
//   * A baseline is only valid together with the runner that produced it. The
//     manifest carries the runner identity and a SHA-256 per file, so a PNG
//     swapped by hand, or a set taken on a different Chromium/font build, is an
//     infrastructure error and not a design finding.
//   * Nothing in here writes into the baseline directory. Updating is a separate,
//     explicitly invoked command (update-baselines.js); the harness that the QA
//     lanes call can only read.

const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');

function pngLibrary() {
  const coreRoot = path.dirname(require.resolve('playwright-core'));
  return require(path.join(coreRoot, 'lib', 'utilsBundle.js')).PNG;
}

function baselineRoot(contract) {
  return path.join(__dirname, contract.baselines.dir);
}

function manifestFile(contract) {
  return path.join(baselineRoot(contract), contract.baselines.manifest);
}

/** theme/name.png for every page x viewport x theme the contract declares. */
function expectedBaselineFiles(contract) {
  const files = [];
  for (const theme of contract.themes) {
    for (const viewport of contract.viewports) {
      for (const page of contract.pages) {
        files.push(`${theme}/${page.name}-${viewport.name}.png`);
      }
    }
  }
  return files.sort();
}

/** Files one capture run must produce for a theme, without the theme prefix. */
function expectedRunFiles(contract) {
  const files = [];
  for (const viewport of contract.viewports) {
    for (const page of contract.pages) files.push(`${page.name}-${viewport.name}.png`);
  }
  return files.sort();
}

function sha256(file) {
  return crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');
}

/**
 * The runner identity a baseline set is bound to. Only the fields that change
 * how a pixel is rasterised belong here; the reason and timestamp are review
 * metadata and are deliberately not part of the comparison.
 */
function runnerIdentity(metadata) {
  return {
    runnerId: metadata.runnerId,
    platform: metadata.platform,
    arch: metadata.arch,
    osRelease: metadata.osRelease,
    browser: metadata.browser,
    playwrightVersion: metadata.playwrightVersion,
    fonts: metadata.fonts,
    locale: metadata.locale,
    timezoneId: metadata.timezoneId,
    viewports: metadata.viewports,
    deviceScaleFactor: metadata.deviceScaleFactor,
    launchArgs: metadata.launchArgs,
    screenshotScale: metadata.screenshotScale,
    themes: metadata.themes,
    pages: metadata.pages,
    masks: metadata.masks,
    maskColor: metadata.maskColor,
  };
}

function readManifest(contract) {
  const file = manifestFile(contract);
  if (!fs.existsSync(file)) return null;
  return JSON.parse(fs.readFileSync(file, 'utf8'));
}

function writeManifest(contract, manifest) {
  fs.mkdirSync(baselineRoot(contract), { recursive: true });
  fs.writeFileSync(manifestFile(contract), JSON.stringify(manifest, null, 2) + '\n');
}

/**
 * Everything that must hold before a single pixel is compared. Each finding is a
 * sentence naming the file, because the operator reading a red gate has neither
 * the manifest nor the directory in front of them.
 *
 * The two kinds are not interchangeable and decide the exit code: a set produced
 * on a different runner says nothing about this build (`infrastructure`), while a
 * missing or hand-edited image means the reviewed set itself is not trustworthy
 * (`baseline`) and must be answered by a person, not by a retry.
 */
function verifyBaselineSet(contract, metadata) {
  const problems = [];
  const manifest = readManifest(contract);
  if (!manifest) {
    problems.push({ kind: 'baseline', message: `no baseline manifest at ${manifestFile(contract)}` });
    return { ok: false, problems, manifest: null };
  }
  const wanted = JSON.stringify(runnerIdentity(metadata));
  const stored = JSON.stringify(manifest.runner || {});
  if (wanted !== stored) {
    problems.push({
      kind: 'infrastructure',
      message: 'baseline manifest was produced by a different runner or contract than this run',
    });
  }
  const expected = expectedBaselineFiles(contract);
  const recorded = Object.keys(manifest.files || {}).sort();
  if (JSON.stringify(expected) !== JSON.stringify(recorded)) {
    problems.push({
      kind: 'baseline',
      message: `baseline manifest lists ${recorded.length} images, the contract declares ${expected.length}`,
    });
  }
  for (const relative of expected) {
    const file = path.join(baselineRoot(contract), relative);
    if (!fs.existsSync(file)) {
      problems.push({ kind: 'baseline', message: `baseline image missing: ${relative}` });
      continue;
    }
    const digest = manifest.files ? manifest.files[relative] : undefined;
    if (digest && sha256(file) !== digest) {
      problems.push({ kind: 'baseline', message: `baseline image does not match its manifest digest: ${relative}` });
    }
  }
  return { ok: problems.length === 0, problems, manifest };
}

/**
 * Writes a review image next to the report: unchanged pixels dimmed to a grey
 * ground, differing ones painted opaque red. A reviewer has to be able to see
 * WHERE a change sits without loading both PNGs into an editor.
 */
function writeDiffImage(baselineFile, actualFile, diffFile) {
  const PNG = pngLibrary();
  const baseline = PNG.sync.read(fs.readFileSync(baselineFile));
  const actual = PNG.sync.read(fs.readFileSync(actualFile));
  const width = Math.max(baseline.width, actual.width);
  const height = Math.max(baseline.height, actual.height);
  const out = new PNG({ width, height });
  let diffPixels = 0;
  let minX = width;
  let minY = height;
  let maxX = -1;
  let maxY = -1;
  const at = (image, x, y) => (x < image.width && y < image.height ? (image.width * y + x) << 2 : -1);
  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) {
      const target = (width * y + x) << 2;
      const a = at(baseline, x, y);
      const b = at(actual, x, y);
      let different = a < 0 || b < 0;
      if (!different) {
        for (let channel = 0; channel < 4; channel++) {
          if (baseline.data[a + channel] !== actual.data[b + channel]) { different = true; break; }
        }
      }
      if (different) {
        diffPixels++;
        if (x < minX) minX = x;
        if (y < minY) minY = y;
        if (x > maxX) maxX = x;
        if (y > maxY) maxY = y;
        out.data.set([255, 0, 0, 255], target);
        continue;
      }
      const grey = Math.round((baseline.data[a] + baseline.data[a + 1] + baseline.data[a + 2]) / 3);
      const dimmed = Math.round(255 - (255 - grey) * 0.15);
      out.data.set([dimmed, dimmed, dimmed, 255], target);
    }
  }
  fs.mkdirSync(path.dirname(diffFile), { recursive: true });
  fs.writeFileSync(diffFile, PNG.sync.write(out));
  return {
    diffPixels,
    box: maxX < 0 ? null : { x: minX, y: minY, width: maxX - minX + 1, height: maxY - minY + 1 },
    width,
    height,
  };
}

module.exports = {
  baselineRoot,
  expectedBaselineFiles,
  expectedRunFiles,
  manifestFile,
  pngLibrary,
  readManifest,
  runnerIdentity,
  sha256,
  verifyBaselineSet,
  writeDiffImage,
  writeManifest,
};
