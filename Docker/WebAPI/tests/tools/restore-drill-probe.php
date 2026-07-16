<?php

declare(strict_types=1);

/**
 * DB and HTTP half of the restore drill (scripts/restore_test.sh, AP6/E5).
 * Runs inside the project PHP image against the drill's throwaway MySQL; the
 * shell script orchestrates containers, this tool proves the restored data.
 *
 * Subcommands:
 *   verify                  invariants, row counts and the credential
 *                           round-trip under the CURRENT APP_KEY (the one from
 *                           the backed-up .env). Inserts a drill-owned
 *                           credential and leaves it for the wrong-key phase.
 *   expect-decrypt-failure  the drill credential must NOT decrypt under this
 *                           APP_KEY (the drill passes a freshly generated one).
 *   seed-admin <user> <pass>  upserts a drill-owned admin for the login smoke.
 *                           lib/seed.php deliberately refuses to add users to
 *                           a populated database, and the restored users'
 *                           passwords are unknown by design.
 *   cleanup                 removes the drill credential and the drill admin.
 *   smoke <base-url>        health.php 200, portal login round-trip with the
 *                           drill admin (DRILL_ADMIN_USER/DRILL_ADMIN_PASS env)
 *                           and the machine API's deterministic 418 for an
 *                           invalid token.
 *
 * Exit codes: 0 ok; 1 finding (restore not trustworthy); 2 unusable call.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/crypto.php';
require_once dirname(__DIR__, 2) . '/lib/permissions.php';

const DRILL_CREDENTIAL_NAME = 'restore-drill-probe';

function drill_fail(string $message): never
{
    fwrite(STDERR, 'DRILL-FINDING: ' . $message . "\n");
    exit(1);
}

function drill_note(string $message): void
{
    fwrite(STDOUT, 'drill: ' . $message . "\n");
}

function drill_scalar(mysqli $db, string $sql): int
{
    $result = $db->query($sql);
    $row = $result instanceof mysqli_result ? $result->fetch_row() : null;

    return (int) ($row[0] ?? 0);
}

function drill_verify(mysqli $db): void
{
    // Core tables must exist; a partially imported dump dies here, not later.
    foreach (['deploy_users', 'deploy_missions', 'deploy_vms', 'deploy_interfaces', 'deploy_jobs', 'deploy_credentials', 'deploy_migrations'] as $table) {
        $result = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'");
        if (!$result instanceof mysqli_result || $result->num_rows !== 1) {
            drill_fail('table ' . $table . ' is missing after the restore');
        }
    }

    // Row counts as restore evidence: a schema-only restore is not a restore.
    $users = drill_scalar($db, 'SELECT COUNT(*) FROM deploy_users');
    $migrations = drill_scalar($db, 'SELECT COUNT(*) FROM deploy_migrations');
    $missions = drill_scalar($db, 'SELECT COUNT(*) FROM deploy_missions');
    $vms = drill_scalar($db, 'SELECT COUNT(*) FROM deploy_vms');
    drill_note('rows: users=' . $users . ' migrations=' . $migrations . ' missions=' . $missions . ' vms=' . $vms);
    if ($users < 1) {
        drill_fail('deploy_users is empty; no operator could sign in to a system restored from this backup');
    }
    if ($migrations < 1) {
        drill_fail('deploy_migrations is empty although migrate.php ran; migration tracking did not survive');
    }

    // FK/business invariants: orphaned children mean the dump was taken or
    // restored inconsistently even if every table is present.
    $orphans = [
        'interfaces without VM' => 'SELECT COUNT(*) FROM deploy_interfaces i LEFT JOIN deploy_vms v ON v.id = i.vm_id WHERE v.id IS NULL',
        'VMs without mission' => 'SELECT COUNT(*) FROM deploy_vms v LEFT JOIN deploy_missions m ON m.id = v.mission_id WHERE m.id IS NULL',
        'mission jobs without mission' => 'SELECT COUNT(*) FROM deploy_jobs j LEFT JOIN deploy_missions m ON m.id = j.mission_id WHERE j.mission_id IS NOT NULL AND m.id IS NULL',
        'credentials with empty ciphertext' => "SELECT COUNT(*) FROM deploy_credentials WHERE secret_ciphertext = ''",
    ];
    foreach ($orphans as $label => $sql) {
        $count = drill_scalar($db, $sql);
        if ($count > 0) {
            drill_fail($count . ' ' . $label . ' after the restore');
        }
    }

    // Credential round-trip under the restored APP_KEY. The drill row proves
    // the crypto path works on this database; it stays in place so the
    // wrong-key phase can prove the negative afterwards.
    $plaintext = 'drill-' . bin2hex(random_bytes(16));
    $ciphertext = crypto_encrypt_secret($plaintext);
    $stmt = $db->prepare('INSERT INTO deploy_credentials (type, name, host, username, secret_ciphertext) VALUES (?, ?, ?, ?, ?)');
    $type = 'ansible';
    $name = DRILL_CREDENTIAL_NAME;
    $host = 'drill.invalid';
    $username = 'drill';
    $stmt->bind_param('sssss', $type, $name, $host, $username, $ciphertext);
    $stmt->execute();

    $stored = drill_credential_ciphertext($db);
    if (crypto_decrypt_secret($stored) !== $plaintext) {
        drill_fail('credential encrypt/decrypt round-trip failed under the restored APP_KEY');
    }
    drill_note('credential round-trip ok under the restored APP_KEY');

    // The stronger proof, when the backup carries real credentials: the .env
    // from the config archive must decrypt what the DB dump stored, otherwise
    // the two artifacts do not belong to the same system state.
    $stmt = $db->prepare('SELECT secret_ciphertext, name FROM deploy_credentials WHERE name <> ? ORDER BY id LIMIT 1');
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (is_array($row)) {
        try {
            crypto_decrypt_secret((string) $row['secret_ciphertext']);
        } catch (Throwable $exception) {
            drill_fail('stored credential "' . (string) $row['name'] . '" does not decrypt with the APP_KEY from the backed-up .env: backup artifacts are inconsistent');
        }
        drill_note('existing credential decrypts with the backed-up APP_KEY');
    } else {
        drill_note('no pre-existing credentials in the backup; round-trip proof only');
    }
}

function drill_credential_ciphertext(mysqli $db): string
{
    $name = DRILL_CREDENTIAL_NAME;
    $stmt = $db->prepare('SELECT secret_ciphertext FROM deploy_credentials WHERE name = ? LIMIT 1');
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!is_array($row)) {
        drill_fail('drill credential is missing; run the verify phase first');
    }

    return (string) $row['secret_ciphertext'];
}

function drill_expect_decrypt_failure(mysqli $db): void
{
    $stored = drill_credential_ciphertext($db);
    try {
        crypto_decrypt_secret($stored);
    } catch (Throwable $exception) {
        drill_note('decryption fails with a wrong APP_KEY, as it must');

        return;
    }
    drill_fail('credential decrypted despite a wrong APP_KEY; secrets are not actually bound to the key');
}

/**
 * Upserts the drill admin. lib/seed.php refuses to add users to a populated
 * database (correct for production boot, useless for the drill: the restored
 * users' password hashes are unknown by design). The drill owns its throwaway
 * database, so a probe-owned admin with must_change_password already cleared
 * is the honest way to prove the login path.
 */
function drill_seed_admin(mysqli $db, string $user, string $password): void
{
    $stmt = $db->prepare('DELETE FROM deploy_users WHERE name = ?');
    $stmt->bind_param('s', $user);
    $stmt->execute();

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $role = VIRTUSPHERE_ROLE_ADMIN;
    $email = 'restore-drill@localhost';
    $stmt = $db->prepare('INSERT INTO deploy_users (name, password, email, role, is_active, must_change_password) VALUES (?, ?, ?, ?, 1, 0)');
    $stmt->bind_param('ssss', $user, $hash, $email, $role);
    $stmt->execute();
    drill_note('drill admin seeded');
}

function drill_cleanup(mysqli $db, string $adminUser): void
{
    $name = DRILL_CREDENTIAL_NAME;
    $stmt = $db->prepare('DELETE FROM deploy_credentials WHERE name = ?');
    $stmt->bind_param('s', $name);
    $stmt->execute();

    if ($adminUser !== '') {
        $stmt = $db->prepare('DELETE FROM deploy_users WHERE name = ?');
        $stmt->bind_param('s', $adminUser);
        $stmt->execute();
    }
}

/**
 * @return array{status: int, headers: array<int, string>, body: string}
 */
function drill_http(string $method, string $url, array $headers = [], string $body = ''): array
{
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'content' => $body,
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 15,
    ]]);
    // Pre-initialized so a wrapper that never spoke HTTP (DNS/connect failure)
    // leaves an empty list instead of an undefined variable.
    $http_response_header = [];
    $responseBody = @file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header;
    if ($responseBody === false && $responseHeaders === []) {
        drill_fail('HTTP request failed entirely: ' . $method . ' ' . $url);
    }
    $status = 0;
    if (isset($responseHeaders[0]) && preg_match('#^HTTP/\S+\s+(\d+)#', $responseHeaders[0], $m) === 1) {
        $status = (int) $m[1];
    }

    return ['status' => $status, 'headers' => $responseHeaders, 'body' => (string) $responseBody];
}

/**
 * @param array<int, string> $headers
 */
function drill_cookies_from(array $headers, array $cookies): array
{
    foreach ($headers as $header) {
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $header, $m) === 1) {
            $cookies[trim($m[1])] = trim($m[2]);
        }
    }

    return $cookies;
}

function drill_cookie_header(array $cookies): string
{
    $pairs = [];
    foreach ($cookies as $name => $value) {
        $pairs[] = $name . '=' . $value;
    }

    return 'Cookie: ' . implode('; ', $pairs);
}

function drill_smoke(string $base): void
{
    $base = rtrim($base, '/');

    // Health: the restored data answers end to end (PHP -> DB). The drill
    // asserts db=ok, not HTTP 200: health.php also grades log directories and
    // worker heartbeats, and the throwaway smoke server has neither a worker
    // loop nor the stack's log mounts - those are drill-environment artifacts,
    // not restore findings. A broken restore lands in the catch branch with
    // db=error and no health document.
    $health = drill_http('GET', $base . '/portal/health.php');
    if (!in_array($health['status'], [200, 503], true)) {
        drill_fail('health.php answered HTTP ' . $health['status'] . ' instead of the health contract (200/503)');
    }
    $decoded = json_decode($health['body'], true);
    if (!is_array($decoded) || !isset($decoded['status'])) {
        drill_fail('health.php did not return the JSON health document');
    }
    if (($decoded['db'] ?? '') !== 'ok') {
        drill_fail('health.php reports db=' . (string) ($decoded['db'] ?? '<missing>') . '; the restored database does not serve the app');
    }
    drill_note('health.php reachable, db=ok (status=' . (string) $decoded['status'] . ')');

    // Machine API: an invalid token gets the deterministic legacy 418. That
    // one response proves routing, PHP and the token check against the
    // restored database without needing a real token.
    $api = drill_http('GET', $base . '/access.php?token=' . urlencode('restore-drill-invalid-token'));
    if ($api['status'] !== 418) {
        drill_fail('machine API answered HTTP ' . $api['status'] . ' for an invalid token instead of the legacy 418');
    }
    drill_note('machine API rejects an invalid token with the legacy wire shape');

    // Portal login with the drill admin the shell script seeded.
    $user = (string) getenv('DRILL_ADMIN_USER');
    $pass = (string) getenv('DRILL_ADMIN_PASS');
    if ($user === '' || $pass === '') {
        drill_fail('DRILL_ADMIN_USER/DRILL_ADMIN_PASS not set for the login smoke');
    }

    $loginPage = drill_http('GET', $base . '/portal/login.php');
    if ($loginPage['status'] !== 200) {
        drill_fail('login.php answered HTTP ' . $loginPage['status'] . ' instead of 200');
    }
    $cookies = drill_cookies_from($loginPage['headers'], []);
    if (preg_match('/name="_csrf"\s+value="([^"]+)"/', $loginPage['body'], $m) !== 1) {
        drill_fail('login.php did not render a CSRF token');
    }

    $post = drill_http('POST', $base . '/portal/login.php', [
        'Content-Type: application/x-www-form-urlencoded',
        drill_cookie_header($cookies),
    ], http_build_query(['_csrf' => $m[1], 'username' => $user, 'password' => $pass]));
    if ($post['status'] !== 302) {
        drill_fail('login POST answered HTTP ' . $post['status'] . ' instead of a redirect; the drill admin cannot sign in');
    }
    $location = '';
    foreach ($post['headers'] as $header) {
        if (stripos($header, 'Location:') === 0) {
            $location = trim(substr($header, 9));
        }
    }
    if (str_contains($location, 'login.php')) {
        drill_fail('login redirected back to login.php; authentication against the restored DB failed');
    }
    $cookies = drill_cookies_from($post['headers'], $cookies);

    $dashboard = drill_http('GET', $base . '/portal/dashboard.php', [drill_cookie_header($cookies)]);
    if ($dashboard['status'] !== 200 && !($dashboard['status'] === 302 && !str_contains(implode(' ', $dashboard['headers']), 'login.php'))) {
        drill_fail('authenticated portal request answered HTTP ' . $dashboard['status'] . '; the session does not survive');
    }
    drill_note('portal login round-trip ok (redirect to ' . ($location === '' ? '?' : $location) . ')');
}

$command = (string) ($argv[1] ?? '');
try {
    switch ($command) {
        case 'verify':
            drill_verify(db(true));
            break;
        case 'expect-decrypt-failure':
            drill_expect_decrypt_failure(db(true));
            break;
        case 'seed-admin':
            $user = (string) ($argv[2] ?? '');
            $pass = (string) ($argv[3] ?? '');
            if ($user === '' || $pass === '') {
                fwrite(STDERR, "usage: restore-drill-probe.php seed-admin <user> <password>\n");
                exit(2);
            }
            drill_seed_admin(db(true), $user, $pass);
            break;
        case 'cleanup':
            drill_cleanup(db(true), (string) ($argv[2] ?? getenv('DRILL_ADMIN_USER')));
            break;
        case 'smoke':
            $baseUrl = (string) ($argv[2] ?? '');
            if ($baseUrl === '') {
                fwrite(STDERR, "usage: restore-drill-probe.php smoke <base-url>\n");
                exit(2);
            }
            drill_smoke($baseUrl);
            break;
        default:
            fwrite(STDERR, "usage: restore-drill-probe.php verify|expect-decrypt-failure|cleanup|smoke <base-url>\n");
            exit(2);
    }
} catch (mysqli_sql_exception $exception) {
    fwrite(STDERR, 'DRILL-ENV: database not usable: ' . $exception->getMessage() . "\n");
    exit(2);
}

exit(0);
