<?php

declare(strict_types=1);

/**
 * The MECM-ID reset: the ONE point at which a new rollout name takes effect
 * (Etappe 14D, ADR-0043).
 *
 * Its own module because it is its own domain, not because vms_operations.php
 * grew: everything here answers one question, "may this VM be handed to MECM
 * again, and under which name". The closed refusal vocabulary, the single-row
 * and bulk drivers and the transaction that moves snapshot, revision, tombstone
 * and hostname claim together belong to that question and to nothing else.
 *
 * Loaded through the lib/repo/vms.php facade like every other VM-repo module.
 */

/**
 * A MECM-ID reset that a rule refused, carrying its reason as a CODE.
 *
 * The reason travels as a token rather than as a sentence for two reasons that
 * both bit already: the bulk path used to recognise "no MAC" by searching the
 * exception TEXT with str_contains, so a reworded message would have silently
 * reclassified every refusal as a generic error; and the portal has to render
 * the reason in the operator's language, which a message built in the repo
 * cannot do.
 *
 * The vocabulary is closed. `VIRTUSPHERE_MECM_RESET_BLOCKERS` is the SSoT and
 * the portal maps it with an exhaustive match, so a new blocker fails the build
 * instead of reaching a page as an untranslated token.
 */
final class RepoMecmResetBlocked extends RuntimeException
{
    public function __construct(private readonly string $reasonCode)
    {
        parent::__construct('MECM ID reset blocked: ' . $reasonCode);
    }

    public function reasonCode(): string
    {
        return $this->reasonCode;
    }
}

/**
 * Bulk MECM-ID reset. Runs the SAME repository guard per VM as the single-row
 * action, so the two cannot drift into different rules for the same click one
 * row over.
 *
 * A refused VM is skipped with its closed reason and NOTHING of it is written:
 * the guard throws before the update, inside that VM's own transaction, so no
 * VM is ever half converted. An unchanged idempotent VM counts as skipped with
 * `already_pending` rather than as done, because reporting it as reset would
 * promise an activation that did not happen.
 *
 * @param int[] $vmIds
 * @return array{done:int, skipped:array<int,array{vm_name:string,reason:string}>}
 */
function repo_bulk_reset_mecm_ids(mysqli $db, int $missionId, array $vmIds, ?int $userId = null): array
{
    $result = ['done' => 0, 'skipped' => []];
    foreach ($vmIds as $vmId) {
        $vmId = (int) $vmId;
        $name = (string) (repo_scalar($db, 'SELECT vm_name FROM deploy_vms WHERE id = ? AND mission_id = ? LIMIT 1', 'ii', [$vmId, $missionId]) ?? '');
        if ($name === '') {
            continue;
        }
        try {
            $outcome = repo_reset_vm_mecm_id($db, $missionId, $vmId, $userId);
            if ($outcome['changed']) {
                $result['done']++;
            } else {
                $result['skipped'][] = ['vm_name' => $name, 'reason' => $outcome['reason']];
            }
        } catch (RepoMecmResetBlocked $blocked) {
            $result['skipped'][] = ['vm_name' => $name, 'reason' => $blocked->reasonCode()];
        } catch (Throwable) {
            $result['skipped'][] = ['vm_name' => $name, 'reason' => 'error'];
        }
    }

    return $result;
}

function repo_vm_has_imported_mac(mysqli $db, int $vmId): bool
{
    if ($vmId <= 0) {
        return false;
    }

    return (int) repo_scalar($db, "SELECT COUNT(*) FROM deploy_interfaces WHERE vm_id = ? AND mac IS NOT NULL AND mac <> ''", 'i', [$vmId]) > 0;
}

/**
 * The ONE activation point for a new MECM rollout (Etappe 14D, ADR-0043).
 *
 * It does four things atomically, and separating any of them would break a
 * different promise: it moves the frozen snapshot to the current desired name,
 * turns the old ResourceID into a delete tombstone, raises the revision so the
 * previous rollout's late callbacks can no longer close this queue, and moves
 * the hostname claim from the old name to the new one. Releasing the old claim
 * outside this commit would let another VM take a name the old Windows machine
 * still answers to.
 *
 * VirtuSphere deletes nothing in MECM. The tombstone keeps the next hand-off
 * fail-closed until the administrator has removed the old device there.
 *
 * @return array{changed:bool, reason:string, previous_id:?string, previous_hostname:?string, hostname:string, revision:int}
 */
function repo_reset_vm_mecm_id(mysqli $db, int $missionId, int $vmId, ?int $userId = null): array
{
    if ($missionId <= 0 || $vmId <= 0) {
        throw new InvalidArgumentException('Mission and VM are required.');
    }

    return repo_transaction($db, static function () use ($db, $missionId, $vmId, $userId): array {
        // Established deploy lock order: mission first, then the VM and its
        // claim rows. Taking the VM first would deadlock against every deploy
        // path, which locks the mission on the way in.
        if (repo_deploy_lock_mission($db, $missionId) === null) {
            throw new RuntimeException('Mission not found.');
        }
        $state = repo_vm_rollout_state($db, $vmId, true);
        if ($state === null || $state['mission_id'] !== $missionId) {
            throw new RuntimeException('VM not found.');
        }

        // Every refusal is a closed reason the portal maps to its own sentence.
        // A free message here would end up in front of an operator through
        // portal_error_message() and could not be translated.
        if ($state['is_template']) {
            throw new RepoMecmResetBlocked('template');
        }
        if (repo_deploy_active_job_exists($db, $missionId)) {
            throw new RepoMecmResetBlocked('active_job');
        }
        if (!repo_vm_has_imported_mac($db, $vmId)) {
            throw new RepoMecmResetBlocked('no_mac');
        }
        // A grandfathered legacy hostname may keep blocking nothing else, but it
        // must never be ACTIVATED: the client's rename phase would truncate it
        // and the machine would answer to a name no portal row carries.
        if (!mecm_hostname_is_rollout_valid($state['vm_hostname'])) {
            throw new RepoMecmResetBlocked('invalid_hostname');
        }
        // A pending tombstone does NOT block the reset, and that is a decision
        // (2026-09-03), not an omission.
        //
        // The fail-closed protection it exists for lives where the hand-off
        // happens: the device sync refuses to import while the old ResourceID
        // is still in MECM (`previous_resource_present`). Blocking here as well
        // only looked stricter. What it actually did was lock the operator out
        // of the most likely correction: reset, notice the hostname was wrong,
        // fix it, and now the second reset is refused until a rollout that
        // cannot happen has happened. The sentence even sent them to delete a
        // device they may have deleted already, because a tombstone is this
        // portal's BELIEF about MECM, never a fact about it.
        //
        // What the blocker really protected is one line further down: the write
        // used to store `mecm_id` as the new tombstone, and after the first
        // reset that is NULL, so a second reset silently destroyed the
        // tombstone. Keeping the existing one fixes that at the cause.
        $existingTombstone = $state['mecm_previous_id'];

        // Idempotent second click: the same revision is already waiting without
        // a ResourceID and the snapshot already carries the desired name. No
        // second status event, no second audit row, no revision bump - a repeat
        // click must not invalidate the callbacks of the rollout it just armed.
        $desired = $state['vm_hostname'];
        if (($state['mecm_id'] === null || $state['mecm_id'] === '') && mecm_hostname_same($state['mecm_rollout_hostname'], $desired)) {
            return [
                'changed' => false,
                'reason' => 'already_pending',
                'previous_id' => null,
                'previous_hostname' => $state['mecm_rollout_hostname'],
                'hostname' => $desired,
                'revision' => (int) ($state['mecm_rollout_revision'] ?? VIRTUSPHERE_MECM_ROLLOUT_REVISION_INITIAL),
            ];
        }

        // The binding being released becomes the tombstone. When there is none
        // to release (a re-arm of a rollout that never bound), the tombstone
        // that is already waiting is KEPT: it names a device that is still out
        // there, and only a successful new binding may clear it. Overwriting it
        // with the NULL `mecm_id` of an unbound VM is exactly how the delete
        // reminder used to disappear on the second click.
        $previousId = $state['mecm_id'] !== null && $state['mecm_id'] !== ''
            ? $state['mecm_id']
            : $existingTombstone;
        $previousHostname = $state['mecm_rollout_hostname'];
        $revision = (int) ($state['mecm_rollout_revision'] ?? 0) + 1;
        $lifecycleState = VIRTUSPHERE_LIFECYCLE_DEPLOYED;
        $mecmSyncState = VIRTUSPHERE_MECM_SYNC_PENDING;
        $legacyStatus = VIRTUSPHERE_STATUS_DEPLOYED;

        $stmt = $db->prepare(
            'UPDATE deploy_vms
                SET lifecycle_state = ?, mecm_sync_state = ?, vm_status = ?, updated = 1,
                    mecm_id = NULL, mecm_previous_id = ?,
                    mecm_rollout_hostname = ?, mecm_rollout_revision = ?,
                    mecm_pending_since = NOW(), os_install_watch_started_at = NULL, updated_at = NOW()
              WHERE id = ? AND mission_id = ?'
        );
        $stmt->bind_param('sssssiii', $lifecycleState, $mecmSyncState, $legacyStatus, $previousId, $desired, $revision, $vmId, $missionId);
        $stmt->execute();

        // The old rollout name is released HERE and nowhere earlier, which is
        // what makes "another VM may take the old name only after the reset"
        // true rather than merely intended.
        repo_vm_hostname_claims_sync($db, $vmId, false, $desired, $desired);

        repo_record_vm_status_event($db, $vmId, $lifecycleState, $mecmSyncState, $legacyStatus, 'mecm id reset from portal', $userId);

        return [
            'changed' => true,
            'reason' => '',
            'previous_id' => $previousId,
            'previous_hostname' => $previousHostname,
            'hostname' => $desired,
            'revision' => $revision,
        ];
    });
}

