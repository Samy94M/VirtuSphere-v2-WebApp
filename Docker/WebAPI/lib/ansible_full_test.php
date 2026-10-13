<?php

declare(strict_types=1);

require_once __DIR__ . '/repo/ansible_preflight.php';
require_once __DIR__ . '/repo/log.php';
require_once __DIR__ . '/credentials_test_message.php';
require_once __DIR__ . '/ansible.php';
require_once __DIR__ . '/ssh.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/log_redaction.php';
require_once __DIR__ . '/ansible_test_config.php';
require_once __DIR__ . '/deploy_remote_secret_sweep.php';

/**
 * The most time the sweep of a passed Volltest may START candidates in (K21-F).
 * The same for the manual and the scheduled test; see ansible_full_test_sweep_deadline().
 */
const VIRTUSPHERE_REMOTE_SECRET_TEST_SWEEP_BUDGET_SECONDS = 60;

/**
 * Time that stays free of the sweep at the end of the scheduled test, for the
 * evidence store and the flash. The scheduled worker's alarm is
 * VIRTUSPHERE_ANSIBLE_TEST_TOTAL_TIMEOUT_SECONDS; an alarm during the sweep would
 * end the test before its evidence is stored.
 */
const VIRTUSPHERE_REMOTE_SECRET_TEST_SWEEP_MARGIN_SECONDS = 30;

/**
 * The time limit of a manual Volltest, which runs in a portal request: nginx
 * ends every PHP request at fastcgi_read_timeout 300 (Docker/nginx/default.conf),
 * long before a scheduled test's alarm would.
 */
const VIRTUSPHERE_ANSIBLE_TEST_WEB_LIMIT_SECONDS = 300;

/**
 * The sweep deadline of a passed Volltest (K21-F).
 *
 * The budget is the constant, but never more than what is left before the time
 * limit of the path that runs the test: the scheduled worker's alarm
 * (VIRTUSPHERE_ANSIBLE_TEST_TOTAL_TIMEOUT_SECONDS, 600) or the manual request's
 * read timeout (VIRTUSPHERE_ANSIBLE_TEST_WEB_LIMIT_SECONDS, 300):
 *
 *   budget = max(0, min(SWEEP_BUDGET, LIMIT - elapsed - OVERRUN - MARGIN))
 *
 * elapsed is the time since the test began, OVERRUN the most one removal can run
 * past its deadline (connect plus its command timeout, the same worst case the
 * K21 lease uses). A candidate starts only before the deadline, so the sweep ends
 * by elapsed + budget + OVERRUN, which is at most LIMIT - MARGIN. With the values
 * 45 and 30 and a 60 s budget: scheduled (600) the full budget applies up to an
 * elapsed time of 465 s and none from 525 s on; manual (300) up to 165 s and none
 * from 225 s on. A candidate that gets no budget waits for the next contact.
 *
 * @return float absolute clock value; a deadline at or before $now means no candidate starts
 */
function ansible_full_test_sweep_deadline(float $testStartedAt, float $now, int $limitSeconds = VIRTUSPHERE_ANSIBLE_TEST_WEB_LIMIT_SECONDS): float
{
    $overrun = VIRTUSPHERE_REMOTE_SECRET_CONNECT_SECONDS + VIRTUSPHERE_DEPLOY_REMOTE_CLEANUP_TIMEOUT_SECONDS;
    $remaining = $limitSeconds - ($now - $testStartedAt) - $overrun - VIRTUSPHERE_REMOTE_SECRET_TEST_SWEEP_MARGIN_SECONDS;
    $budget = max(0.0, min((float) VIRTUSPHERE_REMOTE_SECRET_TEST_SWEEP_BUDGET_SECONDS, $remaining));

    return $now + $budget;
}

/**
 * The one owner of a full test of an Ansible credential, manual and scheduled
 * (K21-F). Missing URL permits tooling checks; invalid URL and DB failures are not absence.
 *
 * After a PASSED test the leftover ESXi access files of this credential on its
 * target are swept (deploy_remote_secret_sweep, the same owner as the job
 * contacts). The outcome is $result['secret_sweep'] = ['closed' => int, 'aborted' => bool].
 * A failed test never sweeps.
 *
 * Seams (tests replace the external work; production passes none):
 *   test:       callable(array $credential, string $secret, string $apiBaseUrl): array
 *               replaces credential_test_connection()
 *   execute:    the ssh_execute_capture() signature for the removal
 *   clock:      callable(): float, microtime(true) by default
 *   time_limit: seconds the sweep budget must leave room for; the scheduled worker
 *               passes its alarm, the default is the manual request's limit
 *
 * @param array{test?: callable, execute?: callable, clock?: callable(): float, time_limit?: int} $seams
 */
function ansible_full_test_execute(mysqli $db, array $credential, array $seams = []): array
{
    $clock = isset($seams['clock']) ? $seams['clock'] : static fn (): float => microtime(true);
    $startedAt = (float) $clock();
    $timeLimit = (int) ($seams['time_limit'] ?? VIRTUSPHERE_ANSIBLE_TEST_WEB_LIMIT_SECONDS);
    $configuration = ansible_api_base_url_configuration($db);
    try {
        $url = $configuration['source'] === 'none' ? '' : ansible_normalize_api_base_url($configuration['value']);
        $secret = crypto_decrypt_secret((string) $credential['secret_ciphertext']);
        $test = isset($seams['test']) ? $seams['test'] : static fn (array $credential, string $secret, string $apiBaseUrl): array => credential_test_connection($credential, $secret, $apiBaseUrl);
        $result = $test($credential, $secret, $url);
    } catch (Throwable $exception) {
        return credential_test_result(false, VIRTUSPHERE_INVENTORY_ERROR_CONFIG,
            virtusphere_redact_log_text($exception->getMessage()));
    }
    if (!$result['ok']) {
        return $result;
    }

    // The sweep runs after the test verdict and outside any transaction: it holds
    // SSH connections, which no database transaction may wait for. It never throws.
    $aborted = false;
    $deadline = ansible_full_test_sweep_deadline($startedAt, (float) $clock(), $timeLimit);
    $sweepSeams = [
        'expired' => static fn (): bool => (float) $clock() >= $deadline,
        'stopped' => static function () use (&$aborted): void {
            $aborted = true;
        },
    ];
    if (isset($seams['execute'])) {
        $sweepSeams['execute'] = $seams['execute'];
    }
    $closed = deploy_remote_secret_sweep(static fn (): mysqli => $db, $credential, $secret, 0, $sweepSeams);
    $result['secret_sweep'] = ['closed' => $closed, 'aborted' => $aborted];

    return $result;
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
