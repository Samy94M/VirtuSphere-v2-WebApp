<?php

declare(strict_types=1);

/**
 * Inventory job artifacts and the remote command that runs the pull
 * (ADR-0023). Split out of lib/ansible_inventory.php (Etappe 7, ADR-0006):
 * everything that prepares the work directory / accounts.yml and builds the
 * one-line remote command lives here, unchanged.
 */

/**
 * Prepares the artifacts for an inventory run: the ansible source (with the
 * inventory playbook) plus an ESXi accounts.yml. No serverlist, no MAC upload.
 *
 * @return array{local_dir:string, remote_dir:string, files:array<int,string>}
 */
function ansible_prepare_inventory_artifacts(mysqli $db, array $job, array $esxiCredential, string $esxiSecret, array $ansibleCredential, string $apiBaseUrl): array
{
    $jobId = (int) ($job['id'] ?? 0);
    if ($jobId <= 0) {
        throw new InvalidArgumentException('Deploy job is required.');
    }

    $label = 'inventory';
    $workDir = ansible_create_job_work_dir($jobId, $label);
    ansible_copy_source_files(ansible_source_dir(), $workDir);
    ansible_write_file(
        $workDir . DIRECTORY_SEPARATOR . 'accounts.yml',
        ansible_accounts_yml($esxiCredential, $esxiSecret, $ansibleCredential, $apiBaseUrl)
    );
    $trustFile = ansible_write_esxi_trust_artifact($workDir, $esxiCredential);

    return [
        'local_dir' => $workDir,
        'remote_dir' => ansible_remote_dir($jobId, $label),
        'files' => array_values(array_unique(array_merge(
            ansible_required_files(),
            ['accounts.yml'],
            $trustFile === null ? [] : [$trustFile]
        ))),
    ];
}

/** Remote command that runs the read-only inventory playbook. */
function ansible_inventory_remote_command(string $remoteDir, bool $verbose = false): string
{
    $playbook = VIRTUSPHERE_SYSTEM_PLAYBOOKS[VIRTUSPHERE_DEPLOY_MODE_INVENTORY];
    $commands = [
        'cd ' . ansible_sh_quote($remoteDir),
        'chmod 600 accounts.yml',
        'if [ -f ' . VIRTUSPHERE_ESXI_TRUST_FILE . ' ]; then chmod 600 ' . VIRTUSPHERE_ESXI_TRUST_FILE . '; fi',
        'ansible-playbook ' . ansible_sh_quote($playbook) . ($verbose ? ' -vvv' : '') . ' 2>&1',
    ];
    $cleanup = 'rm -rf -- ' . ansible_sh_quote($remoteDir);

    return 'trap ' . ansible_sh_quote($cleanup) . ' EXIT; ' . implode(' && ', $commands);
}
