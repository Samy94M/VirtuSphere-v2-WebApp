'use strict';

// M1: every spec must take `test` from lib/test.js, which renews the role
// logins per browser project. A spec that imports @playwright/test directly
// runs on the setup login alone and meets the session-expiry dialog once a
// matrix run passes the 3600 s session lifetime. The list is derived from the
// specs directory, so a new spec is covered without editing this file.

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const SPECS_DIR = path.join(__dirname, '..', 'specs');
// The setup project creates the logins that lib/test.js later renews.
const DIRECT_IMPORT_ALLOWED = new Set(['auth.setup.js']);

function specFiles(dir) {
  return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) return specFiles(full);
    return entry.name.endsWith('.js') ? [full] : [];
  });
}

test('specs take test from lib/test.js, not from @playwright/test', () => {
  const files = specFiles(SPECS_DIR);
  assert.ok(files.length > 10, `expected the spec files under ${SPECS_DIR}`);
  const offenders = files.filter((file) => {
    if (DIRECT_IMPORT_ALLOWED.has(path.basename(file))) return false;
    return /require\(\s*['"]@playwright\/test['"]\s*\)|from\s+['"]@playwright\/test['"]/.test(fs.readFileSync(file, 'utf8'));
  });
  assert.deepEqual(offenders.map((file) => path.relative(SPECS_DIR, file)), []);
});

test('every spec that defines tests loads lib/test.js', () => {
  const missing = specFiles(SPECS_DIR).filter((file) => {
    if (DIRECT_IMPORT_ALLOWED.has(path.basename(file))) return false;
    const source = fs.readFileSync(file, 'utf8');
    return /\btest\(/.test(source) && !/require\(\s*['"](\.\.\/)+lib\/test['"]\s*\)/.test(source);
  });
  assert.deepEqual(missing.map((file) => path.relative(SPECS_DIR, file)), []);
});
