'use strict';

// Synchronous client for the long-lived exec channels (C4). The specs call the
// seed and proof helpers synchronously at some 250 places; a channel process
// can only be read asynchronously. This module keeps the calls synchronous: a
// worker thread (exec-channel-thread.js) owns the channel processes, and the
// calling thread posts a request and sleeps in Atomics.wait until the thread
// has answered, the same way execFileSync blocks on its child.
//
// Each Playwright worker process loads its own copy of this module, so each has
// its own thread and its own channel processes: nothing is shared between
// workers or between runs. The thread is unref'd and a channel process ends with
// its stdin when the worker process exits.
//
// A failure is never retried here. The caller gets an error and the channel is
// closed; the next call opens a new one.

const path = require('node:path');
const { Worker, MessageChannel, receiveMessageOnPort } = require('node:worker_threads');

// Last resort when the thread itself does not answer. The thread's own guard
// (snippet deadline plus its grace) ends every normal hang first.
const MAIN_GRACE_MS = 30000;

let state = null;
let nextId = 1;

function start() {
  const counter = new Int32Array(new SharedArrayBuffer(4));
  const { port1, port2 } = new MessageChannel();
  const worker = new Worker(path.join(__dirname, 'exec-channel-thread.js'), {
    workerData: { port: port2, counter },
    transferList: [port2],
  });
  worker.unref();
  port1.unref();
  const s = { worker, port: port1, counter, failure: null };
  worker.on('error', (error) => { s.failure = error; });
  worker.on('exit', (code) => { if (!s.failure) s.failure = new Error(`exec channel thread exited (${code})`); });
  return s;
}

function discard(s) {
  if (state === s) state = null;
  s.worker.terminate().catch(() => {});
}

function failure(message, code, stderr = '') {
  const error = new Error(message);
  error.code = code;
  error.stderr = stderr;
  error.stdout = '';
  return error;
}

/**
 * Runs one request over the channel for `target` and returns
 * { exitCode, status: 'done'|'timeout'|'spawn', stdout, stderr, openMs }.
 * openMs is set when this call had to open the channel first.
 * @param {{ key: string, command: string, args: string[], openTimeoutMs: number, graceMs?: number }} target
 *   graceMs: how long past timeoutMs the thread waits for an answer before it
 *   closes the channel (default 10 s; the channel tests shorten it).
 * @param {string} payload
 * @param {number} timeoutMs deadline of the request inside the channel
 */
function request(target, payload, timeoutMs) {
  if (!state) state = start();
  const s = state;
  const id = nextId++;
  let seen = Atomics.load(s.counter, 0);
  s.port.postMessage({ type: 'request', id, target, payload, timeoutMs });
  const deadline = Date.now() + target.openTimeoutMs + timeoutMs + MAIN_GRACE_MS;
  for (;;) {
    let entry;
    while ((entry = receiveMessageOnPort(s.port))) {
      const msg = entry.message;
      if (msg.id !== id) continue; // the answer to a request that was given up on
      if (msg.ok) return msg.result;
      throw failure(msg.error.message, msg.error.code, msg.error.stderr);
    }
    if (s.failure) {
      discard(s);
      throw failure(`exec channel thread failed: ${s.failure.message}`, 'ECHANNEL');
    }
    const left = deadline - Date.now();
    if (left <= 0) {
      discard(s);
      throw failure(`exec channel ${target.key}: the channel thread did not answer within ${target.openTimeoutMs + timeoutMs + MAIN_GRACE_MS} ms`, 'ETIMEDOUT');
    }
    // Wakes when the thread raises the counter; a short slice keeps a dead
    // thread (which raises nothing) from holding the caller until the deadline.
    Atomics.wait(s.counter, 0, seen, Math.min(left, 1000));
    seen = Atomics.load(s.counter, 0);
  }
}

module.exports = { request };
