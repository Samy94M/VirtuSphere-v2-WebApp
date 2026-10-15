<?php

declare(strict_types=1);

/**
 * The two ESXi capability gates of a mission job, run before any VM is marked
 * or file uploaded: the write/create preflight (DF-E2, ADR-0023 amendment 7)
 * and the autostart preflight. Split from deploy_worker_mission.php when the
 * create verdict joined them (ADR-0006 budget).
 */

require_once __DIR__ . '/ansible_command_modes.php';
require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/esxi_capabilities.php';
require_once __DIR__ . '/esxi_create_compatibility.php';
require_once __DIR__ . '/esxi_inventory.php';
require_once __DIR__ . '/esxi_write_capability.php';
require_once __DIR__ . '/repo/deploy_jobs.php';
require_once __DIR__ . '/repo/esxi_inventory.php';
require_once __DIR__ . '/deploy_worker_network_preflight.php';

/**
 * Decides whether this job may write the ESXi autostart policy, from the cached
 * capability facts of the target credential (ADR-0025).
 *
 * The facts always land in the job log first, fresh or not, so the run is
 * explainable afterwards even when the verdict was "go ahead".
 *
 * Refusals need a FRESH fact, never a stale or absent one: the cache is a mirror
 * and must not block on an assumption (ADR-0023). Consequences differ by mode,
 * because the operator asked for different things:
 *  - `autostart` is a request to write the policy. If it provably cannot work,
 *    fail loudly rather than report a success that changed nothing.
 *  - `full` is a request to deploy. A host that ignores autostart should not cost
 *    the operator the VMs, so the step is dropped and the pipeline continues.
 *
 * @return bool whether the autostart playbook stays in the sequence
 */
function deploy_worker_autostart_preflight(mysqli $db, int $jobId, int $credentialId, string $mode, bool $autostartEnabled): bool
{
    $state = repo_esxi_inventory_state($db, $credentialId);
    $intervalHours = esxi_inventory_interval_hours($db);
    $preflight = esxi_autostart_preflight($state, $intervalHours);
    $fresh = esxi_capabilities_fresh($state, $intervalHours);
    repo_append_deploy_job_log($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, esxi_capabilities_log_line($preflight['facts'], $fresh));

    if ($preflight['verdict'] === 'block') {
        throw new RuntimeException('Autostart cannot be written: this ESXi host reports a free licence, whose API is read-only. Assign a licensed edition or turn the mission autostart off.');
    }

    if ($preflight['verdict'] === 'skip') {
        if ($mode === VIRTUSPHERE_DEPLOY_MODE_AUTOSTART) {
            throw new RuntimeException('Autostart cannot be written: this ESXi host is part of a vSphere HA cluster, where ESXi disables autostart. Use the HA restart priority instead.');
        }
        repo_append_deploy_job_log($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'Skipping the autostart step: the target host is in a vSphere HA cluster, where autostart has no effect. The rest of the pipeline runs unchanged.');

        return false;
    }

    if (!$fresh) {
        repo_append_deploy_job_log($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'Autostart preflight ran on stale or missing capability facts. Proceeding: ESXi remains the authority and will reject the write if it cannot perform it.');
    }

    return $autostartEnabled || $mode === VIRTUSPHERE_DEPLOY_MODE_AUTOSTART;
}

/**
 * DF-E2 in the worker: logs the evidence, then refuses a writing mode on a
 * proven read-only host before any VM is marked or file uploaded. The refusal
 * is configuration_blocked, never execution_failed: no playbook ran.
 *
 * A create-capable mode then gets its create verdict from the same owner the
 * queue used (ADR-0023 amendment 7): a scheduled job can start days after it
 * was queued, and the host may have changed in between. The returned hardware
 * version is what serverlist.yml carries; null leaves the choice to ESXi.
 *
 * @param list<int> $vmIds the job's materialized scope
 */
function deploy_worker_esxi_write_preflight(mysqli $db, int $jobId, int $credentialId, string $mode, int $missionId, array $vmIds): ?int
{
    if (!ansible_mode_writes_esxi($mode)) {
        return null;
    }
    $state = repo_esxi_inventory_state($db, $credentialId);
    $fresh = esxi_capabilities_fresh($state, esxi_inventory_interval_hours($db));
    repo_append_deploy_job_log($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, esxi_capabilities_log_line(esxi_capabilities($state), $fresh));
    try {
        deploy_assert_esxi_write_capability($db, $credentialId, $mode);
    } catch (ValidationException $blocked) {
        throw new DeployWorkerConfigurationBlocked($blocked->getMessage(), null, $blocked);
    }
    if (!$fresh) {
        repo_append_deploy_job_log($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'ESXi write preflight used stale or missing capability facts. Proceeding: ESXi remains the authority and may reject the write.');
    }
    if (!esxi_create_mode_applies($mode)) {
        return null;
    }
    $verdict = deploy_esxi_create_compatibility($db, $credentialId, $mode, $missionId, $vmIds);
    repo_append_deploy_job_log($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, esxi_create_compatibility_log_line($verdict));
    if ($verdict['verdict'] === 'block') {
        throw new DeployWorkerConfigurationBlocked(esxi_create_compatibility_message($verdict));
    }

    return $verdict['hardware_version'];
}
