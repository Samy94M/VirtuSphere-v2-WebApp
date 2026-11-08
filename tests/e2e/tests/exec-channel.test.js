'use strict';

// C4: the synchronous exec channel against a stand-in loop with the same frame
// protocol (fixtures/fake-channel-loop.js). What has to hold: answers arrive
// synchronously and intact, one channel serves many calls, and every failure
// (no start, wrong greeting, death, hang, stray output, overrun) reaches the
// caller as an error and leaves a usable channel for the next call. None of this
// needs Docker; the PHP loop itself is proven by the e2e gates.

const assert = require('node:assert/strict');
const path = require('node:path');
const test = require('node:test');
const channel = require('../lib/exec-channel');
const { LOOP, loopArgument } = require('../lib/php-channel-loop');

const FAKE = path.join(__dirname, 'fixtures', 'fake-channel-loop.js');
let keySeq = 0;

function target(mode = 'normal', extra = {}) {
  keySeq += 1;
  return {
    key: `fake-${mode}-${keySeq}`,
    command: process.execPath,
    args: [FAKE, mode],
    openTimeoutMs: 5000,
    graceMs: 500,
    ...extra,
  };
}

function sleepSync(ms) {
  Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, ms);
}

test('a request is answered synchronously with exit code, stdout and stderr', () => {
  const t = target();
  const first = channel.request(t, 'echo hello', 2000);
  assert.equal(first.exitCode, 0);
  assert.equal(first.status, 'done');
  assert.equal(first.stdout, 'hello');
  assert.equal(first.stderr, '');
  assert.equal(typeof first.openMs, 'number', 'the first call opens the channel');
  const warned = channel.request(t, 'warn a notice', 2000);
  assert.deepEqual([warned.stdout, warned.stderr, warned.openMs], ['out', 'a notice', null]);
});

test('one channel process serves consecutive calls; keys keep channels apart', () => {
  const a = target();
  const b = target();
  const pidA1 = channel.request(a, 'pid', 2000).stdout;
  const pidA2 = channel.request(a, 'pid', 2000).stdout;
  const pidB = channel.request(b, 'pid', 2000).stdout;
  assert.equal(pidA1, pidA2);
  assert.notEqual(pidA1, pidB);
});

test('multi-byte payloads and answers keep their byte framing', () => {
  const t = target();
  const big = channel.request(t, 'big 100000', 5000);
  assert.equal(big.stdout.length, 100000);
  assert.ok(/^é+$/.test(big.stdout));
  assert.equal(channel.request(t, 'echo Übermäßig 🚀', 2000).stdout, 'Übermäßig 🚀');
});

test('a non-zero exit is returned as such, not thrown, and the channel stays', () => {
  const t = target();
  const pid = channel.request(t, 'pid', 2000).stdout;
  const failed = channel.request(t, 'fail 255 PHP Fatal error', 2000);
  assert.deepEqual([failed.exitCode, failed.stdout, failed.stderr], [255, 'partial', 'PHP Fatal error']);
  assert.equal(channel.request(t, 'pid', 2000).stdout, pid);
});

test('an overrun answered by the loop is reported as timeout', () => {
  const t = target();
  const slow = channel.request(t, 'sleep 2000', 200);
  assert.equal(slow.status, 'timeout');
  assert.equal(channel.request(t, 'echo after', 2000).stdout, 'after');
});

test('a channel that cannot start is an error with the daemon message', () => {
  assert.throws(() => channel.request(target('no-start'), 'echo x', 2000), (error) => {
    assert.equal(error.code, 'ECHANNEL');
    assert.match(error.message, /No such container/);
    return true;
  });
});

test('a wrong greeting and a silent start are errors, not hangs', () => {
  assert.throws(() => channel.request(target('bad-ready'), 'echo x', 2000), /expected READY/);
  assert.throws(
    () => channel.request(target('silent', { openTimeoutMs: 300 }), 'echo x', 2000),
    (error) => error.code === 'ETIMEDOUT' && /no READY within 300 ms/.test(error.message),
  );
});

test('a channel that dies mid-request fails that call; the next call reopens', () => {
  const t = target();
  const before = channel.request(t, 'pid', 2000).stdout;
  assert.throws(() => channel.request(t, 'crash', 2000), (error) => {
    assert.equal(error.code, 'ECHANNEL');
    assert.match(error.message, /exit 5/);
    assert.match(error.message, /boom/);
    return true;
  });
  const after = channel.request(t, 'pid', 2000);
  assert.notEqual(after.stdout, before);
  assert.equal(typeof after.openMs, 'number');
});

test('a hang past the grace closes the channel with ETIMEDOUT', () => {
  const t = target();
  const started = Date.now();
  assert.throws(() => channel.request(t, 'hang', 300), (error) => error.code === 'ETIMEDOUT' && /no answer within 800 ms/.test(error.message));
  assert.ok(Date.now() - started < 5000, 'the guard ends the wait, not the main deadline');
  assert.equal(channel.request(t, 'echo again', 2000).stdout, 'again');
});

test('output outside a frame is a protocol error, never a later answer', () => {
  const t = target();
  assert.throws(() => channel.request(t, 'garbage', 2000), /expected RES/);
  const first = channel.request(t, 'extra', 2000);
  assert.equal(first.stdout, 'first');
  sleepSync(300);
  // The stray line closed that channel; this call opens a fresh one.
  const next = channel.request(t, 'echo clean', 2000);
  assert.equal(next.stdout, 'clean');
  assert.equal(typeof next.openMs, 'number');
});

test('the PHP loop travels as one base64 argument without quotes or spaces', () => {
  const arg = loopArgument();
  assert.match(arg, /^eval\(base64_decode\('[A-Za-z0-9+/=]+'\)\);$/);
  const decoded = Buffer.from(arg.match(/'([^']+)'/)[1], 'base64').toString('utf8');
  assert.equal(decoded, LOOP);
  assert.ok(!/^\s*<\?php/.test(LOOP), 'php -r takes code without the opening tag');
  for (const needle of ["proc_open(['php']", "ini_set('display_errors', 'stderr')", 'READY 1 ', 'RES %d %d %d %s', 'proc_terminate']) {
    assert.ok(LOOP.includes(needle), `loop keeps ${needle}`);
  }
});

test('runPhp maps a channel answer onto what execFileSync returned or threw', () => {
  const { channelOutcome, execArgs } = require('../lib/php');
  const args = execArgs('www-data');
  assert.deepEqual(args.slice(0, 4), ['exec', '-i', '-u', 'www-data']);
  assert.equal(args[args.length - 1], 'php');
  assert.equal(channelOutcome(args, { status: 'done', exitCode: 0, stdout: 'JSON{}JSON', stderr: 'notice' }, 60000), 'JSON{}JSON');
  assert.throws(() => channelOutcome(args, { status: 'done', exitCode: 255, stdout: 'part', stderr: 'PHP Fatal error' }, 60000), (error) => {
    assert.equal(error.status, 255);
    assert.equal(error.stdout, 'part');
    assert.equal(error.stderr, 'PHP Fatal error');
    assert.match(error.message, /^Command failed: docker exec -i -u www-data \S+ php \(exec channel\)\nPHP Fatal error$/);
    return true;
  });
  assert.throws(() => channelOutcome(args, { status: 'spawn', exitCode: 127, stdout: '', stderr: 'proc_open php failed' }, 60000), (error) => error.status === 127);
  assert.throws(() => channelOutcome(args, { status: 'timeout', exitCode: -1, stdout: 'started', stderr: '' }, 15000), (error) => {
    assert.equal(error.code, 'ETIMEDOUT');
    assert.equal(error.stdout, 'started');
    assert.match(error.message, /exceeded 15000 ms/);
    return true;
  });
});
