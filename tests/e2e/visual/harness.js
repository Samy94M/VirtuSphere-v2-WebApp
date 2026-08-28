'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const contract = require('./runner-contract.json');
const { validateMetadata } = require('./metadata');
const { assertVisualQaIsolation } = require('../lib/visual-seed');

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

function pngDecoder() {
  const coreRoot = path.dirname(require.resolve('playwright-core'));
  return require(path.join(coreRoot, 'lib', 'utilsBundle.js')).PNG;
}

function pngFiles(root) {
  const found = [];
  const walk = (dir) => {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
      const full = path.join(dir, entry.name);
      if (entry.isDirectory()) walk(full);
      if (entry.isFile() && entry.name.endsWith('.png')) found.push(path.relative(root, full).replace(/\\/g, '/'));
    }
  };
  walk(root);
  return found.sort();
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

function runPlaywright(e2eDir, artifactDir, theme, iteration, position, total) {
  const outputDir = path.join(artifactDir, theme, `run-${iteration}`);
  fs.mkdirSync(outputDir, { recursive: true });
  process.stdout.write(`[${position}/${total}] RUN visual-${theme}-${iteration}\n`);
  const cli = require.resolve('@playwright/test/cli');
  const result = spawnSync(process.execPath, [cli, 'test', '--project=visual'], {
    cwd: e2eDir,
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
  if (result.status !== 0) {
    process.stdout.write(`[${position}/${total}] fail visual-${theme}-${iteration}\n`);
    throw new Error(`visual Playwright run failed: ${theme} run ${iteration}`);
  }
  process.stdout.write(`[${position}/${total}] pass visual-${theme}-${iteration}\n`);
}

function compareRuns(artifactDir, theme) {
  const firstRoot = path.join(artifactDir, theme, 'run-1');
  const secondRoot = path.join(artifactDir, theme, 'run-2');
  const firstFiles = pngFiles(firstRoot);
  const secondFiles = pngFiles(secondRoot);
  if (firstFiles.length === 0 || JSON.stringify(firstFiles) !== JSON.stringify(secondFiles)) {
    throw new Error(`visual screenshot set mismatch for ${theme}`);
  }
  return firstFiles.map((relative) => ({
    file: relative,
    ...comparePngs(path.join(firstRoot, relative), path.join(secondRoot, relative), contract.pixelComparison),
  }));
}

async function main() {
  if (process.env.UPDATE_SNAPSHOTS || process.env.VIRTUSPHERE_UPDATE_VISUAL_BASELINES) {
    throw new Error('infrastructure_error: Etappe 11 never updates visual baselines');
  }
  validateHarnessEnvironment();
  const artifactDir = process.env.VIRTUSPHERE_VISUAL_ARTIFACT_DIR;
  if (!artifactDir) throw new Error('infrastructure_error: VIRTUSPHERE_VISUAL_ARTIFACT_DIR is required');
  fs.mkdirSync(artifactDir, { recursive: true });

  const metadata = await validateMetadata(contract);
  fs.writeFileSync(path.join(artifactDir, 'metadata.actual.json'), JSON.stringify(metadata.actual, null, 2) + '\n');
  requireMatchingMetadata(metadata);

  const e2eDir = path.resolve(__dirname, '..');
  let position = 0;
  const total = contract.themes.length * 2;
  for (const theme of contract.themes) {
    for (const iteration of [1, 2]) {
      runPlaywright(e2eDir, artifactDir, theme, iteration, ++position, total);
    }
  }

  const comparisons = {};
  for (const theme of contract.themes) {
    comparisons[theme] = compareRuns(artifactDir, theme);
  }
  fs.writeFileSync(path.join(artifactDir, 'comparison.json'), JSON.stringify({ contract: contract.pixelComparison, themes: comparisons }, null, 2) + '\n');
  const mismatchedThemes = contract.themes.filter((theme) => comparisons[theme].some((entry) => !entry.ok));
  if (mismatchedThemes.length > 0) {
    throw new Error(`visual pixel mismatch outside tolerance for ${mismatchedThemes.join(', ')}`);
  }
  process.stdout.write('visual determinism: light and dark runs are pixel-identical\n');
}

if (require.main === module) {
  main().catch((error) => {
    process.stderr.write(`${error.message}\n`);
    process.exitCode = error.message.startsWith('infrastructure_error:') ? 2 : 1;
  });
}

module.exports = { comparePngs, compareRuns, main, pngFiles, requireMatchingMetadata, validateHarnessEnvironment };
