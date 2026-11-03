<?php

declare(strict_types=1);

/**
 * Rollout hostname, revision fence, delete tombstone and the global claim that
 * makes the effective MECM name unique (Etappe 14D, ADR-0043).
 *
 * Everything here assumes the caller already holds the mission lock and the VM
 * row (the established deploy lock order: mission first, then VM/claim rows).
 * These functions never open their own transaction, because each of them is one
 * half of a change whose other half lives in the caller: a snapshot that moved
 * without its claim, or a claim without its snapshot, is exactly the state that
 * lets two machines answer to one name.
 */

require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../defaults.php';
require_once __DIR__ . '/../mecm_hostname.php';
require_once __DIR__ . '/../validate.php';
require_once __DIR__ . '/helpers.php';

/**
 * The rollout row of one VM, or null when it does not exist.
 *
 * @return array{id:int, mission_id:int, vm_name:string, vm_hostname:string, mecm_id:?string, mecm_rollout_hostname:?string, mecm_rollout_revision:?int, mecm_previous_id:?string, is_template:bool}|null
 */
function repo_vm_rollout_state(mysqli $db, int $vmId, bool $lock = false): ?array
{
    if ($vmId <= 0) {
        return null;
    }

    $row = repo_fetch_one(
        $db,
        'SELECT v.id, v.mission_id, v.vm_name, v.vm_hostname, v.mecm_id,
                v.mecm_rollout_hostname, v.mecm_rollout_revision, v.mecm_previous_id,
                m.mission_name
           FROM deploy_vms v
           INNER JOIN deploy_missions m ON m.id = v.mission_id
          WHERE v.id = ? LIMIT 1' . ($lock ? ' FOR UPDATE' : ''),
        'i',
        [$vmId]
    );
    if ($row === null) {
        return null;
    }

    return [
        'id' => (int) $row['id'],
        'mission_id' => (int) $row['mission_id'],
        'vm_name' => (string) $row['vm_name'],
        'vm_hostname' => (string) $row['vm_hostname'],
        'mecm_id' => $row['mecm_id'] === null ? null : (string) $row['mecm_id'],
        'mecm_rollout_hostname' => $row['mecm_rollout_hostname'] === null ? null : (string) $row['mecm_rollout_hostname'],
        'mecm_rollout_revision' => $row['mecm_rollout_revision'] === null ? null : (int) $row['mecm_rollout_revision'],
        'mecm_previous_id' => $row['mecm_previous_id'] === null ? null : (string) $row['mecm_previous_id'],
        'is_template' => mission_name_is_template((string) $row['mission_name']),
    ];
}

/**
 * Who holds a hostname key, if anybody. Read under a row lock so the answer is
 * still true when the caller acts on it.
 *
 * @return array{vm_id:int, vm_name:string, mission_id:int, mission_name:string}|null
 */
function repo_vm_hostname_claim_owner(mysqli $db, string $hostnameKey, int $excludeVmId = 0, bool $lock = false): ?array
{
    if ($hostnameKey === '') {
        return null;
    }

    // The lock is taken on the claim row itself and NOT through the join, so a
    // key nobody holds still leaves the gap lock that blocks a concurrent
    // insert of the same key. That gap is the whole race protection; without it
    // two parallel mission creates both read "free".
    $claim = repo_fetch_one(
        $db,
        'SELECT vm_id FROM deploy_vm_hostname_claims WHERE hostname_key = ?' . ($lock ? ' FOR UPDATE' : ''),
        's',
        [$hostnameKey]
    );
    if ($claim === null) {
        return null;
    }

    $vmId = (int) $claim['vm_id'];
    if ($excludeVmId > 0 && $vmId === $excludeVmId) {
        return null;
    }

    $row = repo_fetch_one(
        $db,
        'SELECT v.id, v.vm_name, v.mission_id, m.mission_name
           FROM deploy_vms v INNER JOIN deploy_missions m ON m.id = v.mission_id
          WHERE v.id = ? LIMIT 1',
        'i',
        [$vmId]
    );
    if ($row === null) {
        // The FK cascades, so this cannot happen through the portal; treat a
        // dangling claim as unheld rather than as a phantom blocker.
        return null;
    }

    return [
        'vm_id' => $vmId,
        'vm_name' => (string) $row['vm_name'],
        'mission_id' => (int) $row['mission_id'],
        'mission_name' => (string) $row['mission_name'],
    ];
}

/**
 * Brings the claim rows of ONE VM in line with the two names it currently
 * occupies. This is the only writer of `deploy_vm_hostname_claims`.
 *
 * The desired and the frozen rollout name are usually the same value and share
 * one row carrying both flags. They diverge exactly while an edit landed on a
 * VM whose snapshot is already handed to MECM, and then the VM legitimately
 * holds two keys: nobody else may take the old name until the reset releases
 * it, because the old Windows machine still answers to it.
 *
 * A name that cannot start a rollout (empty, too long, dotted, a grandfathered
 * legacy value) gets NO claim. It is not reserved for anybody, and the reset
 * and first-import paths refuse it separately with their own reason.
 *
 * @throws ValidationException when another VM holds one of the keys.
 */
function repo_vm_hostname_claims_sync(mysqli $db, int $vmId, bool $isTemplate, ?string $desiredHostname, ?string $rolloutHostname): void
{
    if ($vmId <= 0) {
        throw new InvalidArgumentException('VM is required.');
    }

    // Templates hold nothing: they have no rollout, and a claim on a template
    // would reserve a name for a VM that can never be handed to MECM while
    // blocking the real mission cloned from it.
    $wanted = [];
    if (!$isTemplate) {
        $desiredKey = mecm_hostname_is_rollout_valid($desiredHostname) ? mecm_hostname_key($desiredHostname) : '';
        $rolloutKey = mecm_hostname_is_rollout_valid($rolloutHostname) ? mecm_hostname_key($rolloutHostname) : '';
        if ($desiredKey !== '') {
            $wanted[$desiredKey] = ['desired' => 1, 'rollout' => 0];
        }
        if ($rolloutKey !== '') {
            $wanted[$rolloutKey]['desired'] = $wanted[$rolloutKey]['desired'] ?? 0;
            $wanted[$rolloutKey]['rollout'] = 1;
        }
    }

    // Release first, so a VM that renamed A -> B inside one transaction does not
    // trip over its own stale row, and so the key it gives up is free for
    // another VM in the same batch.
    $existing = [];
    $result = $db->prepare('SELECT hostname_key FROM deploy_vm_hostname_claims WHERE vm_id = ? FOR UPDATE');
    $result->bind_param('i', $vmId);
    $result->execute();
    foreach ($result->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $existing[] = (string) $row['hostname_key'];
    }
    foreach ($existing as $key) {
        if (!isset($wanted[$key])) {
            repo_execute($db, 'DELETE FROM deploy_vm_hostname_claims WHERE hostname_key = ? AND vm_id = ?', 'si', [$key, $vmId]);
        }
    }

    foreach ($wanted as $key => $flags) {
        $owner = repo_vm_hostname_claim_owner($db, $key, $vmId, true);
        if ($owner !== null) {
            throw repo_vm_hostname_claim_conflict($owner);
        }

        // The FOR UPDATE above left a lock (a gap lock when the key was free),
        // so this insert cannot race a second writer. The duplicate-key catch is
        // the belt to that brace: under READ COMMITTED there is no gap lock, and
        // a wrong isolation level must surface as the same operator sentence,
        // not as a raw SQL error page.
        try {
            repo_execute(
                $db,
                'INSERT INTO deploy_vm_hostname_claims (hostname_key, vm_id, desired_claim, rollout_claim)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE desired_claim = VALUES(desired_claim), rollout_claim = VALUES(rollout_claim)',
                'siii',
                [$key, $vmId, $flags['desired'], $flags['rollout']]
            );
        } catch (mysqli_sql_exception $exception) {
            if ($exception->getCode() !== 1062) {
                throw $exception;
            }
            $owner = repo_vm_hostname_claim_owner($db, $key, $vmId);
            throw repo_vm_hostname_claim_conflict($owner ?? ['vm_name' => '', 'mission_name' => '']);
        }
    }
}

/**
 * The one wording for "somebody else already owns this Windows name". It names
 * the holder, because "already taken" without a target sends the operator
 * hunting through every mission.
 *
 * @param array{vm_name?:string, mission_name?:string} $owner
 */
function repo_vm_hostname_claim_conflict(array $owner): ValidationException
{
    $message = validator_text(
        'validate.vm_hostname_taken_global',
        'Windows hostname is already used by VM ":vm" in mission ":mission". A Windows computer name has to be unique, because MECM imports it as the device name.',
        ['vm' => (string) ($owner['vm_name'] ?? ''), 'mission' => (string) ($owner['mission_name'] ?? '')]
    );

    return new ValidationException(['vm_hostname' => $message], $message);
}

/**
 * The snapshot/revision half of a VM write, decided from the row's own state.
 *
 * The rule the whole stage rests on: while `mecm_id IS NULL` nothing has been
 * handed over, so the snapshot simply follows the desired value; once a
 * ResourceID is bound the snapshot FREEZES, because MECM, Windows and the
 * client all already act on it and a portal correction must not reinterpret a
 * device behind their back. Only the explicit reset moves it again.
 *
 * The revision rises exactly with a changed normalised identity. A pure change
 * of spelling is the same machine, needs no reset and must not invalidate the
 * callbacks of a rollout that is already in flight.
 *
 * @param array{mecm_id:?string, mecm_rollout_hostname:?string, mecm_rollout_revision:?int, is_template:bool} $state
 * @return array{mecm_rollout_hostname:?string, mecm_rollout_revision:?int}
 */
function repo_vm_rollout_values_for_edit(array $state, string $desiredHostname): array
{
    if ($state['is_template']) {
        // A template has no rollout at all. Being captured or renamed into one
        // drops the runtime rather than freezing it, so a clone starts clean.
        return ['mecm_rollout_hostname' => null, 'mecm_rollout_revision' => null];
    }

    $revision = $state['mecm_rollout_revision'] ?? 0;
    if ($revision <= 0) {
        // First time this row has a rollout: a fresh VM, or a template that just
        // became a real mission. Revision 1 with no tombstone is exactly the
        // state the pre-cutover compatibility branch accepts.
        return [
            'mecm_rollout_hostname' => $desiredHostname,
            'mecm_rollout_revision' => VIRTUSPHERE_MECM_ROLLOUT_REVISION_INITIAL,
        ];
    }

    if ($state['mecm_id'] !== null && $state['mecm_id'] !== '') {
        // Handed over: frozen. The desired value still changes, the operator
        // still sees it, and the reset is the one thing that activates it.
        return [
            'mecm_rollout_hostname' => $state['mecm_rollout_hostname'],
            'mecm_rollout_revision' => $revision,
        ];
    }

    // Pending: the snapshot follows, and the revision rises only when the
    // identity really changed.
    $identityChanged = !mecm_hostname_same($state['mecm_rollout_hostname'], $desiredHostname);

    return [
        'mecm_rollout_hostname' => $desiredHostname,
        'mecm_rollout_revision' => $identityChanged ? $revision + 1 : $revision,
    ];
}

/**
 * Whether an edit changes the rollout IDENTITY of a VM, which is the only kind
 * of hostname edit an active deploy job blocks. Renaming the machine while a
 * playbook is creating it would leave the job carrying a name the row no longer
 * has; every other edit stays as allowed as it is today.
 *
 * @param array{mecm_rollout_hostname:?string, is_template:bool} $state
 */
function repo_vm_rollout_edit_changes_identity(array $state, string $desiredHostname, string $storedHostname): bool
{
    if ($state['is_template']) {
        return false;
    }

    // Only the desired value's OWN change counts. Comparing against the frozen
    // snapshot instead would block every unrelated edit of a VM whose rollout
    // is already handed over, which is precisely the "do not re-lock what is
    // allowed today" the stage forbids.
    return !mecm_hostname_same($storedHostname, $desiredHostname);
}

/**
 * Re-runs the hostname claim decision for every VM of one mission after its
 * template status changed. Called only from inside the mission transaction that
 * changed it, with the mission row already locked.
 *
 * Becoming a real mission also initialises the rollout runtime the VMs never had
 * as template rows: a clone gets that from repo_clone_mission_vms, a renamed
 * template would otherwise carry NULL snapshots into a live rollout and hand
 * MECM nothing at all.
 */
function repo_mission_reconcile_hostname_claims(mysqli $db, int $missionId, bool $isTemplate): void
{
    $stmt = $db->prepare('SELECT id, vm_hostname FROM deploy_vms WHERE mission_id = ? ORDER BY id FOR UPDATE');
    $stmt->bind_param('i', $missionId);
    $stmt->execute();
    $vms = repo_fetch_all($stmt->get_result());

    foreach ($vms as $vm) {
        $vmId = (int) $vm['id'];
        $hostname = (string) $vm['vm_hostname'];

        $state = repo_vm_rollout_state($db, $vmId);
        if ($state === null) {
            continue;
        }
        // The stored row still carries the OLD template status here (the mission
        // update above changed the name, not this cached read), so the new one
        // has to overwrite rather than merely fill in - `+` would keep the old.
        $state['is_template'] = $isTemplate;
        $rollout = repo_vm_rollout_values_for_edit($state, $hostname);
        repo_execute(
            $db,
            'UPDATE deploy_vms SET mecm_rollout_hostname = ?, mecm_rollout_revision = ?, updated_at = updated_at WHERE id = ?',
            'sii',
            [$rollout['mecm_rollout_hostname'], $rollout['mecm_rollout_revision'], $vmId]
        );
        repo_vm_hostname_claims_sync($db, $vmId, $isTemplate, $hostname, $rollout['mecm_rollout_hostname']);
    }
}
