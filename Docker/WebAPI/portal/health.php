<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/envboot.php';
require_once __DIR__ . '/../lib/headers.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/deploy_constants.php';
require_once __DIR__ . '/../lib/backup_status.php';

virtusphere_send_security_headers();
header('Content-Type: application/json; charset=utf-8');

// Grobe Laufzeitversion (major.minor), keine Patchstufe: der Endpoint ist
// unauthentifiziert, und eine exakte Version macht CVE-Zuordnung von aussen
// trivial. Der einzige maschinelle Konsument (install-VirtuSphere-MECM.ps1)
// liest nur `.status`. Gepinnt durch VersionExposureContractTest.
const HEALTH_PHP_VERSION = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

function health_bool_status(bool $ok): string
{
    return $ok ? 'ok' : 'error';
}

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

function health_log_checks(): array
{
    $logDir = dirname(__DIR__) . '/logs';
    $phpErrorLog = (string) ini_get('error_log');
    $phpLogDir = $phpErrorLog !== '' ? dirname($phpErrorLog) : '';

    $appLogWritable = is_dir($logDir) && is_writable($logDir);
    $phpLogWritable = $phpLogDir !== '' && is_dir($phpLogDir) && is_writable($phpLogDir);

    return [
        'status' => health_bool_status($appLogWritable && $phpLogWritable),
        'app_log_dir' => $appLogWritable ? 'ok' : 'error',
        'php_error_log_dir' => $phpLogWritable ? 'ok' : 'error',
    ];
}

function health_worker_checks(mysqli $db): array
{
    $status = VIRTUSPHERE_DEPLOY_STATUS_RUNNING;
    $row = health_statement_row($db, 'SELECT COUNT(*) AS running_jobs, SUM(CASE WHEN heartbeat_at IS NULL OR heartbeat_at < DATE_SUB(NOW(), INTERVAL 2 MINUTE) THEN 1 ELSE 0 END) AS stale_running_jobs, MAX(heartbeat_at) AS latest_heartbeat_at FROM deploy_jobs WHERE status = ?', 's', [$status]);
    $running = (int) ($row['running_jobs'] ?? 0);
    $stale = (int) ($row['stale_running_jobs'] ?? 0);

    return [
        'status' => $stale > 0 ? 'degraded' : 'ok',
        'running_jobs' => $running,
        'stale_running_jobs' => $stale,
        'latest_heartbeat_at' => $row['latest_heartbeat_at'] ?? null,
    ];
}

try {
    $db = db();
    health_statement_row($db, 'SELECT 1 AS ok');

    $logs = health_log_checks();
    $worker = health_worker_checks($db);
    $status = ($logs['status'] === 'ok' && $worker['status'] === 'ok') ? 'ok' : 'degraded';
    if ($status !== 'ok') {
        http_response_code(503);
    }

    // Informative only: backup health never changes the HTTP status. The portal
    // surfaces it in the settings card and dashboard banner (ADR-0021).
    $backup = backup_status_read();

    echo json_encode([
        'status' => $status,
        'db' => 'ok',
        'php' => HEALTH_PHP_VERSION,
        'logs' => $logs,
        'worker' => $worker,
        'backup' => [
            'status' => $backup['state'],
            'age_seconds' => $backup['age_seconds'],
        ],
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
