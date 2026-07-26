<?php

declare(strict_types=1);

require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../defaults.php';
require_once __DIR__ . '/../deploy_constants.php';
require_once __DIR__ . '/../validate.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/status_events.php';
require_once __DIR__ . '/vms.php';
// deleteMission() cascades a mission's jobs and logs away, so it needs the same
// "not while a deploy runs" guard the job repo owns.
require_once __DIR__ . '/deploy_jobs.php';

/**
 * The mission's ESXi autostart defaults (ADR-0025), as one list. A mission column
 * has to be named in several places (update, checked update, clone, save as
 * template, the transfer format); naming them once means a new autostart field
 * cannot be saved by the editor and then silently dropped by the clone.
 */
const REPO_MISSION_AUTOSTART_COLUMNS = [
    'autostart_enabled',
    'autostart_start_delay',
    'autostart_stop_delay',
    'autostart_stop_action',
    'autostart_wait_for_heartbeat',
];

/**
 * Every mission column an operator may edit, in one place. mission_creator is
 * absent on purpose: it is stamped once at creation and is not editable.
 */
const REPO_MISSION_EDITABLE_COLUMNS = [
    'mission_name',
    'mission_status',
    'mission_notes',
    'wds_vlan',
    'hypervisor_datastorage',
    'hypervisor_datacenter',
    'domain',
    ...REPO_MISSION_AUTOSTART_COLUMNS,
];

/**
 * Mission columns a clone or a template capture carries over. The name and the
 * status are set by the caller, so they are not in here.
 */
const REPO_MISSION_COPYABLE_COLUMNS = [
    'mission_notes',
    'wds_vlan',
    'hypervisor_datastorage',
    'hypervisor_datacenter',
    'domain',
    ...REPO_MISSION_AUTOSTART_COLUMNS,
];

/**
 * Copies REPO_MISSION_COPYABLE_COLUMNS out of a mission row or an imported
 * mission block. Used by the clone, the template capture and the importer, so it
 * has to tolerate untrusted JSON: a non-scalar value is dropped, not cast, or the
 * string conversion would fatal on an array.
 *
 * An autostart key that is absent (a mission export written before this feature)
 * is omitted rather than defaulted to '': the columns are INT NOT NULL, and the
 * omission lets the schema default apply. The validator fills the rest.
 *
 * @param array<string, mixed> $source
 * @return array<string, mixed>
 */
function repo_mission_copyable_values(array $source): array
{
    $values = [];
    foreach (REPO_MISSION_COPYABLE_COLUMNS as $column) {
        $raw = $source[$column] ?? null;
        if (in_array($column, REPO_MISSION_AUTOSTART_COLUMNS, true)) {
            if ($raw !== null && is_scalar($raw)) {
                $values[$column] = $raw;
            }
            continue;
        }
        $values[$column] = is_scalar($raw) ? (string) $raw : '';
    }

    return $values;
}

/** Reads a mission autostart checkbox/bool, defaulting to off when absent. */
function repo_mission_autostart_flag(array $missionData, string $key): int
{
    if (!array_key_exists($key, $missionData)) {
        return (bool) (VIRTUSPHERE_MISSION_AUTOSTART_DEFAULTS[$key] ?? false) ? 1 : 0;
    }

    return in_array(strtolower((string) $missionData[$key]), ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
}

function repo_mission_name_exists(mysqli $db, string $name, int $excludeMissionId = 0): bool
{
    if ($excludeMissionId > 0) {
        $row = repo_fetch_one($db, 'SELECT id FROM deploy_missions WHERE mission_name = ? AND id <> ? LIMIT 1', 'si', [$name, $excludeMissionId]);
    } else {
        $row = repo_fetch_one($db, 'SELECT id FROM deploy_missions WHERE mission_name = ? LIMIT 1', 's', [$name]);
    }

    return $row !== null;
}

// Missions and templates share deploy_missions; the template prefix is the only
// marker that separates them. Validation messages derive their wording from that
// same rule, so a template form never reports a "mission name" problem.
function repo_mission_name_label(string $missionName): string
{
    return mission_name_is_template(trim($missionName))
        ? validator_label('template_name', 'Template name')
        : validator_label('mission_name', 'Mission name');
}

function repo_validate_mission_values(mysqli $db, array $missionData, int $excludeMissionId = 0, bool $requireName = false, bool $requireLocation = false): array
{
    $validator = new Validator();
    $values = [];

    if ($requireName || array_key_exists('mission_name', $missionData)) {
        $nameLabel = repo_mission_name_label((string) ($missionData['mission_name'] ?? ''));
        $values['mission_name'] = $validator->requireString('mission_name', $missionData['mission_name'] ?? '', $nameLabel, 255);
        if ($values['mission_name'] !== '' && preg_match('/\s/', $values['mission_name']) === 1) {
            $validator->add('mission_name', validator_text('validate.name_no_spaces', ':field must not contain spaces.', ['field' => $nameLabel]));
        }
    }
    if (array_key_exists('mission_status', $missionData)) {
        $values['mission_status'] = $validator->requireString('mission_status', $missionData['mission_status'] ?? '', validator_label('mission_status', 'Mission status'), 255);
    }
    if (array_key_exists('mission_notes', $missionData)) {
        $values['mission_notes'] = $validator->optionalString('mission_notes', $missionData['mission_notes'] ?? '', validator_label('mission_notes', 'Mission notes'), 65535);
    }
    if (array_key_exists('wds_vlan', $missionData)) {
        $values['wds_vlan'] = $validator->optionalString('wds_vlan', $missionData['wds_vlan'] ?? '', validator_label('wds_vlan', 'WDS VLAN'), 255);
    }
    if (array_key_exists('hypervisor_datastorage', $missionData)) {
        $datastoreLabel = validator_label('datastore', 'Datastore');
        $values['hypervisor_datastorage'] = $requireLocation
            ? $validator->requireString('hypervisor_datastorage', $missionData['hypervisor_datastorage'] ?? '', $datastoreLabel, 255)
            : $validator->optionalString('hypervisor_datastorage', $missionData['hypervisor_datastorage'] ?? '', $datastoreLabel, 255);
    }
    // Always optional, unlike the datastore: an empty datacenter is resolved at
    // deploy time from the chosen ESXi credential when that host reports exactly
    // one (ADR-0023). Storing it anyway would only re-create the meaningless
    // copies migration 0014 removed one level below. The deploy gates refuse a
    // job where neither the mission nor the host can supply a name.
    if (array_key_exists('hypervisor_datacenter', $missionData)) {
        $values['hypervisor_datacenter'] = $validator->optionalString('hypervisor_datacenter', $missionData['hypervisor_datacenter'] ?? '', validator_label('datacenter', 'Datacenter'), 255);
    }
    if (array_key_exists('domain', $missionData)) {
        $values['domain'] = $validator->fqdn('domain', $missionData['domain'] ?? '', validator_label('domain', 'Domain'), $requireLocation);
    }
    // ESXi autostart defaults (ADR-0025). The mission's delays are the host's
    // system_defaults and must be a real wait: unlike a VM, a mission has nothing
    // to inherit from, so VIRTUSPHERE_AUTOSTART_DELAY_INHERIT is out of range here.
    if (array_key_exists('autostart_enabled', $missionData)) {
        $values['autostart_enabled'] = repo_mission_autostart_flag($missionData, 'autostart_enabled');
    }
    if (array_key_exists('autostart_wait_for_heartbeat', $missionData)) {
        $values['autostart_wait_for_heartbeat'] = repo_mission_autostart_flag($missionData, 'autostart_wait_for_heartbeat');
    }
    foreach (['autostart_start_delay', 'autostart_stop_delay'] as $delayField) {
        if (!array_key_exists($delayField, $missionData)) {
            continue;
        }
        $values[$delayField] = $validator->intRange(
            $delayField,
            $missionData[$delayField],
            validator_label($delayField, $delayField === 'autostart_start_delay' ? 'Start delay' : 'Stop delay'),
            VIRTUSPHERE_AUTOSTART_DELAY_MIN,
            VIRTUSPHERE_AUTOSTART_DELAY_MAX,
            (int) VIRTUSPHERE_MISSION_AUTOSTART_DEFAULTS[$delayField]
        );
    }
    if (array_key_exists('autostart_stop_action', $missionData)) {
        // NOT Validator::enum(): that helper lower-cases its input, and these are
        // vSphere API literals that the Ansible module compares case-sensitively.
        // 'guestshutdown' is not 'guestShutdown' to ESXi.
        $stopAction = trim((string) ($missionData['autostart_stop_action'] ?? ''));
        if ($stopAction === '') {
            $stopAction = VIRTUSPHERE_MISSION_AUTOSTART_DEFAULTS['autostart_stop_action'];
        }
        if (!in_array($stopAction, VIRTUSPHERE_AUTOSTART_STOP_ACTIONS, true)) {
            $validator->add('autostart_stop_action', validator_text('validate.enum', ':field has an invalid value.', ['field' => validator_label('autostart_stop_action', 'Stop action')]));
        }
        $values['autostart_stop_action'] = $stopAction;
    }

    $validator->throwIfInvalid();

    if (isset($values['mission_name']) && repo_mission_name_exists($db, (string) $values['mission_name'], $excludeMissionId)) {
        $message = validator_text('validate.name_taken', ':field already exists.', ['field' => repo_mission_name_label((string) $values['mission_name'])]);
        throw new ValidationException(['mission_name' => $message], $message);
    }

    return $values;
}

function getMissions($connection)
{
    $stmt = $connection->prepare('SELECT m.*, (SELECT COUNT(*) FROM deploy_vms v WHERE v.mission_id = m.id) AS vm_count FROM deploy_missions m ORDER BY m.mission_name');
    $stmt->execute();

    return repo_fetch_all($stmt->get_result());
}

/**
 * Deletes a mission and everything the schema cascades with it: its VM rows,
 * its deploy jobs and their logs.
 *
 * That cascade is why this refuses to run while a job of the mission is queued
 * or running. Without the guard the delete pulled the job row, its log rows and
 * the VM rows out from under a worker that was mid-playbook: the worker then
 * failed on a foreign key while writing its next log line, the VMs it had
 * already created on ESXi were left with nothing pointing at them, and the
 * operator's only trace of the run was gone with the rows. The two sibling
 * guards (repo_delete_credential, the bulk VM delete) already answered this
 * situation, and they answer it the same way: refuse, do not cancel.
 */
function deleteMission($id, $connection)
{
    $missionId = repo_id($id);
    if ($missionId <= 0) {
        throw new InvalidArgumentException('Mission id is required.');
    }

    return repo_transaction($connection, static function () use ($connection, $missionId): bool {
        if (repo_deploy_lock_mission($connection, $missionId) === null) {
            throw new RuntimeException(validator_text('validate.mission_not_found', 'Mission not found.'));
        }
        repo_deploy_assert_mission_idle($connection, $missionId);

        $stmt = $connection->prepare('DELETE FROM deploy_missions WHERE id = ?');
        $stmt->bind_param('i', $missionId);
        $stmt->execute();
        if ($stmt->affected_rows === 0) {
            throw new RuntimeException(validator_text('validate.mission_not_found', 'Mission not found.'));
        }

        return true;
    });
}

function createMission($missionName, $connection)
{
    // Legacy machine-API contract: returns bool so the desktop client keeps
    // receiving `true` on success. The portal uses repo_create_mission() when it
    // needs the new id and location fields.
    return repo_create_mission($connection, ['mission_name' => $missionName]) > 0;
}

/**
 * $creatorUserId stamps deploy_missions.mission_creator from the session. It is
 * never read from $missionData, so a request body can not forge it; callers
 * without a user (legacy machine API) leave the column empty.
 */
function repo_create_mission(mysqli $db, array $missionData, bool $requireLocation = false, ?int $creatorUserId = null): int
{
    $missionData += ['mission_status' => VIRTUSPHERE_MISSION_STATUS_DEFAULT];
    unset($missionData['mission_creator']);
    $values = repo_validate_mission_values($db, $missionData, 0, true, $requireLocation);
    $values['mission_creator'] = repo_creator_name($db, $creatorUserId);

    return repo_insert_from_values($db, 'deploy_missions', $values);
}

function updateMission($mysqli, $missionId, $missionData)
{
    $id = repo_id($missionId);
    if ($id === 0 || empty($missionData)) {
        return false;
    }

    $values = repo_allowed_columns($missionData, REPO_MISSION_EDITABLE_COLUMNS);

    if ($values === []) {
        return false;
    }

    $values = repo_validate_mission_values($mysqli, $values, $id, false);
    if ($values === []) {
        return false;
    }

    $sets = [];
    foreach (array_keys($values) as $column) {
        $sets[] = "`{$column}` = ?";
    }
    $sets[] = 'updated_at = NOW()';

    $params = array_values($values);
    $types = implode('', array_map('repo_bind_type', $params)) . 'i';
    $params[] = $id;
    $sql = 'UPDATE deploy_missions SET ' . implode(', ', $sets) . ' WHERE id = ?';
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    if ($stmt->affected_rows === 0 && repo_get_mission($mysqli, $id) === null) {
        throw new RuntimeException(validator_text('validate.mission_not_found', 'Mission not found.'));
    }

    return true;
}

function repo_get_mission(mysqli $db, int $missionId): ?array
{
    return repo_fetch_one($db, 'SELECT m.*, (SELECT COUNT(*) FROM deploy_vms v WHERE v.mission_id = m.id) AS vm_count FROM deploy_missions m WHERE m.id = ? LIMIT 1', 'i', [$missionId]);
}

// True when any VM of the mission is already known to MECM - renaming the
// mission would orphan its MECM collection (mission name = collection name).
function repo_mission_has_mecm_active_vms(mysqli $db, int $missionId): bool
{
    $submitted = VIRTUSPHERE_MECM_SYNC_SUBMITTED;
    $registered = VIRTUSPHERE_MECM_SYNC_REGISTERED;

    return repo_fetch_one($db, 'SELECT id FROM deploy_vms WHERE mission_id = ? AND mecm_sync_state IN (?, ?) LIMIT 1', 'iss', [$missionId, $submitted, $registered]) !== null;
}

function repo_update_mission_checked(mysqli $db, int $missionId, array $missionData, string $expectedUpdatedAt, bool $requireLocation = false): bool
{
    $mission = repo_get_mission($db, $missionId);
    if ($mission === null) {
        throw new RuntimeException(validator_text('validate.mission_not_found', 'Mission not found.'));
    }
    if ($expectedUpdatedAt !== '' && (string) $mission['updated_at'] !== $expectedUpdatedAt) {
        throw new RuntimeException(validator_text('validate.mission_stale', 'The mission was changed by someone else in the meantime. Please reload and save again.'));
    }

    $values = [];
    foreach (REPO_MISSION_EDITABLE_COLUMNS as $column) {
        if (array_key_exists($column, $missionData)) {
            $values[$column] = $missionData[$column];
        }
    }
    if ($values === []) {
        return true;
    }

    // Rename guard (E2, repo layer on purpose - page-level guards are
    // bypassable): once VMs are submitted/registered in MECM the mission name
    // is locked because it doubles as the MECM collection name.
    if (array_key_exists('mission_name', $values)
        && (string) $values['mission_name'] !== (string) $mission['mission_name']
        && repo_mission_has_mecm_active_vms($db, $missionId)) {
        $message = validator_text('validate.mission_rename_mecm_locked', 'Mission cannot be renamed: its name is the MECM collection name and VMs of this mission are already registered in MECM.');
        throw new ValidationException(['mission_name' => $message], $message);
    }

    $values = repo_validate_mission_values($db, $values, $missionId, false, $requireLocation);

    $sets = [];
    foreach (array_keys($values) as $column) {
        $sets[] = "`{$column}` = ?";
    }
    $sets[] = 'updated_at = NOW()';

    $params = array_values($values);
    $types = str_repeat('s', count($params)) . 'i';
    $params[] = $missionId;
    $stmt = $db->prepare('UPDATE deploy_missions SET ' . implode(', ', $sets) . ' WHERE id = ?');
    $stmt->bind_param($types, ...$params);

    return $stmt->execute();
}

function repo_clone_template_to_new_mission(mysqli $db, int $templateMissionId, string $targetMissionName, int $userId): array
{
    $targetMissionName = trim($targetMissionName);
    if ($targetMissionName === '') {
        $message = validator_text('validate.target_mission_name_required', 'Enter a target mission name.');
        throw new ValidationException(['mission_name' => $message], $message);
    }
    if (mission_name_is_template($targetMissionName)) {
        $message = validator_text('validate.target_mission_no_prefix', 'Target mission names must not start with the template prefix.');
        throw new ValidationException(['mission_name' => $message], $message);
    }

    $template = repo_get_mission($db, $templateMissionId);
    if ($template === null || !mission_name_is_template((string) $template['mission_name'])) {
        throw new RuntimeException(validator_text('validate.source_not_template', 'The source is not a template.'));
    }

    $targetValues = repo_validate_mission_values($db, [
        'mission_name' => $targetMissionName,
        'mission_status' => VIRTUSPHERE_MISSION_STATUS_DEFAULT,
    ] + repo_mission_copyable_values($template), 0, true);

    // Global name preflight (E2): report ALL colliding VM names before
    // cloning anything - the clone target is a real mission whose VM names
    // become MECM device names.
    $stmt = $db->prepare('SELECT vm_name FROM deploy_vms WHERE mission_id = ? ORDER BY vm_name');
    $stmt->bind_param('i', $templateMissionId);
    $stmt->execute();
    $conflicts = [];
    foreach (repo_fetch_all($stmt->get_result()) as $row) {
        $conflict = repo_vm_name_conflict_global($db, (string) $row['vm_name']);
        if ($conflict !== null) {
            $conflicts[] = (string) $row['vm_name'] . ' (' . (string) $conflict['mission_name'] . ')';
        }
    }
    if ($conflicts !== []) {
        $message = validator_text('validate.template_clone_name_conflicts', 'Cannot clone template: these VM names already exist in other missions: :names', ['names' => implode(', ', $conflicts)]);
        throw new ValidationException(['mission_name' => $message], $message);
    }

    return repo_transaction($db, static function () use ($db, $templateMissionId, $targetValues, $userId): array {
        // Instantiating a template creates new work: the operator owns the mission
        // and its VMs, not whoever authored the template.
        $operator = repo_creator_name($db, $userId);
        $targetValues['mission_creator'] = $operator;
        $targetMissionId = repo_insert_from_values($db, 'deploy_missions', $targetValues);

        $result = repo_clone_mission_vms($db, $templateMissionId, $targetMissionId, $userId, 'cloned from template', $operator);

        return ['target_mission_id' => $targetMissionId, 'created' => $result['created'], 'skipped' => $result['skipped']];
    });
}

function repo_save_mission_as_template(mysqli $db, int $sourceMissionId, string $targetTemplateName, int $userId): array
{
    $targetTemplateName = trim($targetTemplateName);
    if ($targetTemplateName === '') {
        $message = validator_text('validate.template_name_required', 'Enter a template name.');
        throw new ValidationException(['mission_name' => $message], $message);
    }
    if (!mission_name_is_template($targetTemplateName)) {
        $targetTemplateName = VIRTUSPHERE_TEMPLATE_PREFIX . $targetTemplateName;
    }

    $source = repo_get_mission($db, $sourceMissionId);
    if ($source === null || mission_name_is_template((string) $source['mission_name'])) {
        throw new RuntimeException(validator_text('validate.source_not_mission', 'The source is not a normal mission.'));
    }

    $targetValues = repo_validate_mission_values($db, [
        'mission_name' => $targetTemplateName,
        'mission_status' => VIRTUSPHERE_MISSION_STATUS_DEFAULT,
    ] + repo_mission_copyable_values($source), 0, true);

    return repo_transaction($db, static function () use ($db, $sourceMissionId, $targetValues, $userId): array {
        // The template row itself is authored by whoever captured it, but the
        // captured VMs keep the creator they were originally built by.
        $targetValues['mission_creator'] = repo_creator_name($db, $userId);
        $targetMissionId = repo_insert_from_values($db, 'deploy_missions', $targetValues);

        $result = repo_clone_mission_vms($db, $sourceMissionId, $targetMissionId, $userId, 'captured from mission');

        return ['target_mission_id' => $targetMissionId, 'created' => $result['created'], 'skipped' => $result['skipped']];
    });
}

/**
 * $creator: null keeps each source VM's own vm_creator (capturing a mission into
 * a template preserves authorship). A non-null name re-stamps every clone with it,
 * which is what instantiating a template does - those VMs are new work owned by
 * the operator, not by the person who wrote the template.
 */
function repo_clone_mission_vms(mysqli $db, int $sourceMissionId, int $targetMissionId, int $userId, string $reason = 'cloned from template', ?string $creator = null): array
{
    $stmt = $db->prepare('SELECT * FROM deploy_vms WHERE mission_id = ? ORDER BY vm_name');
    $stmt->bind_param('i', $sourceMissionId);
    $stmt->execute();
    $sourceVms = repo_fetch_all($stmt->get_result());

    $created = 0;
    $skipped = [];
    foreach ($sourceVms as $sourceVm) {
        $name = (string) $sourceVm['vm_name'];
        if (repo_vm_name_exists($db, $targetMissionId, $name)) {
            $skipped[] = $name;
            continue;
        }

        $values = [];
        foreach (REPO_VM_COLUMNS as $column) {
            $values[$column] = (string) ($sourceVm[$column] ?? '');
        }
        if ($creator !== null) {
            $values['vm_creator'] = $creator;
        }
        $values['mission_id'] = $targetMissionId;
        $values['vm_status'] = VIRTUSPHERE_STATUS_REGISTERED;
        $values['lifecycle_state'] = VIRTUSPHERE_LIFECYCLE_READY;
        $values['mecm_sync_state'] = VIRTUSPHERE_MECM_SYNC_NOT_READY;
        $values['updated'] = 0;

        $newVmId = repo_insert_from_values($db, 'deploy_vms', $values);
        $interfaces = repo_fetch_related($db, 'SELECT ip, subnet, gateway, dns1, dns2, vlan, mode, type FROM deploy_interfaces WHERE vm_id = ? ORDER BY id', (int) $sourceVm['id']);
        foreach ($interfaces as &$interface) {
            $interface['mac'] = '';
        }
        unset($interface);
        repo_replace_interfaces($db, $newVmId, $interfaces, false);

        $disks = repo_fetch_related($db, 'SELECT disk_name, disk_size, disk_type FROM deploy_disks WHERE vm_id = ? ORDER BY id', (int) $sourceVm['id']);
        repo_replace_disks($db, $newVmId, $disks);

        $packages = repo_fetch_related($db, 'SELECT package_id AS id FROM deploy_vm_packages WHERE vm_id = ? ORDER BY package_id', (int) $sourceVm['id']);
        repo_replace_packages($db, $newVmId, $packages);
        repo_record_vm_status_event($db, $newVmId, VIRTUSPHERE_LIFECYCLE_READY, VIRTUSPHERE_MECM_SYNC_NOT_READY, VIRTUSPHERE_STATUS_REGISTERED, $reason, $userId);
        $created++;
    }

    return ['created' => $created, 'skipped' => $skipped];
}
