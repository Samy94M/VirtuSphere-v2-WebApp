// Seeding and DB-proof helper: runs a PHP snippet inside the PHP container with
// the app bootstrap loaded. The CRUD specs seed and verify through this instead
// of each carrying its own copy (crud-mission/crud-negative predate it).
//
// Transport (C4): a snippet no longer costs its own `docker exec`. Each worker
// process keeps one long-lived `docker exec -i <php> php -r <loop>` per container
// user (lib/exec-channel.js), and the loop runs every snippet in a fresh `php`
// process inside the container (lib/php-channel-loop.js). The snippet, its
// requires, the user, the exit code, stdout and the error on failure are what
// they were. VIRTUSPHERE_E2E_EXEC_CHANNEL=0 goes back to one `docker exec` per
// call, for comparison runs and diagnosis; a failing channel never falls back
// on its own.

const { execFileSync } = require('node:child_process');
const profile = require('./exec-profile');
const channel = require('./exec-channel');
const { loopArgument } = require('./php-channel-loop');

const PHP_CONTAINER = process.env.VIRTUSPHERE_PHP_CONTAINER || 'virtusphere-v2-webapp-php-1';

// Generous: the release browser matrix (Firefox/WebKit/Edge) can slow the
// docker daemon enough that a 15s exec times out (ETIMEDOUT) on Edge.
const DEFAULT_TIMEOUT_MS = 60000;
// Opening a channel is one `docker exec`, under the same load as above.
const CHANNEL_OPEN_TIMEOUT_MS = 60000;

function channelEnabled() {
  return process.env.VIRTUSPHERE_E2E_EXEC_CHANNEL !== '0';
}

function execArgs(user) {
  const args = ['exec', '-i'];
  if (user) {
    args.push('-u', user);
  }
  args.push(PHP_CONTAINER, 'php');
  return args;
}

// The error execFileSync throws, so a caller that reports status, stdout and
// stderr (deploy-log.spec.js) reads the same fields from either transport.
function commandFailed(args, result) {
  const error = new Error(`Command failed: docker ${args.join(' ')} (exec channel)` + (result.stderr ? '\n' + result.stderr : ''));
  error.status = result.exitCode;
  error.signal = null;
  error.stdout = result.stdout;
  error.stderr = result.stderr;
  error.output = [null, result.stdout, result.stderr];
  return error;
}

function viaChannel(php, user, timeoutMs) {
  const args = execArgs(user);
  const target = {
    key: `${PHP_CONTAINER}|${user || ''}`,
    command: 'docker',
    args: [...args, '-r', loopArgument()],
    openTimeoutMs: CHANNEL_OPEN_TIMEOUT_MS,
  };
  const meta = { helper: 'runPhp', container: PHP_CONTAINER, user };
  const start = process.hrtime.bigint();
  let result;
  try {
    result = channel.request(target, php, timeoutMs);
  } catch (error) {
    profile.record({ ...meta, transport: 'channel', ms: Number(process.hrtime.bigint() - start) / 1e6, ok: false });
    throw error;
  }
  const totalMs = Number(process.hrtime.bigint() - start) / 1e6;
  if (result.openMs !== null) {
    profile.record({ ...meta, transport: 'channel-open', ms: result.openMs, ok: true });
  }
  const ok = result.status === 'done' && result.exitCode === 0;
  profile.record({ ...meta, transport: 'channel', ms: totalMs - (result.openMs || 0), ok });
  if (result.stderr) {
    // execFileSync passes a child's stderr through to ours; keep that.
    process.stderr.write(result.stderr);
  }
  return channelOutcome(args, result, timeoutMs);
}

// stdout of a clean run; otherwise the error the caller sees.
function channelOutcome(args, result, timeoutMs) {
  if (result.status === 'timeout') {
    const error = new Error(`exec channel: PHP snippet exceeded ${timeoutMs} ms and was killed (ETIMEDOUT)`);
    error.code = 'ETIMEDOUT';
    error.stdout = result.stdout;
    error.stderr = result.stderr;
    throw error;
  }
  if (result.status !== 'done' || result.exitCode !== 0) {
    throw commandFailed(args, result);
  }
  return result.stdout;
}

/**
 * @param {string} body PHP statements, executed after the bootstrap require.
 * @param {string[]} [requires] additional /var/www/html-relative requires.
 * @param {{ user?: string, timeout?: number }} [options] user runs the exec as
 *   that container user. Needed to touch files the FPM worker owns: the
 *   container drops CAP_DAC_OVERRIDE, so even root cannot read a
 *   www-data-owned 0600 session file - it has to be www-data. timeout is in
 *   milliseconds and defaults to 60 s.
 */
function runPhp(body, requires = [], options = {}) {
  const php =
    '<?php\n' +
    'require_once "/var/www/html/lib/bootstrap.php";\n' +
    requires.map((r) => `require_once "/var/www/html/${r}";\n`).join('') +
    body;
  const timeoutMs = options.timeout || DEFAULT_TIMEOUT_MS;
  if (channelEnabled()) {
    return viaChannel(php, options.user, timeoutMs);
  }
  const meta = { helper: 'runPhp', transport: 'exec', container: PHP_CONTAINER, user: options.user };
  return profile.timed(meta, () => execFileSync('docker', execArgs(options.user), {
    input: php,
    encoding: 'utf8',
    timeout: timeoutMs,
  }));
}

/** Runs a snippet that echoes `JSON<data>JSON` and returns the parsed data. */
function phpJson(body, requires = []) {
  const out = runPhp(body, requires);
  const m = out.match(/JSON([\s\S]*)JSON/);
  if (!m) {
    throw new Error('PHP snippet produced no JSON envelope. Output was:\n' + out);
  }
  return JSON.parse(m[1]);
}

module.exports = { runPhp, phpJson, PHP_CONTAINER, channelOutcome, execArgs };
