'use strict';

const crypto = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { chromium } = require('playwright-core');
const { resolveBrowser } = require('../lib/browser-resolver');

function sha256(file) {
  return crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');
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
  const fontRoot = path.join(process.env.WINDIR || '', 'Fonts');
  const actual = {
    ...contract,
    platform: process.platform,
    arch: process.arch,
    osRelease: os.release(),
    browser: {
      engine: resolution.engine,
      revision: resolution.revision,
      version: null,
    },
    playwrightVersion: require('@playwright/test/package.json').version,
    fonts: contract.fonts.map((font) => {
      const file = path.join(fontRoot, font.file);
      return { file: font.file, sha256: fs.existsSync(file) ? sha256(file) : null };
    }),
  };

  if (resolution.exists) {
    const browser = await chromium.launch({
      executablePath: resolution.executablePath,
      headless: true,
      args: ['--no-sandbox'],
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

module.exports = { collectMetadata, compareMetadata, validateMetadata };
