<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/envboot.php';
require_once __DIR__ . '/../lib/headers.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/deploy_constants.php';
require_once __DIR__ . '/../lib/deploy_service_health.php';
require_once __DIR__ . '/../lib/log_redaction.php';

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
 * Whether the deploy service is healthy, read from the ONE snapshot every
 * other surface reads (Etappe 13R).
 *
 * It used to derive its own answer from a stale-heartbeat query. That made this
 * endpoint a fifth opinion, and it was the opinion nobody could see: a deploy
 * service the System status page called paused on purpose was reported here as
 * degraded, because a query about heartbeats cannot tell a deliberate pause
 * from a fault. The axis knows the difference, so the axis decides.
 *
 * A snapshot that cannot be computed is `degraded`, never a 503. The database
 * already answered, so this service CAN serve requests; turning an internal
 * derivation fault into an address-probe failure would stop every client script
 * in the deploy VLAN over a portal detail none of them read.
 */
function health_workers_ok(mysqli $db): bool
{
    try {
        $availability = deploy_service_health_snapshot($db)['availability'];
    } catch (Throwable $exception) {
        error_log('[health] deploy snapshot unavailable: ' . $exception::class);

        return false;
    }

    return !in_array(
        $availability,
        [VIRTUSPHERE_DEPLOY_AVAILABILITY_DEGRADED, VIRTUSPHERE_DEPLOY_AVAILABILITY_OFFLINE],
        true
    );
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
    error_log('[health] ' . $exception::class . ': ' . virtusphere_redact_log_text($exception->getMessage()));
    http_response_code(503);
    echo json_encode([
        'status' => 'error',
        'db' => 'error',
        'php' => HEALTH_PHP_VERSION,
        'message' => 'Service temporarily unavailable',
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}
