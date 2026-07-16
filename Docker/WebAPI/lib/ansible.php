<?php

declare(strict_types=1);

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/credentials.php';
require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/defaults.php';
require_once __DIR__ . '/envboot.php';
require_once __DIR__ . '/repo/esxi_inventory.php';
require_once __DIR__ . '/repo/missions.php';
require_once __DIR__ . '/repo/vms.php';
require_once __DIR__ . '/ansible_paths.php';
require_once __DIR__ . '/ansible_command.php';
require_once __DIR__ . '/ansible_yaml.php';

/**
 * Deploy orchestration: assemble a job's artifacts (work dir, accounts.yml,
 * serverlist.yml, patched upload script) and resolve the run-time facts the
 * assembly needs (API base URL, the host's sole datacenter, its object name,
 * the mission-ready gate). The artifact content lives in ansible_yaml.php, the
 * filesystem in ansible_paths.php, the remote commands in ansible_command.php.
 */

function ansible_prepare_job_artifacts(
    mysqli $db,
    array $job,
    array $esxiCredential,
    string $esxiSecret,
    array $ansibleCredential,
    string $apiBaseUrl
): array {
    $jobId = (int) ($job['id'] ?? 0);
    $missionId = (int) ($job['mission_id'] ?? 0);
    if ($jobId <= 0 || $missionId <= 0) {
        throw new InvalidArgumentException('Deploy job and mission are required.');
    }

    $mission = repo_get_mission($db, $missionId);
    if ($mission === null) {
        throw new RuntimeException('Mission not found.');
    }

    $missionName = (string) ($mission['mission_name'] ?? '');
    if ($missionName === '' || mission_name_is_template($missionName)) {
        throw new RuntimeException('Templates cannot be deployed directly.');
    }

    $payload = ansible_job_payload($job);

    // Third resolution level: the target host is known here, so a mission that
    // leaves its datacenter empty inherits the one this credential reports. Null
    // when the host has none or several; the gate below then refuses the job
    // rather than guessing. Re-read at run time on purpose: the cache may have
    // changed since the job was queued.
    $hostDatacenter = repo_esxi_sole_datacenter($db, (int) ($esxiCredential['id'] ?? 0)) ?? '';
    ansible_assert_mission_ready($mission, $hostDatacenter, (string) $payload['mode']);

    // The ESXi host object name, as the hypervisor knows itself. Only the
    // autostart playbook needs it, and an empty value (never pulled) makes it
    // fall back to the connection address.
    $esxiHostName = ansible_esxi_host_object_name($db, (int) ($esxiCredential['id'] ?? 0));

    $vms = getVMs($db, $missionId);
    if ($vms === []) {
        throw new RuntimeException('Mission has no VMs to deploy.');
    }

    $vms = ansible_filter_vms($vms, $payload['vm_ids']);
    if ($vms === []) {
        throw new RuntimeException('None of the selected VMs belong to this mission.');
    }

    $sourceDir = ansible_source_dir();
    $workDir = ansible_create_job_work_dir($jobId, $missionName);
    ansible_copy_source_files($sourceDir, $workDir);

    ansible_write_file(
        $workDir . DIRECTORY_SEPARATOR . 'accounts.yml',
        ansible_accounts_yml($esxiCredential, $esxiSecret, $ansibleCredential, $apiBaseUrl)
    );
    ansible_write_file(
        $workDir . DIRECTORY_SEPARATOR . 'serverlist.yml',
        ansible_serverlist_yml($mission, $vms, $payload['powercycle_wait'], $hostDatacenter, $esxiHostName)
    );
    ansible_patch_upload_script($workDir . DIRECTORY_SEPARATOR . VIRTUSPHERE_ANSIBLE_UPLOAD_SCRIPT, $apiBaseUrl, $missionId, $jobId);

    return [
        'local_dir' => $workDir,
        'remote_dir' => ansible_remote_dir($jobId, $missionName),
        'files' => array_values(array_unique(array_merge(ansible_required_files(), ['accounts.yml', 'serverlist.yml']))),
        // The full pipeline appends the autostart playbook only for a mission
        // that asked for it; the caller needs the mission row to know that, and
        // the mission is loaded here.
        'autostart_enabled' => (int) ($mission['autostart_enabled'] ?? 0) === 1,
    ];
}

function ansible_resolve_api_base_url(mysqli $db): string
{
    $setting = repo_fetch_one($db, 'SELECT setting_value FROM deploy_settings WHERE setting_key = ? LIMIT 1', 's', [VIRTUSPHERE_SETTING_API_BASE_URL]);
    $value = trim((string) ($setting['setting_value'] ?? ''));
    if ($value === '') {
        $value = trim(envboot_optional('APP_PUBLIC_BASE_URL', ''));
    }

    if ($value === '') {
        throw new RuntimeException('Set deploy_settings.api_base_url or APP_PUBLIC_BASE_URL before running deploy jobs.');
    }

    return ansible_normalize_api_base_url($value);
}

function ansible_normalize_api_base_url(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        throw new InvalidArgumentException('API base URL is required.');
    }

    if (preg_match('#^https?://#i', $value) !== 1) {
        $value = 'http://' . $value;
    }

    $parts = parse_url($value);
    if (!is_array($parts) || empty($parts['host'])) {
        throw new InvalidArgumentException('API base URL must include a host.');
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        throw new InvalidArgumentException('API base URL must use http or https.');
    }

    return rtrim($value, '/');
}

/**
 * The name the ESXi host has for itself, from the inventory cache. Standalone
 * hosts report exactly one; more than one means the credential points at a
 * vCenter, where guessing which host to configure is not the portal's call.
 * Empty when the credential was never pulled, or when it is ambiguous.
 */
function ansible_esxi_host_object_name(mysqli $db, int $credentialId): string
{
    if ($credentialId <= 0) {
        return '';
    }

    $hosts = repo_esxi_inventory_for_credential($db, $credentialId)['host'] ?? [];

    return count($hosts) === 1 ? trim((string) ($hosts[0]['name'] ?? '')) : '';
}

/**
 * Worker-side gate, mirroring repo_deploy_assert_mission_ready(). It runs again
 * at job time because the inventory cache can change between queueing and the
 * run: a pull may retire the host's only datacenter or surface a second one. In
 * that case the job fails loudly instead of deploying into a guessed location.
 *
 * Skipped for modes that read no location, so the two gates agree on which
 * question they are asking.
 */
function ansible_assert_mission_ready(array $mission, string $hostDatacenter = '', string $mode = VIRTUSPHERE_DEPLOY_MODE_FULL): void
{
    if (!virtusphere_deploy_mode_needs_location($mode)) {
        return;
    }
    if (trim((string) ($mission['hypervisor_datacenter'] ?? '')) === '' && trim($hostDatacenter) === '') {
        throw new RuntimeException('Mission datacenter is required: the ESXi credential of this job does not report exactly one datacenter.');
    }
    if (trim((string) ($mission['hypervisor_datastorage'] ?? '')) === '') {
        throw new RuntimeException('Mission datastore is required before deployment.');
    }
}
