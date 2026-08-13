<?php

declare(strict_types=1);

require_once __DIR__ . '/../directory_constants.php';
require_once __DIR__ . '/helpers.php';

/**
 * Records a typed controller observation only while its configuration revision
 * is still current. A late request can therefore never repaint newer state.
 *
 * @param array{fingerprint?:string,not_after?:string} $certificate
 */
function repo_directory_record_controller_outcome(mysqli $db, int $controllerId, int $revision, string $outcome, bool $transportFailure, array $certificate = []): bool
{
    return repo_transaction($db, function () use ($db, $controllerId, $revision, $outcome, $transportFailure, $certificate): bool {
        $currentRevision = repo_scalar($db, 'SELECT revision FROM deploy_ad_config WHERE id = 1 FOR UPDATE');
        if ((int) $currentRevision !== $revision) {
            return false;
        }
        $before = repo_fetch_one($db, 'SELECT last_outcome, consecutive_transport_failures FROM deploy_ad_controller_state WHERE controller_id = ? FOR UPDATE', 'i', [$controllerId]);
        $oldOutcome = (string) ($before['last_outcome'] ?? '');
        $failures = $transportFailure ? (int) ($before['consecutive_transport_failures'] ?? 0) + 1 : 0;
        $cooldown = $transportFailure ? VIRTUSPHERE_DIRECTORY_CONTROLLER_COOLDOWN_SECONDS : 0;
        $fingerprint = trim((string) ($certificate['fingerprint'] ?? ''));
        $notAfter = trim((string) ($certificate['not_after'] ?? ''));
        $stmt = $db->prepare(
            'INSERT INTO deploy_ad_controller_state
                (controller_id, config_revision, last_attempt_at, last_success_at,
                 last_outcome, consecutive_transport_failures, retry_after,
                 certificate_sha256, certificate_not_after)
             VALUES (?, ?, NOW(), IF(? = \'ok\', NOW(), NULL), ?, ?,
                     IF(? > 0, DATE_ADD(NOW(), INTERVAL ? SECOND), NULL),
                     NULLIF(?, \'\'), NULLIF(?, \'\'))
             ON DUPLICATE KEY UPDATE
                config_revision = VALUES(config_revision), last_attempt_at = NOW(),
                last_success_at = IF(VALUES(last_outcome) = \'ok\', NOW(), last_success_at),
                last_outcome = VALUES(last_outcome),
                consecutive_transport_failures = VALUES(consecutive_transport_failures),
                retry_after = VALUES(retry_after),
                certificate_sha256 = IF(VALUES(certificate_sha256) IS NULL, certificate_sha256, VALUES(certificate_sha256)),
                certificate_not_after = IF(VALUES(certificate_not_after) IS NULL, certificate_not_after, VALUES(certificate_not_after))'
        );
        $stmt->bind_param('iissiiiss', $controllerId, $revision, $outcome, $outcome, $failures, $cooldown, $cooldown, $fingerprint, $notAfter);
        $stmt->execute();

        return $oldOutcome !== $outcome;
    });
}
