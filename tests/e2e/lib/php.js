// Seeding and DB-proof helper: runs a PHP snippet inside the PHP container with
// the app bootstrap loaded. The CRUD specs seed and verify through this instead
// of each carrying its own copy (crud-mission/crud-negative predate it).

const { execFileSync } = require('node:child_process');

const PHP_CONTAINER = process.env.VIRTUSPHERE_PHP_CONTAINER || 'virtusphere-v2-webapp-php-1';

/**
 * @param {string} body PHP statements, executed after the bootstrap require.
 * @param {string[]} [requires] additional /var/www/html-relative requires.
 */
function runPhp(body, requires = []) {
  const php =
    '<?php\n' +
    'require_once "/var/www/html/lib/bootstrap.php";\n' +
    requires.map((r) => `require_once "/var/www/html/${r}";\n`).join('') +
    body;
  return execFileSync('docker', ['exec', '-i', PHP_CONTAINER, 'php'], {
    input: php,
    encoding: 'utf8',
    timeout: 15000,
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
