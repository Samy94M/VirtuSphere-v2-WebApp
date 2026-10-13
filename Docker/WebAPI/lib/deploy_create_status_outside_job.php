<?php

declare(strict_types=1);

require_once __DIR__ . '/ansible_command.php';
require_once __DIR__ . '/ansible_create_protocol.php';
require_once __DIR__ . '/ansible_paths.php';
require_once __DIR__ . '/ansible_yaml.php';
require_once __DIR__ . '/deploy_create_constants.php';
require_once __DIR__ . '/deploy_job_output.php';
require_once __DIR__ . '/deploy_remote_secret.php';
require_once __DIR__ . '/ssh.php';

/**
 * Asks the Ansible host for the state of one create unit after its job ended
 * (K21, VT-04). The job's worker is gone, so the query runs outside it.
 *
 * It uploads accounts.yml alone into a private local directory and removes that
 * file again afterwards, also when the status check failed. It writes nothing to
 * deploy_create_vm_results and nothing to the job log: the source job is terminal.
 *
 * Only accounts.yml is uploaded: the job directory of an unresolved unit is never
 * removed, so the ESXi trust artifact of a strict-trust credential is still
 * there; if it is not, the status check fails visibly. Not used by any
 * production caller in K21.
 *
 * It runs beside the worker, so the single-worker rule of ADR-0042 does not keep
 * a sweep of another job away from its file. Its row therefore carries a lease
 * of VIRTUSPHERE_REMOTE_SECRET_STATUS_LEASE_SECONDS, longer than upload, status
 * command and removal together; a sweep leaves a leased row alone, and a failed
 * removal ends the lease so the next contact can take the row (K21-A4).
 *
 * @param array<string,mixed> $unit deploy_create_vm_results row: job_id, position, vm_id, vm_name, async_jid
 * @param array{upload?: callable, execute?: callable} $seams upload = ssh_sftp_upload_directory() signature, execute = ssh_execute_capture() signature
 * @return array{marker: ?array<string,mixed>, error: ?string, secret_removed: bool}
 *         secret_removed is true only when the host confirmed that accounts.yml is gone (removed or already absent)
 */
function deploy_create_status_query_outside_job(mysqli $db, array $unit, string $remoteDir, array $esxiCredential, string $esxiSecret, array $ansibleCredential, string $ansibleSecret, string $apiBaseUrl, array $seams = []): array
{
    $secrets = [$esxiSecret, $ansibleSecret];
    $jobId = (int) $unit['job_id'];
    $position = (int) $unit['position'];
    $vmId = (int) $unit['vm_id'];
    $vmName = (string) $unit['vm_name'];
    $asyncJid = (string) ($unit['async_jid'] ?? '');
    $run = $seams['execute'] ?? ssh_execute_capture(...);
    $result = ['marker' => null, 'error' => null, 'secret_removed' => false];
    $localDir = null;
    $remembered = false;

    try {
        if (!deploy_create_jid_is_valid($asyncJid)) {
            throw new InvalidArgumentException('The unit has no valid async job id; no status query was started.');
        }
        ansible_create_assert_remote_dir($remoteDir);
        $localDir = deploy_create_status_local_dir($jobId, $position);
        ansible_write_file(
            $localDir . DIRECTORY_SEPARATOR . 'accounts.yml',
            ansible_accounts_yml($esxiCredential, $esxiSecret, $ansibleCredential, $apiBaseUrl)
        );

        // The row exists before the first byte goes out: a worker that dies
        // between the two leaves a row for the next contact, never a file without one.
        deploy_remote_secret_remember($db, $jobId, (int) $ansibleCredential['id'], $remoteDir, VIRTUSPHERE_REMOTE_SECRET_STATUS_LEASE_SECONDS);
        $remembered = true;
        $upload = $seams['upload'] ?? static function (array $credential, string $secret, string $sourceDir, string $targetDir, ?callable $logger = null, ?callable $now = null): void {
            ssh_sftp_upload_directory($credential, $secret, $sourceDir, $targetDir, $logger, $now);
        };
        $upload($ansibleCredential, $ansibleSecret, $localDir, $remoteDir);

        $command = ansible_create_control_command($remoteDir, VIRTUSPHERE_CREATE_PLAYBOOK_STATUS, $position, [
            'vs_portal_vm_id' => $vmId,
            'vs_result_file' => ansible_create_result_file($remoteDir, $position, 'status'),
            'vs_async_dir' => ansible_create_async_dir($remoteDir, $position),
            'vs_async_jid' => $asyncJid,
        ]);
        $capture = $run($ansibleCredential, $ansibleSecret, $command, VIRTUSPHERE_CREATE_CONTROL_IDLE_TIMEOUT_SECONDS);
        $exitCode = (int) ($capture['exit_code'] ?? -1);
        if ($exitCode !== 0) {
            throw new RuntimeException('The status check returned exit code ' . $exitCode . '.');
        }
        $marker = ansible_create_marker_extract((string) ($capture['output'] ?? ''));
        if (!ansible_create_marker_matches($marker, $vmId, $vmName, $asyncJid)) {
            throw new CreateMarkerProtocolException('The marker describes another VM or another async job.');
        }
        $result['marker'] = $marker;
    } catch (Throwable $exception) {
        $result['error'] = deploy_worker_redact_secrets($exception->getMessage(), $secrets);
    } finally {
        // Whatever the status check did, a row that was written is answered
        // for: the file must not outlive this query without a row.
        if ($remembered) {
            $result['secret_removed'] = deploy_remote_secret_release(
                static fn (): mysqli => $db,
                $ansibleCredential,
                $ansibleSecret,
                $jobId,
                $remoteDir,
                false,
                $run
            );
        }
        if ($localDir !== null) {
            ansible_cleanup_artifacts($localDir);
        }
    }

    return $result;
}

/**
 * A private scratch directory under the deploy work root, holding nothing but
 * the accounts.yml of one status query.
 */
function deploy_create_status_local_dir(int $jobId, int $position): string
{
    $root = ansible_work_root();
    if (!is_dir($root) && !mkdir($root, 0770, true) && !is_dir($root)) {
        throw new RuntimeException('Cannot create deploy work directory.');
    }
    $dir = $root . DIRECTORY_SEPARATOR . 'status-' . $jobId . '-' . $position . '-' . bin2hex(random_bytes(4));
    if (!mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create status query work directory.');
    }

    return $dir;
}
