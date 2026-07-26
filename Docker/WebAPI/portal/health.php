<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/envboot.php';
require_once __DIR__ . '/../lib/headers.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/deploy_constants.php';

virtusphere_send_security_headers();
header('Content-Type: application/json; charset=utf-8');

// Grobe Laufzeitversion (major.minor), keine Patchstufe: der Endpoint ist
// unauthentifiziert, und eine exakte Version macht CVE-Zuordnung von aussen
// trivial. Der einzige maschinelle Konsument (install-VirtuSphere-MECM.ps1)
// liest nur `.status`. Gepinnt durch VersionExposureContractTest.
const HEALTH_PHP_VERSION = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

function health_statement_row(mysqli $db, string $sql, string $types = '', array $params = []): array
{
    $stmt = $db->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    return is_array($row) ? $row : [];
}

/** Both log destinations the app writes to must be writable. */
function health_logs_ok(): bool
{
    $phpErrorLog = (string) ini_get('error_log');
    $phpLogDir = $phpErrorLog !== '' ? dirname($phpErrorLog) : '';
    $appLogDir = dirname(__DIR__) . '/logs';

    return is_dir($appLogDir) && is_writable($appLogDir)
        && $phpLogDir !== '' && is_dir($phpLogDir) && is_writable($phpLogDir);
}

/**
 * Whether a running deploy job has stopped reporting. "Stale" means the same
 * thing here as it does to the reaper, and it reads the reaper's own constant to
 * say so: the hardcoded 2 MINUTE called jobs stale that the reaper still
 * considered alive, so this endpoint reported `degraded` for a healthy deploy
 * whose playbook simply had not printed for three minutes.
 */
function health_workers_ok(mysqli $db): bool
{
    $status = VIRTUSPHERE_DEPLOY_STATUS_RUNNING;
    $stale = VIRTUSPHERE_DEPLOY_STALE_AFTER_SECONDS;
    $row = health_statement_row(
        $db,
        'SELECT SUM(CASE WHEN heartbeat_at IS NULL OR heartbeat_at < DATE_SUB(NOW(), INTERVAL ? SECOND) THEN 1 ELSE 0 END) AS stale_running_jobs FROM deploy_jobs WHERE status = ?',
        'is',
        [$stale, $status]
    );

    return (int) ($row['stale_running_jobs'] ?? 0) === 0;
}

try {
    $db = db();
    health_statement_row($db, 'SELECT 1 AS ok');

    // 200, including for `degraded`. This endpoint is an ADDRESS PROBE before it
    // is a health report: the MECM installer, every client script's
    // Resolve-VsApi and the Ansible host's preflight all ask it "are you there",
    // and PowerShell 5.1's Invoke-RestMethod THROWS on a 5xx while discarding the
    // body. A degraded portal therefore looked unreachable to the whole machine
    // chain, so one stale deploy job - or a worker restart - could stop every
    // client script on every VM at once, and the same 503 made the integration
    // suite skip itself silently. Only the catch branch below answers 503, where
    // it means what a 503 means: this service cannot serve requests.
    //
    // The nuance is not lost, it is in the body, which is where a health report
    // belongs. The body carries nothing an unauthenticated caller in the deploy
    // VLAN has no business knowing: job counts, heartbeat timestamps and the
    // backup age used to be here and are read from the portal instead.
    echo json_encode([
        'status' => (health_logs_ok() && health_workers_ok($db)) ? 'ok' : 'degraded',
        'db' => 'ok',
        'php' => HEALTH_PHP_VERSION,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    error_log('[health] ' . $exception::class . ': ' . $exception->getMessage());
    http_response_code(503);
    echo json_encode([
        'status' => 'error',
        'db' => 'error',
        'php' => HEALTH_PHP_VERSION,
        'message' => 'Service temporarily unavailable',
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}
