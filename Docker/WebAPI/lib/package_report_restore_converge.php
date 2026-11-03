<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log_redaction.php';
require_once __DIR__ . '/repo/package_run_maintenance.php';

/** @return array{previous:string,current:string} */
function package_report_restore_converge(mysqli $db): array
{
    return repo_rotate_package_report_acceptance_generation($db);
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    fwrite(STDOUT, "[1/1] RUN package report restore convergence\n");
    try {
        $rotation = package_report_restore_converge(db());
        fwrite(STDOUT, '[1/1] PASS package report restore convergence (acceptance generation rotated to '
            . $rotation['current'] . ")\n");
        exit(0);
    } catch (Throwable $exception) {
        fwrite(STDERR, '[1/1] FAIL package report restore convergence: '
            . virtusphere_redact_log_text($exception->getMessage()) . "\n");
        exit(1);
    }
}
