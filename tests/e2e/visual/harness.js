'use strict';

// The read-only half of the visual contract, and the only half the QA lanes run.
//
// Etappe 11 compared two runs of the same build to each other. That proves the
// harness is deterministic and nothing else: a build whose every page had turned
// magenta would have passed it twice. Etappe 17 makes the committed, reviewed PNG
// the pass criterion. The tolerance stays at zero in both directions.
//
// The retry is the answer to the flake carried through Etappen 14D, 15 and 16 and
// it does not soften anything. A design regression is deterministic: it is in
// every capture, so no number of attempts will ever produce one that equals the
// baseline. Sub-pixel rasterisation noise is not: it disappears on the next
// capture. So "at least one attempt is byte-identical to the reviewed image"
// detects every regression while ignoring exactly the noise, without moving the
// threshold off zero. A retry that was needed is reported, counted and kept as an
// artifact - a harness that quietly retries is a harness nobody can judge.

const fs = require('node:fs');
const path = require('node:path');
const contract = require('./runner-contract.json');
const { validateMetadata } = require('./metadata');
const { assertVisualQaIsolation, visualCaptureScopeConflicts } = require('../lib/visual-seed');
const { assertRunIsComplete, runCapture } = require('./capture');
const {
  baselineRoot,
  expectedRunFiles,
  verifyBaselineSet,
  writeDiffImage,
} = require('./baselines');

// The three names an update path could arrive under. The harness refuses all of
// them, so
// no lane can reach an update through an inherited environment variable; the
// update command lives in its own entrypoint and is invoked by a person.
const UPDATE_ENV_NAMES = ['UPDATE_SNAPSHOTS', 'VIRTUSPHERE_UPDATE_VISUAL_BASELINES', 'VIRTUSPHERE_VISUAL_BASELINE_UPDATE'];

function refuseUpdateRequest(env = process.env) {
  for (const name of UPDATE_ENV_NAMES) {
    if (env[name]) {
      throw new Error(`infrastructure_error: the visual QA harness never updates baselines (${name} is set)`);
    }
  }
  return true;
}

function validateHarnessEnvironment(env = process.env) {
  try {
    assertVisualQaIsolation(env);
  } catch (error) {
    throw new Error(`infrastructure_error: ${error.message}`);
  }
  return true;
}

function requireMatchingMetadata(metadata) {
  if (!metadata.ok) {
    throw new Error(`infrastructure_error: visual runner metadata mismatch\n${metadata.mismatches.join('\n')}`);
  }
  return metadata.actual;
}

/**
 * The captured pages must show only rows the fixture owns. Naming the offending
 * rows turns ten unexplainable pixel diffs into one sentence that points at the
 * spec that left them behind.
 */
function requireCleanCaptureScope(conflicts) {
  const problems = [];
  if (conflicts.missions.length > 0) {
    problems.push(`missions the visual fixture does not own: ${conflicts.missions.join(', ')}`);
  }
  if (conflicts.jobs > 0) problems.push(`${conflicts.jobs} deploy job(s) in the QA database`);
  if (problems.length === 0) return true;
  throw new Error(
    'infrastructure_error: the QA database holds rows the captured pages render\n'
      + `${problems.join('\n')}\n`
      + 'a reviewed target image cannot describe another spec\'s leftovers; clean them up in the spec that created them'
  );
}

function requireUsableBaselines(verification) {
  if (verification.ok) return verification.manifest;
  const infrastructure = verification.problems.filter((problem) => problem.kind === 'infrastructure');
  const messages = verification.problems.map((problem) => problem.message).join('\n');
  const hint = 'run `npm run visual:update -- --reason "<review reason>"` after reviewing the change';
  if (infrastructure.length > 0) throw new Error(`infrastructure_error: reviewed visual baselines unusable\n${messages}`);
  throw new Error(`reviewed visual baselines unusable\n${messages}\n${hint}`);
}

function pngDecoder() {
  const { pngLibrary } = require('./baselines');
  return pngLibrary();
}

function comparePngs(firstFile, secondFile, policy) {
  const PNG = pngDecoder();
  const first = PNG.sync.read(fs.readFileSync(firstFile));
  const second = PNG.sync.read(fs.readFileSync(secondFile));
  if (first.width !== second.width || first.height !== second.height) {
    return { ok: false, width: first.width, height: first.height, diffPixels: null, diffPixelRatio: 1, reason: 'size mismatch' };
  }
  let diffPixels = 0;
  for (let offset = 0; offset < first.data.length; offset += 4) {
    let different = false;
    for (let channel = 0; channel < 4; channel++) {
      if (Math.abs(first.data[offset + channel] - second.data[offset + channel]) > policy.channelThreshold) {
        different = true;
        break;
      }
    }
    if (different) diffPixels++;
  }
  const totalPixels = first.width * first.height;
  const diffPixelRatio = diffPixels / totalPixels;
  return {
    ok: diffPixelRatio <= policy.maxDiffPixelRatio,
    width: first.width,
    height: first.height,
    diffPixels,
    diffPixelRatio,
  };
}

/**
 * One theme, attempt by attempt. `minimumAttempts` keeps the two stable runs that
 * Etappe 11 established as evidence even when the first one already matched;
 * further attempts are taken only for the files that have not matched yet.
 */
function verifyTheme(artifactDir, theme, progress) {
  const runFiles = expectedRunFiles(contract);
  const pending = new Set(runFiles);
  const results = new Map(runFiles.map((file) => [file, { file, matchedOnAttempt: null, attempts: [] }]));
  const { maxAttempts, minimumAttempts } = contract.baselines;
  let attemptsUsed = 0;

  for (let attempt = 1; attempt <= maxAttempts; attempt++) {
    if (attempt > minimumAttempts && pending.size === 0) break;
    const outputDir = path.join(artifactDir, theme, `run-${attempt}`);
    const label = `visual-${theme}-${attempt}`;
    progress(label);
    runCapture(outputDir, theme, label);
    assertRunIsComplete(outputDir, runFiles, label);
    attemptsUsed = attempt;

    // Every file of every attempt is compared, including the ones that already
    // matched: an attempt that is captured but never looked at is an attempt
    // that proves nothing, and the second run is exactly where the noise this
    // harness has to stay honest about shows up.
    for (const file of runFiles) {
      const actual = path.join(outputDir, file);
      const baseline = path.join(baselineRoot(contract), theme, file);
      const comparison = comparePngs(baseline, actual, contract.pixelComparison);
      if (comparison.ok) {
        pending.delete(file);
        if (results.get(file).matchedOnAttempt === null) results.get(file).matchedOnAttempt = attempt;
        results.get(file).attempts.push({ attempt, diffPixels: 0 });
        continue;
      }
      const diffFile = path.join(artifactDir, theme, 'diff', `${path.basename(file, '.png')}-attempt-${attempt}.png`);
      const diff = writeDiffImage(baseline, actual, diffFile);
      results.get(file).attempts.push({
        attempt,
        diffPixels: comparison.diffPixels === null ? diff.diffPixels : comparison.diffPixels,
        box: diff.box,
        reason: comparison.reason || null,
        diffImage: path.relative(artifactDir, diffFile).replace(/\\/g, '/'),
        actual: path.relative(artifactDir, actual).replace(/\\/g, '/'),
      });
    }
    if (attempt >= minimumAttempts && pending.size === 0) break;
  }

  return {
    theme,
    attemptsUsed,
    unmatched: [...pending].sort(),
    files: runFiles.map((file) => results.get(file)),
  };
}

async function main() {
  refuseUpdateRequest();
  validateHarnessEnvironment();
  const artifactDir = process.env.VIRTUSPHERE_VISUAL_ARTIFACT_DIR;
  if (!artifactDir) throw new Error('infrastructure_error: VIRTUSPHERE_VISUAL_ARTIFACT_DIR is required');
  fs.mkdirSync(artifactDir, { recursive: true });

  const metadata = await validateMetadata(contract);
  fs.writeFileSync(path.join(artifactDir, 'metadata.actual.json'), JSON.stringify(metadata.actual, null, 2) + '\n');
  requireMatchingMetadata(metadata);
  const manifest = requireUsableBaselines(verifyBaselineSet(contract, metadata.actual));
  requireCleanCaptureScope(visualCaptureScopeConflicts());

  // The progress unit is the theme, not the attempt: how many attempts a theme
  // needs is not known in advance, and a total that is never reached reads as an
  // aborted run. Each attempt still announces itself on its own line.
  const perFile = contract.pages.length * contract.viewports.length;
  const total = contract.themes.length;
  let position = 0;
  const themes = {};
  for (const theme of contract.themes) {
    position += 1;
    process.stdout.write(`[${position}/${total}] RUN visual-${theme} (${perFile} reviewed images)\n`);
    themes[theme] = verifyTheme(artifactDir, theme, (label) => {
      process.stdout.write(`  capture ${label}\n`);
    });
    const state = themes[theme].unmatched.length === 0 ? 'pass' : 'fail';
    process.stdout.write(`[${position}/${total}] ${state} visual-${theme} (${themes[theme].attemptsUsed} attempt(s))\n`);
  }

  // "Noise" is any file that failed at least one attempt while matching another:
  // the design is right and the rasteriser was not reproducible. It never fails
  // the run and it is never silent either.
  const noise = [];
  const failed = [];
  for (const theme of contract.themes) {
    for (const entry of themes[theme].files) {
      if (entry.matchedOnAttempt === null) {
        failed.push(`${theme}/${entry.file}`);
        continue;
      }
      const missed = entry.attempts.filter((attempt) => attempt.diffPixels !== 0);
      if (missed.length > 0) {
        noise.push(
          `${theme}/${entry.file} (matched on attempt ${entry.matchedOnAttempt}; `
            + `${missed.map((attempt) => `attempt ${attempt.attempt}: ${attempt.diffPixels} px`).join(', ')})`
        );
      }
    }
  }

  fs.writeFileSync(
    path.join(artifactDir, 'comparison.json'),
    JSON.stringify(
      {
        contract: contract.pixelComparison,
        baselineManifest: { updatedAt: manifest.updatedAt, reason: manifest.reason },
        imagesPerTheme: perFile,
        rasterisationNoise: noise,
        themes,
      },
      null,
      2
    ) + '\n'
  );

  if (failed.length > 0) {
    throw new Error(
      `visual baseline mismatch in every attempt: ${failed.join(', ')}\n` +
        `diff images and the actual captures are below ${artifactDir}`
    );
  }
  if (noise.length > 0) {
    process.stdout.write(`visual baselines: rasterisation noise observed, not a mismatch: ${noise.join('; ')}\n`);
  }
  process.stdout.write(
    `visual baselines: ${contract.themes.length * perFile} reviewed images matched at zero tolerance\n`
  );
}

if (require.main === module) {
  main().catch((error) => {
    process.stderr.write(`${error.message}\n`);
    process.exitCode = error.message.startsWith('infrastructure_error:') ? 2 : 1;
  });
}

module.exports = {
  UPDATE_ENV_NAMES,
  comparePngs,
  main,
  refuseUpdateRequest,
  requireCleanCaptureScope,
  requireMatchingMetadata,
  requireUsableBaselines,
  validateHarnessEnvironment,
  verifyTheme,
};
