'use strict';

// C4: the exec profile attributes each seed/proof call to its spec, buckets the
// waits and merges the per-worker parts without dropping a line silently.

const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');
const { callerSpec, bucketLabel, readParts, summarize } = require('../lib/exec-profile');

const SPECS = path.join(path.sep === '\\' ? 'C:\\e2e' : '/e2e', 'specs');

test('the first stack frame under specs/ names the spec, also through lib helpers', () => {
  const lib = path.join(path.dirname(SPECS), 'lib', 'matrix-seed.js');
  const spec = path.join(SPECS, 'visual', 'pages.spec.js');
  const stack = [
    'Error',
    `    at currentSpec (${path.join(path.dirname(SPECS), 'lib', 'exec-profile.js')}:60:17)`,
    `    at seedMatrix (${lib}:40:3)`,
    `    at ${spec}:12:5`,
    `    at Object.<anonymous> (${path.join(SPECS, 'other.spec.js')}:1:1)`,
  ].join('\n');
  assert.equal(callerSpec(stack, SPECS), 'visual/pages.spec.js');
});

test('a call without a spec frame is reported as outside specs', () => {
  const stack = `Error\n    at harness (${path.join(path.dirname(SPECS), 'visual', 'harness.js')}:5:1)`;
  assert.equal(callerSpec(stack, SPECS), '(outside specs)');
});

test('buckets are closed below and open above', () => {
  assert.equal(bucketLabel(0), '0-100ms');
  assert.equal(bucketLabel(99.9), '0-100ms');
  assert.equal(bucketLabel(100), '100-250ms');
  assert.equal(bucketLabel(4999), '2000-5000ms');
  assert.equal(bucketLabel(5000), '>=5000ms');
});

test('parts merge into totals per transport and spec; a broken line is counted', () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'c4-exec-profile-'));
  try {
    const line = (o) => JSON.stringify({ at: 1, pid: 1, project: 'chromium', helper: 'runPhp', ok: true, ...o });
    fs.writeFileSync(path.join(dir, 'a.ndjson'), [
      line({ spec: 'a.spec.js', transport: 'exec', ms: 700 }),
      line({ spec: 'a.spec.js', transport: 'exec', ms: 1300, ok: false }),
      '{"broken',
      '',
    ].join('\n'));
    fs.writeFileSync(path.join(dir, 'b.ndjson'), [
      line({ pid: 2, spec: 'b.spec.js', transport: 'channel-open', ms: 900 }),
      line({ pid: 2, spec: 'b.spec.js', transport: 'channel', ms: 40 }),
    ].join('\n'));
    const profile = summarize(readParts(dir));
    assert.equal(profile.parts, 2);
    assert.equal(profile.unreadableLines, 1);
    assert.equal(profile.workerProcesses, 2);
    assert.equal(profile.total.calls, 4);
    assert.equal(profile.total.failed, 1);
    assert.equal(profile.total.dockerExec, 3, 'a channel request is not a docker exec');
    assert.equal(profile.total.totalMs, 2940);
    assert.equal(profile.total.maxMs, 1300);
    assert.equal(profile.bySpec['a.spec.js'].calls, 2);
    assert.equal(profile.bySpec['a.spec.js'].buckets['500-1000ms'], 1);
    assert.equal(profile.bySpec['a.spec.js'].buckets['1000-2000ms'], 1);
    assert.equal(profile.byTransport.channel.dockerExec, 0);
  } finally {
    fs.rmSync(dir, { recursive: true, force: true });
  }
});

test('a missing parts folder is an empty profile, not a crash', () => {
  const profile = summarize(readParts(path.join(os.tmpdir(), 'c4-exec-profile-does-not-exist')));
  assert.equal(profile.total.calls, 0);
  assert.equal(profile.parts, 0);
});
