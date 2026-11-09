'use strict';

// C5: the browser specs recognise the portal error page by one attribute, which
// errors.php writes and lib/error-page.js names. This test holds the two sides
// and the specs together, in both directions that fail silently:
//  - errors.php renames or drops the attribute: every spec stops seeing the page;
//  - a page sweep stops checking for it, or a spec goes back to the title text,
//    which turns green the day the title is reworded or translated.
// The sweeps are derived from the specs directory (every spec that walks
// PORTAL_PAGES), never listed, so a new sweep is covered without editing this file.

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const { ERROR_PAGE_ATTRIBUTE, ERROR_PAGE_SELECTOR } = require('../lib/error-page');

const E2E_DIR = path.join(__dirname, '..');
const SPECS_DIR = path.join(E2E_DIR, 'specs');
const LIB_DIR = path.join(E2E_DIR, 'lib');
const ERRORS_PHP = path.join(E2E_DIR, '..', '..', 'Docker', 'WebAPI', 'lib', 'errors.php');

function jsFiles(dir) {
  return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) return jsFiles(full);
    return entry.name.endsWith('.js') ? [full] : [];
  });
}

function errorsSource() {
  return fs.readFileSync(ERRORS_PHP, 'utf8');
}

// The body of virtusphere_render_error_page(), up to the next top-level function.
function rendererBody() {
  const source = errorsSource();
  const start = source.indexOf('function virtusphere_render_error_page(');
  assert.ok(start >= 0, 'virtusphere_render_error_page() not found in lib/errors.php');
  const next = source.indexOf('\nfunction ', start + 1);
  return next < 0 ? source.slice(start) : source.slice(start, next);
}

function decodePhpText(text) {
  return text.replace(/&amp;/g, '&').trim();
}

test('errors.php declares the attribute the specs look for', () => {
  const match = errorsSource().match(/const VIRTUSPHERE_ERROR_PAGE_ATTRIBUTE = '([^']+)';/);
  assert.ok(match, 'lib/errors.php must declare VIRTUSPHERE_ERROR_PAGE_ATTRIBUTE');
  assert.equal(match[1], ERROR_PAGE_ATTRIBUTE);
  assert.equal(ERROR_PAGE_SELECTOR, `[${ERROR_PAGE_ATTRIBUTE}]`);
});

test('the error renderer writes the attribute on its <main>', () => {
  const body = rendererBody();
  assert.match(
    body,
    /<main ' \. VIRTUSPHERE_ERROR_PAGE_ATTRIBUTE \. '="1">/,
    'virtusphere_render_error_page() must open <main> with the attribute',
  );
  assert.equal((body.match(/<main\b/g) || []).length, 1, 'exactly one <main> in the error page');
});

test('every page sweep checks each page for the error page', () => {
  const sweeps = jsFiles(SPECS_DIR).filter((file) => {
    const source = fs.readFileSync(file, 'utf8');
    return /require\(\s*['"]\.\.\/lib\/pages['"]\s*\)/.test(source) && /\bof\s+PORTAL_PAGES\b/.test(source);
  });
  // health-matrix and accessibility walk every page today; an empty
  // derivation would make the next assertion pass vacuously.
  assert.ok(sweeps.length >= 2, `expected the page sweeps under ${SPECS_DIR}, found ${sweeps.length}`);

  const missing = sweeps.filter((file) => {
    const source = fs.readFileSync(file, 'utf8');
    return !(
      /require\(\s*['"]\.\.\/lib\/error-page['"]\s*\)/.test(source) &&
      /locator\(\s*ERROR_PAGE_SELECTOR\s*\)[\s\S]{0,200}?\.toHaveCount\(\s*0\s*\)/.test(source)
    );
  });
  assert.deepEqual(missing.map((file) => path.relative(SPECS_DIR, file)), []);
});

test('no spec or helper finds the error page by its visible text', () => {
  const body = rendererBody();
  const texts = ['title', 'h1']
    .map((tag) => body.match(new RegExp(`<${tag}>([^<]+)</${tag}>`)))
    .filter(Boolean)
    .map((match) => decodePhpText(match[1]));
  assert.ok(texts.length === 2 && texts.every((text) => text.length > 3), 'title and heading of the error page not found');

  const offenders = [];
  for (const file of [...jsFiles(SPECS_DIR), ...jsFiles(LIB_DIR)]) {
    const source = fs.readFileSync(file, 'utf8');
    for (const text of new Set(texts)) {
      if (source.includes(text)) offenders.push(`${path.relative(E2E_DIR, file)}: "${text}"`);
    }
  }
  assert.deepEqual(offenders, []);
});
