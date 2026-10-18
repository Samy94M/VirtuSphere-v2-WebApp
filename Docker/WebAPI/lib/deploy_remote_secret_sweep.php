<?php

declare(strict_types=1);

require_once __DIR__ . '/audit_event_definitions.php';
require_once __DIR__ . '/audit_registry.php';
require_once __DIR__ . '/deploy_remote_secret.php';

/*
 * The "next contact" of K21 (VT-04): a deploy or inventory job removes the ESXi
 * access files that OTHER jobs of its Ansible credential left on the same target,
 * after its host preflight and before its own first upload. The write-ahead row,
 * the removal at job end and the reading of a removal's state line live in
 * lib/deploy_remote_secret.php; this module only sweeps.
 */

/**
 * The rows of one Ansible credential that the current target reaches and whose
 * job is not active any more: rows never tried first, then the longest untried.
 * A row whose job row is gone (its mission was deleted) has nothing left to
 * protect it and is included; a row under a running lease is not.
 *
 * @param array{target_host: string, target_port: int, target_fingerprint: ?string} $target
 * @return list<array{id: int|string, job_id: int|string, remote_dir: string}>
 */
function deploy_remote_secret_sweep_candidates(mysqli $db, int $credentialId, int $currentJobId, array $target): array
{
    $active = VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES;
    $placeholders = implode(',', array_fill(0, count($active), '?'));
    $stmt = $db->prepare(
        'SELECT s.id, s.job_id, s.remote_dir FROM deploy_remote_secret_files s'
        . ' LEFT JOIN deploy_jobs j ON j.id = s.job_id'
        . ' WHERE s.credential_ansible_id = ? AND s.job_id <> ?'
        . ' AND s.target_host = ? AND s.target_port = ? AND s.target_fingerprint <=> ?'
        . ' AND (s.lease_until IS NULL OR s.lease_until <= NOW(6))'
        . ' AND (j.id IS NULL OR j.status NOT IN (' . $placeholders . '))'
        . ' ORDER BY s.last_attempt_at IS NOT NULL, s.last_attempt_at, s.id LIMIT ' . VIRTUSPHERE_REMOTE_SECRET_SWEEP_LIMIT
    );
    $stmt->bind_param(
        'iisis' . str_repeat('s', count($active)),
        $credentialId,
        $currentJobId,
        $target['target_host'],
        $target['target_port'],
        $target['target_fingerprint'],
        ...$active
    );
    $stmt->execute();

    return repo_fetch_all($stmt->get_result());
}

/**
 * Rows of this credential that were left on ANOTHER target: after the credential
 * was pointed at a new host or the host key changed. This contact cannot reach
 * them; they stay, without an audit row, and are named in the error log.
 *
 * @param array{target_host: string, target_port: int, target_fingerprint: ?string} $target
 */
function deploy_remote_secret_diagnose_other_targets(mysqli $db, int $credentialId, array $target, int $currentJobId, string $secret): void
{
    $stmt = $db->prepare(
        'SELECT target_host, target_port, target_fingerprint, COUNT(*) AS files, MIN(uploaded_at) AS oldest FROM deploy_remote_secret_files'
        . ' WHERE credential_ansible_id = ? AND NOT (target_host = ? AND target_port = ? AND target_fingerprint <=> ?)'
        . ' GROUP BY target_host, target_port, target_fingerprint'
    );
    $stmt->bind_param('isis', $credentialId, $target['target_host'], $target['target_port'], $target['target_fingerprint']);
    $stmt->execute();
    foreach (repo_fetch_all($stmt->get_result()) as $other) {
        deploy_worker_cleanup_diagnose(
            $currentJobId,
            (int) $other['files'] . ' ESXi access file(s) of this Ansible credential were left on ' . deploy_remote_secret_target_label($other)
                . ', the oldest since ' . (string) $other['oldest'] . ' UTC. This contact reaches ' . deploy_remote_secret_target_label($target)
                . ', so they are kept and not removed from here.',
            $secret
        );
    }
}

/**
 * The "next contact" of K21: removes accounts.yml left behind by OTHER jobs of
 * the same Ansible credential on the same target. Only the file is removed, never
 * a directory, because the directory of an uncertain create unit holds the async
 * evidence.
 *
 * Per candidate: one heartbeat, a recheck of its lease, one removal. A confirmed
 * removal deletes the row and writes one audit row, in one transaction, with the
 * outcome "removed" or "absent" and the target host. A removal the host did not
 * confirm keeps the row, records the attempt and goes on with the next row; a
 * transport failure ends the sweep, because the host will not answer the next
 * row either. Never throws, so a side channel cannot end the current job.
 *
 * A time budget (K21-F, the manual and scheduled Volltest only) is the expired
 * seam: when it answers true before a candidate, that candidate is not started.
 * The sweep then stops with the rows still open, and the stopped seam tells the
 * caller it did not finish. A candidate started before that may still run to its own end.
 *
 * @param callable(): mysqli $connection the worker passes fn () => $channel->connection(); taken per use
 * @param array<string, mixed> $ansibleCredential
 * @param array{execute?: callable, log?: callable(string): void, tick?: callable(): void, expired?: callable(): bool, stopped?: callable(): void} $seams
 *        execute = ssh_execute_capture() signature; log receives one line per closed record; tick keeps the job's heartbeat;
 *        expired answers true once the time budget is used up, checked before each candidate (absent: no time limit);
 *        stopped is called once when the sweep ends before its last candidate (budget, transport failure, failed check or listing)
 * @return int the number of records closed
 */
function deploy_remote_secret_sweep(callable $connection, array $ansibleCredential, string $ansibleSecret, int $currentJobId, array $seams = []): int
{
    // Local callables, so every call below is one the portal's require closure can prove (tests/Support).
    $run = isset($seams['execute']) ? $seams['execute'] : ssh_execute_capture(...);
    $tick = isset($seams['tick']) ? $seams['tick'] : null;
    $log = isset($seams['log']) ? $seams['log'] : null;
    $stopped = isset($seams['stopped']) ? $seams['stopped'] : null;
    $expired = isset($seams['expired']) ? $seams['expired'] : null;
    $credentialId = (int) ($ansibleCredential['id'] ?? 0);
    try {
        $target = deploy_remote_secret_target($connection(), $credentialId);
        if ($target === null) {
            return 0;
        }
        deploy_remote_secret_diagnose_other_targets($connection(), $credentialId, $target, $currentJobId, $ansibleSecret);
        $candidates = deploy_remote_secret_sweep_candidates($connection(), $credentialId, $currentJobId, $target);
    } catch (Throwable $exception) {
        deploy_worker_cleanup_diagnose($currentJobId, 'Leftover ESXi access files could not be listed: ' . $exception->getMessage(), $ansibleSecret);
        deploy_remote_secret_side_channel($stopped);

        return 0;
    }

    $closed = 0;
    foreach ($candidates as $candidate) {
        if ($expired !== null && $expired()) {
            deploy_remote_secret_side_channel($stopped);

            break;
        }
        deploy_remote_secret_side_channel($tick);
        $rowId = (int) $candidate['id'];
        $jobId = (int) $candidate['job_id'];
        $remoteDir = (string) $candidate['remote_dir'];
        $file = 'ESXi access file of job ' . $jobId . ' in ' . $remoteDir;
        try {
            // A status query may have taken the row since the listing.
            if ((int) repo_scalar($connection(), 'SELECT COUNT(*) FROM deploy_remote_secret_files WHERE id = ? AND (lease_until IS NULL OR lease_until <= NOW(6))', 'i', [$rowId]) === 0) {
                continue;
            }
        } catch (Throwable $exception) {
            deploy_worker_cleanup_diagnose($currentJobId, 'Leftover ESXi access files could not be checked: ' . $exception->getMessage() . ' The rest waits for the next contact.', $ansibleSecret);
            deploy_remote_secret_side_channel($stopped);

            break;
        }

        try {
            $result = $run($ansibleCredential, $ansibleSecret, ansible_remote_secret_remove_command($remoteDir), VIRTUSPHERE_DEPLOY_REMOTE_CLEANUP_TIMEOUT_SECONDS);
        } catch (Throwable $exception) {
            deploy_remote_secret_record_attempt($connection, 'id = ?', 'i', [$rowId]);
            deploy_worker_cleanup_diagnose(
                $currentJobId,
                $file . ' could not be removed at this contact: ' . $exception->getMessage()
                    . ' The host is not asked about the other leftover files now; they wait for the next contact with this Ansible host.',
                $ansibleSecret
            );
            deploy_remote_secret_side_channel($stopped);

            break;
        }
        $state = deploy_remote_secret_removal_state($result);
        if ($state === null) {
            deploy_remote_secret_record_attempt($connection, 'id = ?', 'i', [$rowId]);
            deploy_worker_cleanup_diagnose(
                $currentJobId,
                $file . ' could not be removed at this contact: ' . deploy_remote_secret_unconfirmed_reason($result)
                    . ' It will be removed at the next contact with this Ansible host.',
                $ansibleSecret
            );

            continue;
        }

        try {
            deploy_remote_secret_close_swept($connection(), $rowId, $jobId, $credentialId, $currentJobId, $target, $state);
        } catch (Throwable $exception) {
            deploy_worker_cleanup_diagnose(
                $currentJobId,
                'The ' . $file . ' is ' . ($state === VIRTUSPHERE_REMOTE_SECRET_REMOVED ? 'removed' : 'no longer on the host')
                    . ', but its record could not be closed: ' . $exception->getMessage() . ' The next contact repeats the harmless removal.',
                $ansibleSecret
            );

            continue;
        }

        $closed++;
        deploy_remote_secret_side_channel(
            $log,
            $state === VIRTUSPHERE_REMOTE_SECRET_REMOVED
                ? 'Removed the ESXi access file that job ' . $jobId . ' left on the Ansible host.'
                : 'The ESXi access file that job ' . $jobId . ' left was no longer on the Ansible host; its record is closed.'
        );
    }

    return $closed;
}

/**
 * Deletes a swept row and writes its audit row in one transaction.
 *
 * @param array{target_host: string, target_port: int, target_fingerprint: ?string} $target
 */
function deploy_remote_secret_close_swept(mysqli $db, int $rowId, int $jobId, int $credentialId, int $currentJobId, array $target, string $state): void
{
    repo_transaction($db, static function () use ($db, $rowId, $jobId, $credentialId, $currentJobId, $target, $state): void {
        repo_execute($db, 'DELETE FROM deploy_remote_secret_files WHERE id = ?', 'i', [$rowId]);
        audit_event_required(
            $db,
            VIRTUSPHERE_AUDIT_EVENT_DEPLOY_REMOTE_SECRET_CLEARED,
            'deploy_job',
            $jobId,
            VIRTUSPHERE_AUDIT_RESULT_SUCCESS,
            [
                'credential_id' => $credentialId,
                'job_id' => $currentJobId,
                'host' => mb_substr($target['target_host'], 0, 253),
                'port' => $target['target_port'],
                'outcome' => $state,
            ]
        );
    });
}

/** Calls a side-channel seam (job log line, heartbeat). A failing one changes nothing about the removal. */
function deploy_remote_secret_side_channel(?callable $seam, string ...$arguments): void
{
    if ($seam === null) {
        return;
    }
    try {
        $seam(...$arguments);
    } catch (Throwable $exception) {
        // The job log and the heartbeat are side channels of the sweep, not part of it, and a
        // side channel never ends the work it describes: no rethrow, but one line says it failed.
        error_log('[deploy-remote-secret] side channel failed: ' . $exception::class);
    }
}
