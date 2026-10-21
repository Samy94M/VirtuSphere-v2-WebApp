<?php

declare(strict_types=1);

/**
 * The evidence behind the phase and findings of a page of VMs, read in a fixed
 * number of queries per batch and never per row (plan "Phase und Befund",
 * section 5). Pure consumers: virtusphere_vm_phase() and
 * virtusphere_vm_findings() in lib/vm_phase.php.
 *
 * Every source is a table that already exists and is written by its existing
 * owner; this module adds no writer and changes no row. Per VM it reads the
 * newest journal row with its job, the unresolved Create units, the latest
 * client event per phase and the newest package run; per bound ESXi access it
 * reads the cached `vm` inventory with its freshness.
 */

require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../deploy_constants.php';
require_once __DIR__ . '/../deploy_create_constants.php';
require_once __DIR__ . '/../deploy_stored_mode.php';
require_once __DIR__ . '/../esxi_inventory_evidence.php';
require_once __DIR__ . '/../status_evidence.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/settings.php';

// Bounds each prepared IN list independently of the number of VMs on a page.
const VIRTUSPHERE_VM_EVIDENCE_BATCH_SIZE = 500;

/**
 * Reads the evidence of all given VM rows (each with `id` and, when bound,
 * `vm_bound_credential_id`). The result is keyed so that
 * repo_vm_evidence_for_vm() can slice it per row without another query.
 *
 * @param list<array<string,mixed>> $vms
 * @return array{jobs:array<int,array<string,mixed>>,create_uncertain:array<int,list<array{job_id:int,position:int}>>,client_phases:array<int,array<string,array<string,mixed>>>,package_runs:array<int,array<string,mixed>>,inventory:array<int,array<string,mixed>>}
 */
function repo_vm_evidence(mysqli $db, array $vms, ?int $now = null): array
{
    $evidence = ['jobs' => [], 'create_uncertain' => [], 'client_phases' => [], 'package_runs' => [], 'inventory' => []];
    $vmIds = [];
    $credentialIds = [];
    foreach ($vms as $vm) {
        $vmId = (int) ($vm['id'] ?? 0);
        if ($vmId > 0) {
            $vmIds[] = $vmId;
        }
        $credentialId = (int) ($vm['vm_bound_credential_id'] ?? 0);
        if ($credentialId > 0) {
            $credentialIds[] = $credentialId;
        }
    }
    foreach (array_chunk(array_values(array_unique($vmIds)), VIRTUSPHERE_VM_EVIDENCE_BATCH_SIZE) as $batch) {
        $evidence['jobs'] += repo_vm_evidence_jobs($db, $batch);
        $evidence['create_uncertain'] += repo_vm_evidence_create_uncertain($db, $batch);
        $evidence['client_phases'] += repo_vm_evidence_client_phases($db, $batch);
        $evidence['package_runs'] += repo_vm_evidence_package_runs($db, $batch);
    }
    $credentialIds = array_values(array_unique($credentialIds));
    if ($credentialIds !== []) {
        $evidence['inventory'] = repo_vm_evidence_inventory($db, $credentialIds, $now ?? time());
    }

    return $evidence;
}

/**
 * The slice of one VM in the shape virtusphere_vm_findings() documents. Pure.
 *
 * @param array<string,mixed> $evidence the result of repo_vm_evidence()
 * @param array<string,mixed> $vm
 * @return array<string,mixed>
 */
function repo_vm_evidence_for_vm(array $evidence, array $vm, bool $isTemplate): array
{
    $vmId = (int) ($vm['id'] ?? 0);
    $credentialId = (int) ($vm['vm_bound_credential_id'] ?? 0);
    $clientPhases = [];
    foreach (VIRTUSPHERE_CLIENT_PHASES as $phase) {
        $clientPhases[$phase] = $evidence['client_phases'][$vmId][$phase] ?? null;
    }

    return [
        'is_template' => $isTemplate,
        'last_job' => $evidence['jobs'][$vmId] ?? null,
        'create_uncertain' => $evidence['create_uncertain'][$vmId] ?? [],
        'client_phases' => $clientPhases,
        'last_package_run' => $evidence['package_runs'][$vmId] ?? null,
        'inventory' => $credentialId > 0 ? ($evidence['inventory'][$credentialId] ?? null) : null,
    ];
}

/** @param list<int> $ids @return array{0:string,1:string} placeholders and bind types */
function repo_vm_evidence_in_list(array $ids): array
{
    return [implode(',', array_fill(0, count($ids), '?')), str_repeat('i', count($ids))];
}

/**
 * The newest journal row of each VM (deploy_job_vm_states by id, K15) joined
 * to its job. The mode comes from the stored payload through the one
 * predicate, deploy_job_stored_mode(), and is null when unreadable.
 *
 * @param list<int> $vmIds
 * @return array<int,array<string,mixed>>
 */
function repo_vm_evidence_jobs(mysqli $db, array $vmIds): array
{
    if ($vmIds === []) {
        return [];
    }
    [$placeholders, $types] = repo_vm_evidence_in_list($vmIds);
    $stmt = $db->prepare(
        'SELECT s.vm_id, j.id, j.status, j.payload_json, j.started_at, j.created_at, j.updated_at'
        . ' FROM deploy_job_vm_states s'
        . ' INNER JOIN (SELECT vm_id, MAX(id) AS max_id FROM deploy_job_vm_states WHERE vm_id IN (' . $placeholders . ') GROUP BY vm_id) latest ON latest.max_id = s.id'
        . ' INNER JOIN deploy_jobs j ON j.id = s.job_id'
    );
    $stmt->bind_param($types, ...$vmIds);
    $stmt->execute();

    $jobs = [];
    foreach (repo_fetch_all($stmt->get_result()) as $row) {
        $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
        $jobs[(int) $row['vm_id']] = [
            'id' => (int) $row['id'],
            'status' => (string) $row['status'],
            'mode' => deploy_job_stored_mode($payload),
            'started_at' => $row['started_at'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    return $jobs;
}

/**
 * Create units whose outcome on the host is unknown (ADR-0041), newest job
 * first. Only `uncertain` counts: `failed` is a known outcome and `running`
 * belongs to the active job the journal already shows.
 *
 * @param list<int> $vmIds
 * @return array<int,list<array{job_id:int,position:int}>>
 */
function repo_vm_evidence_create_uncertain(mysqli $db, array $vmIds): array
{
    if ($vmIds === []) {
        return [];
    }
    [$placeholders, $types] = repo_vm_evidence_in_list($vmIds);
    $stmt = $db->prepare(
        'SELECT vm_id, job_id, position FROM deploy_create_vm_results'
        . ' WHERE vm_id IN (' . $placeholders . ') AND status = ? ORDER BY vm_id, job_id DESC, position'
    );
    $params = array_merge($vmIds, [VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN]);
    $stmt->bind_param($types . 's', ...$params);
    $stmt->execute();

    $units = [];
    foreach (repo_fetch_all($stmt->get_result()) as $row) {
        $units[(int) $row['vm_id']][] = ['job_id' => (int) $row['job_id'], 'position' => (int) $row['position']];
    }

    return $units;
}

/**
 * The latest received event per (VM, phase): repo_client_phase_summary() for
 * a whole page in one query, over the (vm_id, phase, id) index.
 *
 * @param list<int> $vmIds
 * @return array<int,array<string,array<string,mixed>>>
 */
function repo_vm_evidence_client_phases(mysqli $db, array $vmIds): array
{
    if ($vmIds === []) {
        return [];
    }
    [$placeholders, $types] = repo_vm_evidence_in_list($vmIds);
    $stmt = $db->prepare(
        'SELECT e.vm_id, e.phase, e.event, e.detail, e.created_at FROM deploy_client_events e'
        . ' INNER JOIN (SELECT vm_id, phase, MAX(id) AS max_id FROM deploy_client_events WHERE vm_id IN (' . $placeholders . ') GROUP BY vm_id, phase) latest ON latest.max_id = e.id'
    );
    $stmt->bind_param($types, ...$vmIds);
    $stmt->execute();

    $phases = [];
    foreach (repo_fetch_all($stmt->get_result()) as $row) {
        $phases[(int) $row['vm_id']][(string) $row['phase']] = $row;
    }

    return $phases;
}

/**
 * The newest stored package run per VM with its reported failure count, the
 * same order and marker window as repo_package_runs_for_vm() (R53: received
 * evidence, never an inferred execution order).
 *
 * @param list<int> $vmIds
 * @return array<int,array<string,mixed>>
 */
function repo_vm_evidence_package_runs(mysqli $db, array $vmIds): array
{
    if ($vmIds === []) {
        return [];
    }
    [$placeholders, $types] = repo_vm_evidence_in_list($vmIds);
    $stmt = $db->prepare(
        'SELECT vm_id, run_id, project_name, package_version, completed_received_at, wrapper_result, reported_failures FROM ('
        . ' SELECT r.vm_id, LOWER(BIN_TO_UUID(r.run_id)) AS run_id, r.project_name, r.package_version,'
        . ' r.completed_received_at, r.wrapper_result,'
        . " (SELECT COUNT(*) FROM deploy_package_step_results s WHERE s.package_run_id = r.id AND s.result = 'fail') AS reported_failures,"
        . ' ROW_NUMBER() OVER (PARTITION BY r.vm_id ORDER BY r.last_evidence_at DESC, r.id DESC) AS newest'
        . ' FROM deploy_package_runs r INNER JOIN deploy_package_run_markers m ON m.run_id = r.run_id'
        . ' WHERE r.vm_id IN (' . $placeholders . ') AND m.expires_at > UTC_TIMESTAMP(6)'
        . ') runs WHERE runs.newest = 1'
    );
    $stmt->bind_param($types, ...$vmIds);
    $stmt->execute();

    $runs = [];
    foreach (repo_fetch_all($stmt->get_result()) as $row) {
        $runs[(int) $row['vm_id']] = $row;
    }

    return $runs;
}

/**
 * The cached `vm` inventory of each access with its freshness: `fresh` (the
 * success is younger than the evidence window of the configured interval),
 * `answered` (the latest observation of the `vm` kind got an answer),
 * `fresh_at` and `vms` as instance UUID => power state. A stale or unanswered
 * pull keeps its rows but is not evidence for a VM's absence or power state.
 *
 * @param list<int> $credentialIds
 * @return array<int,array<string,mixed>>
 */
function repo_vm_evidence_inventory(mysqli $db, array $credentialIds, int $now): array
{
    [$placeholders, $types] = repo_vm_evidence_in_list($credentialIds);
    $intervalHours = (int) repo_setting_value($db, VIRTUSPHERE_SETTING_ESXI_INVENTORY_INTERVAL_HOURS, (string) VIRTUSPHERE_ESXI_INVENTORY_INTERVAL_HOURS_DEFAULT);
    $window = esxi_inventory_evidence_window_seconds($intervalHours);

    $stmt = $db->prepare('SELECT credential_id, kind_freshness_json, kind_observation_json FROM deploy_esxi_inventory_state WHERE credential_id IN (' . $placeholders . ')');
    $stmt->bind_param($types, ...$credentialIds);
    $stmt->execute();
    $inventory = [];
    foreach (repo_fetch_all($stmt->get_result()) as $state) {
        $freshness = json_decode((string) ($state['kind_freshness_json'] ?? ''), true);
        $observations = json_decode((string) ($state['kind_observation_json'] ?? ''), true);
        $freshAt = is_array($freshness) && isset($freshness[VIRTUSPHERE_INVENTORY_KIND_VM]) ? (string) $freshness[VIRTUSPHERE_INVENTORY_KIND_VM] : '';
        $freshTs = virtusphere_evidence_timestamp($freshAt, $now);
        $observation = is_array($observations) && is_array($observations[VIRTUSPHERE_INVENTORY_KIND_VM] ?? null)
            ? $observations[VIRTUSPHERE_INVENTORY_KIND_VM]
            : [];
        $inventory[(int) $state['credential_id']] = [
            'fresh' => $freshTs !== null && ($now - $freshTs) <= $window,
            'answered' => (string) ($observation['outcome'] ?? '') === VIRTUSPHERE_INVENTORY_QUERY_ANSWERED,
            'fresh_at' => $freshAt,
            'vms' => [],
        ];
    }

    $stmt = $db->prepare('SELECT credential_id, meta_json FROM deploy_esxi_inventory WHERE kind = ? AND credential_id IN (' . $placeholders . ')');
    $params = array_merge([VIRTUSPHERE_INVENTORY_KIND_VM], $credentialIds);
    $stmt->bind_param('s' . $types, ...$params);
    $stmt->execute();
    foreach (repo_fetch_all($stmt->get_result()) as $row) {
        $credentialId = (int) $row['credential_id'];
        if (!isset($inventory[$credentialId])) {
            continue;
        }
        $meta = json_decode((string) ($row['meta_json'] ?? ''), true);
        $uuid = is_array($meta) ? (string) ($meta['instance_uuid'] ?? '') : '';
        if ($uuid !== '') {
            $inventory[$credentialId]['vms'][$uuid] = is_array($meta) ? ($meta['power_state'] ?? null) : null;
        }
    }

    return $inventory;
}
