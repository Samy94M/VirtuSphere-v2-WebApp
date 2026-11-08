'use strict';

// Counts what the seed and proof helpers cost (C4, finding 7 of the QA runtime
// analysis 2026-11-04). Every call through lib/php.js or lib/db.js records one
// line: which spec it came from, how it reached the container (one `docker exec`
// per call, a request over the long-lived channel, or the `docker exec` that
// opened the channel), how long the caller waited and whether it succeeded.
//
// Each Playwright worker process appends to its own NDJSON file below
// test-results/, one line per call, so a worker that Playwright replaces after a
// failed test loses nothing. The reporter in exec-profile-reporter.js merges the
// parts into test-results/exec-profile.json when the run ends. Recording never
// changes a call's result: a write that fails is reported once on stderr and the
// call goes on.

const fs = require('node:fs');
const path = require('node:path');

const E2E_DIR = path.resolve(__dirname, '..');
const SPECS_DIR = path.join(E2E_DIR, 'specs');
const RESULTS_DIR = path.join(E2E_DIR, 'test-results');
const PARTS_DIR = path.join(RESULTS_DIR, 'exec-profile.parts');
const PROFILE_FILE = path.join(RESULTS_DIR, 'exec-profile.json');

// Upper bounds in milliseconds; the last bucket is open.
const BUCKETS_MS = [100, 250, 500, 1000, 2000, 5000];

let partFile = null;
let writeFailed = false;

function bucketLabel(ms) {
  for (let i = 0; i < BUCKETS_MS.length; i++) {
    if (ms < BUCKETS_MS[i]) {
      const low = i === 0 ? 0 : BUCKETS_MS[i - 1];
      return `${low}-${BUCKETS_MS[i]}ms`;
    }
  }
  return `>=${BUCKETS_MS[BUCKETS_MS.length - 1]}ms`;
}

function emptyBuckets() {
  const out = {};
  for (const upper of BUCKETS_MS) out[bucketLabel(upper - 1)] = 0;
  out[bucketLabel(Number.MAX_SAFE_INTEGER)] = 0;
  return out;
}

// The spec a call belongs to, read off the call stack: the helpers are called
// from specs directly, from lib/ helpers the specs call, and from hooks, and the
// stack covers all three without every caller passing its name.
function callerSpec(stack, specsDir = SPECS_DIR) {
  const prefix = specsDir + path.sep;
  for (const line of String(stack).split('\n')) {
    const m = line.match(/\(?((?:[A-Za-z]:)?[^():]+\.js):\d+:\d+\)?\s*$/);
    if (!m) continue;
    const file = path.resolve(m[1]);
    if (file.startsWith(prefix)) {
      return path.relative(specsDir, file).split(path.sep).join('/');
    }
  }
  return '(outside specs)';
}

function currentSpec() {
  const limit = Error.stackTraceLimit;
  Error.stackTraceLimit = 64;
  const stack = new Error().stack;
  Error.stackTraceLimit = limit;
  return callerSpec(stack);
}

// The Playwright project of this worker. lib/test.js sets it when the worker
// starts; for a spec that takes `test` from @playwright/test directly
// (auth.setup.js) the running test's info supplies it. It stays null at module
// scope in the main process, which loads every spec file to list the tests.
// There is no per-test attribution on purpose: inside beforeAll and afterAll
// Playwright reports a neighbouring test's info, which would charge hook calls
// to the wrong test.
let workerProject = null;

function setProject(name) {
  workerProject = name || null;
}

function currentProject() {
  if (workerProject) return workerProject;
  try {
    workerProject = require('@playwright/test').test.info().project.name;
  } catch {
    // Not inside a test: the project stays unknown.
  }
  return workerProject;
}

function ensurePartFile() {
  if (partFile) return partFile;
  fs.mkdirSync(PARTS_DIR, { recursive: true });
  const worker = process.env.TEST_WORKER_INDEX || 'main';
  partFile = path.join(PARTS_DIR, `${process.pid}-${worker}-${Date.now()}.ndjson`);
  return partFile;
}

/**
 * Records one call.
 * @param {{ helper: string, transport: 'exec'|'channel'|'channel-open', ms: number, ok: boolean, container?: string, user?: string }} entry
 */
function record(entry) {
  const line = {
    at: Date.now(),
    pid: process.pid,
    project: currentProject(),
    spec: currentSpec(),
    helper: entry.helper,
    transport: entry.transport,
    container: entry.container || null,
    user: entry.user || null,
    ms: Math.round(entry.ms * 10) / 10,
    ok: entry.ok === true,
  };
  try {
    fs.appendFileSync(ensurePartFile(), JSON.stringify(line) + '\n');
  } catch (error) {
    if (!writeFailed) {
      writeFailed = true;
      process.stderr.write(`exec-profile: cannot record (${error.message}); the profile of this run is incomplete\n`);
    }
  }
}

/** Times `fn` and records it; the result or the error passes through unchanged. */
function timed(meta, fn) {
  const start = process.hrtime.bigint();
  let ok = false;
  try {
    const result = fn();
    ok = true;
    return result;
  } finally {
    record({ ...meta, ms: Number(process.hrtime.bigint() - start) / 1e6, ok });
  }
}

function percentile(sorted, p) {
  if (sorted.length === 0) return 0;
  const index = Math.min(sorted.length - 1, Math.ceil((p / 100) * sorted.length) - 1);
  return sorted[Math.max(0, index)];
}

function stats(entries) {
  const ms = entries.map((e) => e.ms).sort((a, b) => a - b);
  const total = ms.reduce((sum, v) => sum + v, 0);
  const buckets = emptyBuckets();
  for (const v of ms) buckets[bucketLabel(v)] += 1;
  return {
    calls: entries.length,
    failed: entries.filter((e) => !e.ok).length,
    dockerExec: entries.filter((e) => e.transport !== 'channel').length,
    totalMs: Math.round(total),
    meanMs: entries.length ? Math.round((total / entries.length) * 10) / 10 : 0,
    p50Ms: percentile(ms, 50),
    p90Ms: percentile(ms, 90),
    p99Ms: percentile(ms, 99),
    maxMs: ms.length ? ms[ms.length - 1] : 0,
    buckets,
  };
}

function groupBy(entries, key) {
  const groups = {};
  for (const e of entries) {
    const k = String(key(e));
    (groups[k] = groups[k] || []).push(e);
  }
  const out = {};
  for (const k of Object.keys(groups).sort()) out[k] = stats(groups[k]);
  return out;
}

/** Reads every part file; a line that does not parse is counted, not dropped silently. */
function readParts(dir = PARTS_DIR) {
  const entries = [];
  let unreadable = 0;
  let files = [];
  try {
    files = fs.readdirSync(dir).filter((f) => f.endsWith('.ndjson')).sort();
  } catch {
    return { entries, unreadable, parts: 0 };
  }
  for (const file of files) {
    for (const line of fs.readFileSync(path.join(dir, file), 'utf8').split('\n')) {
      if (line.trim() === '') continue;
      try {
        entries.push(JSON.parse(line));
      } catch {
        unreadable += 1;
      }
    }
  }
  return { entries, unreadable, parts: files.length };
}

/** The merged profile: totals, per transport, helper, project and spec. */
function summarize({ entries, unreadable, parts }, meta = {}) {
  const sorted = entries.slice().sort((a, b) => a.at - b.at);
  return {
    schema: 1,
    generatedAt: new Date().toISOString(),
    ...meta,
    workerProcesses: new Set(sorted.map((e) => e.pid)).size,
    parts,
    unreadableLines: unreadable,
    channel: process.env.VIRTUSPHERE_E2E_EXEC_CHANNEL === '0' ? 'off' : 'on',
    bucketsMs: BUCKETS_MS,
    total: stats(sorted),
    byTransport: groupBy(sorted, (e) => e.transport),
    byHelper: groupBy(sorted, (e) => e.helper),
    byProject: groupBy(sorted, (e) => e.project || '(module scope)'),
    bySpec: groupBy(sorted, (e) => e.spec),
  };
}

module.exports = {
  record,
  setProject,
  timed,
  readParts,
  summarize,
  callerSpec,
  bucketLabel,
  PARTS_DIR,
  PROFILE_FILE,
  RESULTS_DIR,
};
