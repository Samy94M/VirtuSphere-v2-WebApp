<?php

declare(strict_types=1);

require_once __DIR__ . '/repo/ansible_test_schedule.php';
require_once __DIR__ . '/ansible_full_test.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/ssh.php';
require_once __DIR__ . '/log_redaction.php';

/**
 * Idle-worker diagnostics use the manual full test, never a second probe chain.
 *
 * $seams: 'claim' => callable(mysqli): ?array replaces repo_ansible_test_claim_due()
 * (tests only: it returns ['credential' => ..., 'generation' => ...]); every other
 * seam goes to ansible_full_test_execute().
 *
 * @param array<string, mixed> $seams
 */
function ansible_test_run_due(mysqli $db, array $seams = []): void
{
    $claim = isset($seams['claim']) ? $seams['claim']($db) : repo_ansible_test_claim_due($db);
    if ($claim === null) {
        return;
    }
    $credential = $claim['credential'];
    $id = (int) $credential['id'];
    fwrite(STDOUT, '[1/1] RUN ansible-full-test credential=' . $id . "\n");
    try {
        // The sweep budget leaves room before this worker's alarm, not the portal's read timeout.
        $result = ansible_full_test_execute($db, $credential, $seams + ['time_limit' => VIRTUSPHERE_ANSIBLE_TEST_TOTAL_TIMEOUT_SECONDS]);
        $stored = ansible_full_test_store_result($db, $id, null, $result,
            (int) $credential['config_revision'], (int) $claim['generation']);
        $verdict = !$stored ? 'discarded' : ($result['ok']
            ? (credentials_test_is_allowlist_warning($result) ? 'warning' : 'pass') : 'fail');
        // The sweep outcome rides on the same line: the scheduled test has no page
        // to show it, and the cleared audit rows carry the same count.
        $sweep = isset($result['secret_sweep'])
            ? ' secret_sweep closed=' . (int) $result['secret_sweep']['closed'] . ' aborted=' . ($result['secret_sweep']['aborted'] ? '1' : '0')
            : '';
        fwrite(STDOUT, '[1/1] ' . $verdict . ' ansible-full-test credential=' . $id . $sweep . "\n");
    } catch (Throwable $exception) {
        fwrite(STDERR, '[1/1] infrastructure_error ansible-full-test credential=' . $id . ': '
            . virtusphere_redact_log_text($exception->getMessage()) . "\n");
        throw $exception;
    }
}
