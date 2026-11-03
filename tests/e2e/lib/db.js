// DB assertions for E2E, run through `docker exec` into the MySQL container.
// The container's port is deliberately unpublished (the DB must not be reachable
// on the LAN), so a shelled-out mysql client is the honest path and needs no
// compose change (ADR-0028).

const { execFileSync } = require('node:child_process');

const CONTAINER = process.env.VIRTUSPHERE_MYSQL_CONTAINER || 'virtusphere-v2-webapp-mysql-1';
const DB_NAME = process.env.DB_NAME || 'deploymentcenter';

/**
 * Runs one SQL statement and returns rows as an array of objects. Uses the
 * container's own env for credentials (MYSQL_USER/MYSQL_PASSWORD), so no secret
 * is passed from the host or written to a file.
 */
function query(sql) {
  const script =
    'MYSQL_PWD="$MYSQL_PASSWORD" mysql --batch --raw -u"$MYSQL_USER" "' + DB_NAME + '" -e ' +
    shellQuote(sql);

  const out = execFileSync(
    'docker',
    ['exec', CONTAINER, 'sh', '-c', script],
    // Generous: under the release browser matrix (Firefox/WebKit/Edge) the docker
    // daemon can slow enough that a 15s exec times out (spawnSync ETIMEDOUT) on
    // Edge. 60s keeps the seed/proof robust without masking a real hang.
    { encoding: 'utf8', timeout: 60000 }
  );

  return parseTsv(out);
}

/** Single scalar from `SELECT expr ...`, or null when there is no row. */
function scalar(sql) {
  const rows = query(sql);
  if (rows.length === 0) {
    return null;
  }
  const first = rows[0];
  const keys = Object.keys(first);
  return keys.length > 0 ? first[keys[0]] : null;
}

function count(sql) {
  const value = scalar(sql);
  return value === null ? 0 : Number(value);
}

// mysql --batch emits tab-separated rows with a header line. --raw keeps values
// literal (no backslash escaping), which is what byte-exact round-trip checks
// need; a value containing a real tab or newline is out of scope for these
// assertions and none of the seeded fixtures use one.
function parseTsv(out) {
  const lines = out.replace(/\r\n/g, '\n').split('\n').filter((l) => l.length > 0);
  if (lines.length === 0) {
    return [];
  }
  const headers = lines[0].split('\t');
  return lines.slice(1).map((line) => {
    const cells = line.split('\t');
    const row = {};
    headers.forEach((h, i) => {
      row[h] = cells[i] === 'NULL' ? null : cells[i];
    });
    return row;
  });
}

// The SQL is passed as a single argv element to `mysql -e`, so it is not
// re-parsed by a shell for the mysql invocation itself; this only quotes it for
// the `sh -c` wrapper. Tests build SQL from literals and integer ids, never from
// page input, so this is a wrapper-safety measure, not an injection boundary.
function shellQuote(s) {
  return "'" + String(s).replace(/'/g, "'\\''") + "'";
}

module.exports = { query, scalar, count };
