'use strict';

// Worker thread behind lib/exec-channel.js. It owns the long-lived channel
// processes (one per target key, e.g. one `docker exec -i <php> php -r <loop>`
// per container user) and speaks the frame protocol with the loop inside the
// container:
//
//   loop    -> thread   READY 1 <pid>\n                       once, after start
//   thread  -> loop     REQ <bytes> <timeoutMs>\n<payload>
//   loop    -> thread   RES <exit> <outBytes> <errBytes> <done|timeout|spawn>\n<stdout><stderr>
//
// The main thread is blocked in Atomics.wait while a request runs. A reply is
// posted on the port first and the shared counter is raised afterwards, so a
// woken main thread always finds its message. Every failure (the process does
// not start, dies, answers out of protocol or overruns its time) becomes an
// error reply and closes the channel; the next request opens a fresh one.

const { workerData } = require('node:worker_threads');
const { spawn } = require('node:child_process');

const { port, counter } = workerData;
const channels = new Map();
const STDERR_KEEP = 16384;
// Over the loop's own deadline, which ends a slow snippet and answers "timeout"
// itself; this one only fires when the loop or the transport hangs.
const TRANSPORT_GRACE_MS = 10000;

function reply(message) {
  port.postMessage(message);
  Atomics.add(counter, 0, 1);
  Atomics.notify(counter, 0);
}

function channelError(message, code, ch) {
  const error = new Error(message);
  error.code = code;
  error.stderr = ch ? ch.stderr : '';
  return error;
}

function close(ch, error) {
  if (ch.dead) return;
  ch.dead = true;
  if (channels.get(ch.key) === ch) channels.delete(ch.key);
  if (ch.waiter) {
    const waiter = ch.waiter;
    ch.waiter = null;
    clearTimeout(waiter.timer);
    waiter.reject(error);
  }
  try {
    ch.child.stdin.destroy();
  } catch {
    // already gone
  }
  if (ch.child.exitCode === null && ch.child.signalCode === null) {
    ch.child.kill();
  }
}

function wait(ch, kind, timeoutMs, onTimeout) {
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => close(ch, onTimeout()), timeoutMs);
    ch.waiter = { kind, resolve, reject, timer };
    pump(ch);
  });
}

function settle(ch, value) {
  const waiter = ch.waiter;
  ch.waiter = null;
  clearTimeout(waiter.timer);
  waiter.resolve(value);
}

// Consumes what the loop wrote as far as the current waiter needs it. Bytes
// that arrive while nobody waits are a protocol error, never a later answer.
function pump(ch) {
  if (ch.dead) return;
  if (!ch.waiter) {
    if (ch.buf.length > 0) {
      close(ch, channelError(`exec channel ${ch.key}: unexpected output between requests: ${JSON.stringify(ch.buf.toString('utf8', 0, 200))}`, 'ECHANNEL', ch));
    }
    return;
  }
  const nl = ch.buf.indexOf(0x0a);
  if (nl < 0) return;
  const header = ch.buf.toString('utf8', 0, nl);
  if (ch.waiter.kind === 'ready') {
    if (!/^READY 1 \d+$/.test(header)) {
      close(ch, channelError(`exec channel ${ch.key}: expected READY, got ${JSON.stringify(header.slice(0, 200))}`, 'ECHANNEL', ch));
      return;
    }
    ch.buf = ch.buf.subarray(nl + 1);
    settle(ch, null);
    pump(ch);
    return;
  }
  const m = header.match(/^RES (-?\d+) (\d+) (\d+) (done|timeout|spawn)$/);
  if (!m) {
    close(ch, channelError(`exec channel ${ch.key}: expected RES, got ${JSON.stringify(header.slice(0, 200))}`, 'ECHANNEL', ch));
    return;
  }
  const outBytes = Number(m[2]);
  const errBytes = Number(m[3]);
  const start = nl + 1;
  if (ch.buf.length < start + outBytes + errBytes) return;
  const stdout = ch.buf.toString('utf8', start, start + outBytes);
  const stderr = ch.buf.toString('utf8', start + outBytes, start + outBytes + errBytes);
  ch.buf = ch.buf.subarray(start + outBytes + errBytes);
  settle(ch, { exitCode: Number(m[1]), status: m[4], stdout, stderr });
  pump(ch);
}

async function open(target) {
  const child = spawn(target.command, target.args, { stdio: ['pipe', 'pipe', 'pipe'], windowsHide: true });
  const ch = { key: target.key, child, buf: Buffer.alloc(0), stderr: '', waiter: null, dead: false };
  channels.set(target.key, ch);
  child.stdout.on('data', (data) => {
    ch.buf = ch.buf.length === 0 ? data : Buffer.concat([ch.buf, data]);
    pump(ch);
  });
  child.stderr.on('data', (data) => {
    ch.stderr = (ch.stderr + data.toString('utf8')).slice(-STDERR_KEEP);
  });
  child.stdin.on('error', (error) => {
    close(ch, channelError(`exec channel ${ch.key}: cannot write to the channel process: ${error.message}`, 'ECHANNEL', ch));
  });
  child.on('error', (error) => {
    close(ch, channelError(`exec channel ${ch.key}: cannot start ${target.command}: ${error.message}`, 'ECHANNEL', ch));
  });
  // 'close' comes after stdout and stderr have ended, so a last answer and the
  // last error text are already read when the end is reported.
  child.on('close', (code, signal) => {
    close(ch, channelError(
      `exec channel ${ch.key}: channel process ended (exit ${code}, signal ${signal})${ch.stderr ? ': ' + ch.stderr.trim() : ''}`,
      'ECHANNEL',
      ch,
    ));
  });
  await wait(ch, 'ready', target.openTimeoutMs, () => channelError(
    `exec channel ${ch.key}: no READY within ${target.openTimeoutMs} ms`,
    'ETIMEDOUT',
    ch,
  ));
  return ch;
}

async function handle(msg) {
  let ch = channels.get(msg.target.key);
  if (ch && ch.waiter) {
    // The main thread gave up on an earlier request; its answer must not be
    // read as this one's.
    close(ch, channelError(`exec channel ${ch.key}: abandoned request`, 'ECHANNEL', ch));
    ch = null;
  }
  let openMs = null;
  if (!ch || ch.dead) {
    const start = process.hrtime.bigint();
    ch = await open(msg.target);
    openMs = Number(process.hrtime.bigint() - start) / 1e6;
  }
  const payload = Buffer.from(msg.payload, 'utf8');
  const guardMs = msg.timeoutMs + (msg.target.graceMs ?? TRANSPORT_GRACE_MS);
  const response = wait(ch, 'response', guardMs, () => channelError(
    `exec channel ${ch.key}: no answer within ${guardMs} ms`,
    'ETIMEDOUT',
    ch,
  ));
  ch.child.stdin.write(Buffer.concat([Buffer.from(`REQ ${payload.length} ${msg.timeoutMs}\n`, 'ascii'), payload]));
  const result = await response;
  return { ...result, openMs };
}

port.on('message', (msg) => {
  if (msg.type === 'close') {
    for (const ch of channels.values()) close(ch, channelError('exec channel closed', 'ECHANNEL', ch));
    return;
  }
  handle(msg).then(
    (result) => reply({ id: msg.id, ok: true, result }),
    (error) => reply({ id: msg.id, ok: false, error: { message: error.message, code: error.code || 'ECHANNEL', stderr: error.stderr || '' } }),
  );
});
