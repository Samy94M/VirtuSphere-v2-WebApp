'use strict';

// The ONLY writer of tests/e2e/visual/baselines/. It is never reachable from a
// lane: scripts/check.ps1 clears the update variables before it calls the
// harness, and the harness refuses to start while any of them is set. This file
// is invoked by a person, with a reason, after looking at the change.
//
//   npm run visual:update -- --reason "Etappe 16 Slate-/Indigo-Refresh"
//
// What it will not do, and why:
//   * It refuses on a runner or font mismatch. A baseline taken on a different
//     Chromium or a different Segoe UI is not a decision about the design, it is
//     a picture of another machine, and committing it would make every later
//     comparison meaningless.
//   * It never overwrites in place without evidence. The previous image and a
//     diff image go to the review directory first. Those are audit artifacts of
//     what was replaced; they are not, and must not become, a second baseline.
//   * It writes a manifest with the runner identity, a reason and a SHA-256 per
//     file, so the harness can tell a reviewed set from a directory somebody
//     copied images into.

const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const contract = require('./runner-contract.json');
const { validateMetadata } = require('./metadata');
const { assertVisualQaIsolation } = require('../lib/visual-seed');
const { assertRunIsComplete, runCapture } = require('./capture');
const {
  baselineRoot,
  expectedBaselineFiles,
  expectedRunFiles,
  runnerIdentity,
  sha256,
  writeDiffImage,
  writeManifest,
} = require('./baselines');

const MINIMUM_REASON_LENGTH = 12;

function parseReason(argv) {
  const index = argv.indexOf('--reason');
  const value = index >= 0 ? String(argv[index + 1] || '').trim() : '';
  if (value.length < MINIMUM_REASON_LENGTH) {
    throw new Error(
      `--reason "<why this image changed>" is required and must be at least ${MINIMUM_REASON_LENGTH} characters`
    );
  }
  return value;
}

/**
 * A target image is only a decision about the design while the runner that took
 * it is the runner the gates use. On a different Chromium or a different Segoe UI
 * it is a picture of another machine, and committing it would silently retire the
 * comparison instead of updating it.
 */
function requireMatchingRunner(metadata) {
  if (!metadata.ok) {
    throw new Error(
      `runner metadata mismatch, refusing to overwrite reviewed baselines\n${metadata.mismatches.join('\n')}`
    );
  }
  return metadata.actual;
}

function requireExplicitAllowance(env = process.env) {
  if (env.VIRTUSPHERE_VISUAL_BASELINE_UPDATE !== '1') {
    throw new Error('VIRTUSPHERE_VISUAL_BASELINE_UPDATE=1 is required: updating baselines is a deliberate act');
  }
  return true;
}

async function main(argv = process.argv.slice(2), env = process.env) {
  const reason = parseReason(argv);
  requireExplicitAllowance(env);
  assertVisualQaIsolation(env);
  const reviewDir = env.VIRTUSPHERE_VISUAL_ARTIFACT_DIR
    || path.join(os.tmpdir(), `virtusphere-visual-baseline-review-${Date.now()}`);
  fs.mkdirSync(reviewDir, { recursive: true });

  const metadata = await validateMetadata(contract);
  fs.writeFileSync(path.join(reviewDir, 'metadata.actual.json'), JSON.stringify(metadata.actual, null, 2) + '\n');
  requireMatchingRunner(metadata);

  const runFiles = expectedRunFiles(contract);
  const changes = [];
  const total = contract.themes.length;
  let position = 0;
  for (const theme of contract.themes) {
    position += 1;
    const outputDir = path.join(reviewDir, theme, 'update-run');
    process.stdout.write(`[${position}/${total}] RUN visual-update-${theme}\n`);
    runCapture(outputDir, theme, `visual-update-${theme}`);
    assertRunIsComplete(outputDir, runFiles, `visual-update-${theme}`);

    for (const file of runFiles) {
      const relative = `${theme}/${file}`;
      const actual = path.join(outputDir, file);
      const target = path.join(baselineRoot(contract), theme, file);
      if (!fs.existsSync(target)) {
        changes.push({ file: relative, kind: 'added' });
      } else if (sha256(target) === sha256(actual)) {
        changes.push({ file: relative, kind: 'unchanged' });
      } else {
        const before = path.join(reviewDir, 'before', relative);
        fs.mkdirSync(path.dirname(before), { recursive: true });
        fs.copyFileSync(target, before);
        const diffFile = path.join(reviewDir, 'diff', relative);
        const diff = writeDiffImage(target, actual, diffFile);
        changes.push({
          file: relative,
          kind: 'replaced',
          diffPixels: diff.diffPixels,
          box: diff.box,
          before: path.relative(reviewDir, before).replace(/\\/g, '/'),
          diffImage: path.relative(reviewDir, diffFile).replace(/\\/g, '/'),
        });
      }
      fs.mkdirSync(path.dirname(target), { recursive: true });
      fs.copyFileSync(actual, target);
    }
    process.stdout.write(`[${position}/${total}] pass visual-update-${theme}\n`);
  }

  const files = {};
  for (const relative of expectedBaselineFiles(contract)) {
    files[relative] = sha256(path.join(baselineRoot(contract), relative));
  }
  writeManifest(contract, {
    schemaVersion: contract.schemaVersion,
    updatedAt: new Date().toISOString(),
    reason,
    runner: runnerIdentity(metadata.actual),
    files,
  });

  const report = { reason, updatedAt: new Date().toISOString(), reviewDir, changes };
  fs.writeFileSync(path.join(reviewDir, 'baseline-update.json'), JSON.stringify(report, null, 2) + '\n');
  const replaced = changes.filter((change) => change.kind === 'replaced');
  const added = changes.filter((change) => change.kind === 'added');
  process.stdout.write(
    `visual baselines updated: ${added.length} added, ${replaced.length} replaced, `
      + `${changes.length - added.length - replaced.length} unchanged\n`
  );
  for (const change of replaced) {
    process.stdout.write(`  ${change.file}: ${change.diffPixels} px, box ${JSON.stringify(change.box)}\n`);
  }
  process.stdout.write(`review evidence (previous image and diff image): ${reviewDir}\n`);
  process.stdout.write('review every diff image before committing; the previous images are audit artifacts only\n');
}

if (require.main === module) {
  main().catch((error) => {
    process.stderr.write(`${error.message}\n`);
    process.exitCode = 1;
  });
}

module.exports = { MINIMUM_REASON_LENGTH, main, parseReason, requireExplicitAllowance, requireMatchingRunner };
