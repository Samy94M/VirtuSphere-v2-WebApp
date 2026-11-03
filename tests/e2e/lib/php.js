// Seeding and DB-proof helper: runs a PHP snippet inside the PHP container with
// the app bootstrap loaded. The CRUD specs seed and verify through this instead
// of each carrying its own copy (crud-mission/crud-negative predate it).

const { execFileSync } = require('node:child_process');

const PHP_CONTAINER = process.env.VIRTUSPHERE_PHP_CONTAINER || 'virtusphere-v2-webapp-php-1';

/**
 * @param {string} body PHP statements, executed after the bootstrap require.
 * @param {string[]} [requires] additional /var/www/html-relative requires.
 * @param {{ user?: string }} [options] user runs the exec as that container
 *   user. Needed to touch files the FPM worker owns: the container drops
 *   CAP_DAC_OVERRIDE, so even root cannot read a www-data-owned 0600 session
 *   file - it has to be www-data.
 */
function runPhp(body, requires = [], options = {}) {
  const php =
    '<?php\n' +
    'require_once "/var/www/html/lib/bootstrap.php";\n' +
    requires.map((r) => `require_once "/var/www/html/${r}";\n`).join('') +
    body;
  const args = ['exec', '-i'];
  if (options.user) {
    args.push('-u', options.user);
  }
  args.push(PHP_CONTAINER, 'php');
  return execFileSync('docker', args, {
    input: php,
    encoding: 'utf8',
    // Generous: the release browser matrix (Firefox/WebKit/Edge) can slow the
    // docker daemon enough that a 15s exec times out (ETIMEDOUT) on Edge.
    timeout: 60000,
  });
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

module.exports = { runPhp, phpJson, PHP_CONTAINER };
