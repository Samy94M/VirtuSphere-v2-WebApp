'use strict';

// One way to take the pictures, shared by the read-only harness that the QA
// lanes call and by the separate update command. If these two ever took their
// screenshots differently, every reviewed baseline would be an image that no
// gate can reproduce, and the whole comparison would be theatre.

const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

const E2E_DIR = path.resolve(__dirname, '..');

function runCapture(outputDir, theme, label) {
  fs.mkdirSync(outputDir, { recursive: true });
  const cli = require.resolve('@playwright/test/cli');
  const result = spawnSync(process.execPath, [cli, 'test', '--project=visual'], {
    cwd: E2E_DIR,
    env: {
      ...process.env,
      VIRTUSPHERE_VISUAL_THEME: theme,
      VIRTUSPHERE_VISUAL_OUTPUT_DIR: outputDir,
    },
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  process.stdout.write(result.stdout || '');
  process.stderr.write(result.stderr || '');
  if (result.error) process.stderr.write(`visual spawn failed: ${result.error.message}\n`);
  if (result.status !== 0) throw new Error(`visual Playwright run failed: ${label}`);
}

/**
 * The set a run must have produced, compared against the contract rather than
 * read off the disk: a capture that quietly stopped being taken would otherwise
 * look like a page with nothing to report.
 */
function assertRunIsComplete(outputDir, expectedFiles, label) {
  const found = fs
    .readdirSync(outputDir, { withFileTypes: true })
    .filter((entry) => entry.isFile() && entry.name.endsWith('.png'))
    .map((entry) => entry.name)
    .sort();
  if (JSON.stringify(found) !== JSON.stringify(expectedFiles)) {
    throw new Error(
      `visual capture set mismatch for ${label}: expected ${expectedFiles.join(', ')}, got ${found.join(', ') || '(none)'}`
    );
  }
  return found;
}

module.exports = { E2E_DIR, assertRunIsComplete, runCapture };
