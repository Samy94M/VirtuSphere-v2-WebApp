<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * Deletes expired diagnostic content while retaining the permanent replay
 * markers. Events and step projections follow their run through the schema's
 * cascades, so the maintenance worker has one atomic delete owner.
 */
function repo_purge_expired_package_runs(mysqli $db): int
{
    $stmt = $db->prepare(
        'DELETE r FROM deploy_package_runs r
         INNER JOIN deploy_package_run_markers m ON m.run_id = r.run_id
         WHERE m.expires_at <= UTC_TIMESTAMP(6)'
    );
    $stmt->execute();

    return $stmt->affected_rows;
}

/** @return array{previous:string,current:string} */
function repo_rotate_package_report_acceptance_generation(mysqli $db): array
{
    return repo_transaction($db, static function () use ($db): array {
        $previous = (string) repo_scalar(
            $db,
            'SELECT LOWER(BIN_TO_UUID(acceptance_generation)) FROM deploy_package_report_state WHERE id = 1 FOR UPDATE'
        );
        if ($previous === '') {
            throw new RuntimeException('Package report acceptance generation is missing.');
        }

        repo_execute(
            $db,
            'UPDATE deploy_package_report_state
             SET acceptance_generation = UUID_TO_BIN(UUID()), rotated_at = UTC_TIMESTAMP(6)
             WHERE id = 1'
        );
        $current = (string) repo_scalar(
            $db,
            'SELECT LOWER(BIN_TO_UUID(acceptance_generation)) FROM deploy_package_report_state WHERE id = 1'
        );
        if ($current === '' || hash_equals($previous, $current)) {
            throw new RuntimeException('Package report acceptance generation did not rotate.');
        }

        return ['previous' => $previous, 'current' => $current];
    });
}
