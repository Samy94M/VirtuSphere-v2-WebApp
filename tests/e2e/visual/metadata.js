'use strict';

const crypto = require('node:crypto');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { chromium } = require('playwright-core');
const { resolveBrowser } = require('../lib/browser-resolver');

function sha256(file) {
  return crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');
}

// A hosted Linux runner changes its kernel independently of the userspace that
// supplies Chromium and fontconfig. Pin the distribution release there; keep
// the existing Windows kernel/build identity unchanged.
function platformRelease(platform = process.platform, fallback = os.release(), releaseText = null) {
  if (platform !== 'linux') return fallback;
  try {
    const fields = Object.fromEntries(
      (releaseText === null ? fs.readFileSync('/etc/os-release', 'utf8') : releaseText)
        .split(/\r?\n/)
        .filter((line) => /^[A-Z0-9_]+=/.test(line))
        .map((line) => {
          const separator = line.indexOf('=');
          return [line.slice(0, separator), line.slice(separator + 1).replace(/^['"]|['"]$/g, '')];
        })
    );
    if (fields.ID && fields.VERSION_ID) return `${fields.ID}-${fields.VERSION_ID}`;
  } catch (_) {
    // The exact fallback remains visible and therefore fails a mismatching
    // contract; missing /etc/os-release never turns into a guessed success.
  }
  return fallback;
}

function fontconfigFile(query) {
  try {
    const file = execFileSync('fc-match', ['--format=%{file}', query], {
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'ignore'],
    }).trim();
    return path.isAbsolute(file) && fs.existsSync(file) ? file : null;
  } catch (_) {
    return null;
  }
}

function contractFont(font) {
  let file = null;
  if (process.platform === 'win32') {
    file = path.join(process.env.WINDIR || '', 'Fonts', font.file);
  } else if (process.platform === 'linux' && typeof font.query === 'string') {
    file = fontconfigFile(font.query);
  } else if (typeof font.path === 'string' && path.isAbsolute(font.path)) {
    file = font.path;
  }
  return {
    ...font,
    file: file === null ? font.file : path.basename(file),
    sha256: file !== null && fs.existsSync(file) ? sha256(file) : null,
  };
}

// The first Linux mismatch must still leave enough read-only evidence to write
// a real contract. These entries are diagnostic only until a reviewed Linux
// contract names its chosen queries in `fonts`; compareMetadata ignores extra
// actual fields and keeps the old contract hard red.
function detectedLinuxFonts() {
  if (process.platform !== 'linux') return [];
  return ['system-ui:style=Regular', 'system-ui:style=Bold', 'sans-serif:style=Regular']
    .map((query) => {
      const file = fontconfigFile(query);
      return {
        query,
        file: file === null ? null : path.basename(file),
        path: file,
        sha256: file === null ? null : sha256(file),
      };
    });
}

function compareMetadata(expected, actual) {
  const mismatches = [];
  const visit = (want, got, key) => {
    if (Array.isArray(want)) {
      if (!Array.isArray(got) || want.length !== got.length) {
        mismatches.push(`${key}: expected ${want.length} entries`);
        return;
      }
      want.forEach((value, index) => visit(value, got[index], `${key}[${index}]`));
      return;
    }
    if (want && typeof want === 'object') {
      if (!got || typeof got !== 'object') {
        mismatches.push(`${key}: missing object`);
        return;
      }
      Object.keys(want).forEach((child) => visit(want[child], got[child], key ? `${key}.${child}` : child));
      return;
    }
    if (want !== got) {
      mismatches.push(`${key}: expected ${JSON.stringify(want)}, got ${JSON.stringify(got)}`);
    }
  };
  visit(expected, actual, '');
  return mismatches;
}

async function collectMetadata(contract) {
  const resolution = resolveBrowser('chromium');
  const actual = {
    ...contract,
    platform: process.platform,
    arch: process.arch,
    osRelease: platformRelease(),
    browser: {
      engine: resolution.engine,
      revision: resolution.revision,
      version: null,
    },
    playwrightVersion: require('@playwright/test/package.json').version,
    fonts: contract.fonts.map(contractFont),
    detectedLinuxFonts: detectedLinuxFonts(),
  };

  if (resolution.exists) {
    const browser = await chromium.launch({
      executablePath: resolution.executablePath,
      headless: true,
      args: contract.launchArgs,
    });
    try {
      actual.browser.version = browser.version();
    } finally {
      await browser.close();
    }
  }
  return actual;
}

async function validateMetadata(contract) {
  const actual = await collectMetadata(contract);
  const mismatches = compareMetadata(contract, actual);
  return { actual, mismatches, ok: mismatches.length === 0 };
}

module.exports = {
  collectMetadata,
  compareMetadata,
  contractFont,
  detectedLinuxFonts,
  platformRelease,
  validateMetadata,
};
