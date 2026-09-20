<?php

declare(strict_types=1);

require_once __DIR__ . '/repo/ansible_test_schedule.php';
require_once __DIR__ . '/ansible_full_test.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/ssh.php';
require_once __DIR__ . '/log_redaction.php';

/** Idle-worker diagnostics use the manual full test, never a second probe chain. */
function ansible_test_run_due(mysqli $db): void
{
    $claim = repo_ansible_test_claim_due($db);
    if ($claim === null) {
        return;
    }
    $credential = $claim['credential'];
    $id = (int) $credential['id'];
    fwrite(STDOUT, '[1/1] RUN ansible-full-test credential=' . $id . "\n");
    try {
        $result = ansible_full_test_execute($db, $credential);
        $stored = ansible_full_test_store_result($db, $id, null, $result,
            (int) $credential['config_revision'], (int) $claim['generation']);
        $verdict = !$stored ? 'discarded' : ($result['ok']
            ? (credentials_test_is_allowlist_warning($result) ? 'warning' : 'pass') : 'fail');
        fwrite(STDOUT, '[1/1] ' . $verdict . ' ansible-full-test credential=' . $id . "\n");
    } catch (Throwable $exception) {
        fwrite(STDERR, '[1/1] infrastructure_error ansible-full-test credential=' . $id . ': '
            . virtusphere_redact_log_text($exception->getMessage()) . "\n");
        throw $exception;
    }
}
