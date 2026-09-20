<?php

declare(strict_types=1);

require_once __DIR__ . '/repo/ansible_preflight.php';
require_once __DIR__ . '/repo/log.php';
require_once __DIR__ . '/credentials_test_message.php';
require_once __DIR__ . '/ansible.php';
require_once __DIR__ . '/ssh.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/log_redaction.php';

/** Missing URL permits tooling checks; invalid URL and DB failures are not absence. */
function ansible_full_test_execute(mysqli $db, array $credential): array
{
    $configuration = ansible_api_base_url_configuration($db);
    try {
        $url = $configuration['source'] === 'none' ? '' : ansible_normalize_api_base_url($configuration['value']);
        return credential_test_connection($credential,
            crypto_decrypt_secret((string) $credential['secret_ciphertext']), $url);
    } catch (Throwable $exception) {
        return credential_test_result(false, VIRTUSPHERE_INVENTORY_ERROR_CONFIG,
            virtusphere_redact_log_text($exception->getMessage()));
    }
}

/** Atomic evidence and audit owner for manual and scheduled full tests. */
function ansible_full_test_store_result(
    mysqli $connection,
    int $credentialId,
    ?int $userId,
    array $result,
    int $configRevision,
    int $testGeneration
): bool {
    $isAllowlistWarning = credentials_test_is_allowlist_warning($result);
    $failedComponent = ($result['code'] === VIRTUSPHERE_CREDENTIAL_TEST_SFTP || $isAllowlistWarning)
        ? $result['code']
        : (string) ($result['context']['component'] ?? '');
    $preflightStatus = VIRTUSPHERE_ANSIBLE_PREFLIGHT_STATUS_FAILED;
    if ($result['ok']) {
        $preflightStatus = $isAllowlistWarning
            ? VIRTUSPHERE_ANSIBLE_PREFLIGHT_STATUS_WARNING
            : VIRTUSPHERE_ANSIBLE_PREFLIGHT_STATUS_OK;
    }
    // The evidence row and its audit are one fact. The nested record helper
    // joins this transaction, so a registry or insert failure rolls both back
    // and can never recreate the split state from SC-022.
    return repo_transaction($connection, static function () use (
        $connection,
        $credentialId,
        $userId,
        $result,
        $configRevision,
        $testGeneration,
        $preflightStatus,
        $failedComponent,
        $isAllowlistWarning
    ): bool {
        $stored = repo_ansible_preflight_record(
            $connection,
            $credentialId,
            $preflightStatus,
            $failedComponent,
            $configRevision,
            $testGeneration
        );

        if (!$stored) {
            $testResult = VIRTUSPHERE_AUDIT_RESULT_WARNING;
            $testContext = [
                'outcome' => 'discarded',
                'evidence_stored' => false,
            ];
        } else {
            $testContext = [
                'outcome' => $result['code'],
                'evidence_stored' => true,
            ];
            if ($failedComponent !== '') {
                $testContext['component'] = $failedComponent;
            }
            $warnedIp = trim((string) ($result['context']['ip'] ?? ''));
            if ($warnedIp !== '') {
                $testContext['ip'] = $warnedIp;
            }
            $testResult = $result['ok']
                ? ($isAllowlistWarning ? VIRTUSPHERE_AUDIT_RESULT_WARNING : VIRTUSPHERE_AUDIT_RESULT_SUCCESS)
                : VIRTUSPHERE_AUDIT_RESULT_FAILURE;
        }

        if ($userId === null) {
            $testContext['scheduled'] = true;
        }
        if (!audit_event(
            $connection,
            VIRTUSPHERE_AUDIT_EVENT_CREDENTIAL_TESTED,
            'credential',
            $credentialId,
            $testResult,
            $testContext,
            $userId
        )) {
            throw new RuntimeException('Credential test audit could not be stored.');
        }

        return $stored;
    });

}
