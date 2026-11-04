'use strict';

// NZ1: portal/assets/vm-disk-label.js mirrors the Windows volume label rule of
// lib/vm_disk_layout.php for live typing. Both sides run the same vectors
// (Docker/WebAPI/tests/fixtures/disk-label-vectors.json; VmDiskLayoutTest is the
// PHP half), so a change on one side without the other fails here or there.

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const WEBAPI = path.join(__dirname, '..', '..', '..', 'Docker', 'WebAPI');
const mirror = require(path.join(WEBAPI, 'portal', 'assets', 'vm-disk-label.js'));
const vectors = JSON.parse(fs.readFileSync(path.join(WEBAPI, 'tests', 'fixtures', 'disk-label-vectors.json'), 'utf8'));

test('the vector file is not empty', () => {
  assert.ok(vectors.labels.length > 20);
  assert.ok(vectors.notices.length > 8);
  assert.ok(vectors.max > 0);
});

test('label rule matches the shared vectors', () => {
  for (const vector of vectors.labels) {
    assert.equal(mirror.volumeLabel(vector.name, vectors.max), vector.label, vector.why);
  }
});

test('editor notice matches the shared vectors', () => {
  const { first, prefix } = vectors.defaults;
  for (const vector of vectors.notices) {
    assert.deepEqual(
      mirror.labelNotice(vector.name, vector.position, vectors.max, first, prefix),
      { kind: vector.kind, label: vector.label },
      vector.why,
    );
  }
});

test('a lone surrogate counts as the U+FFFD the form submits', () => {
  // Not a shared vector: PHP json_decode refuses an unpaired surrogate escape,
  // and the server only ever receives the replacement character.
  assert.equal(mirror.volumeLabel('A\uD800B', vectors.max), 'A�B');
});
