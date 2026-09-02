<?php

declare(strict_types=1);

require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../deploy_constants.php';
require_once __DIR__ . '/../mac_import.php';
require_once __DIR__ . '/../validate.php';
require_once __DIR__ . '/vm_identity.php';
require_once __DIR__ . '/vm_network.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/deploy_job_input.php';
require_once __DIR__ . '/deploy_job_queries.php';
require_once __DIR__ . '/deploy_job_guards.php';
require_once __DIR__ . '/deploy_job_worker.php';
require_once __DIR__ . '/deploy_job_retry.php';
require_once __DIR__ . '/deploy_create_results.php';
require_once __DIR__ . '/../ansible_command_modes.php';

/**
 * Enqueue paths: the single mission job, its retry, the staggered group and the
 * mission-less system job (ESXi inventory pulls).
 *
 * All four run inside repo_transaction() and take the same lock order through
 * lib/repo/deploy_job_guards.php. The one-active-job-per-mission guard is a
 * locking read for a reason documented there; the group path checks it once for
 * the whole group.
 */

/**
 * @param list<array<string,mixed>>|null $createRetrySource The source job's
 *        create rows when this job is the retry of a create-tracked job. It
 *        replaces the fresh materialization so a confirmed success becomes a
 *        verify_skip unit bound to the row that proved it; there is still
 *        exactly ONE materialization per job (Etappe 14B-F).
 */
function repo_create_deploy_job(mysqli $db, int $missionId, int $userId, int $esxiCredentialId, int $ansibleCredentialId, array $payloadData, ?string $scheduledAtUtc = null, ?array $createRetrySource = null): int
{
    if ($missionId <= 0 || $userId <= 0 || $esxiCredentialId <= 0 || $ansibleCredentialId <= 0) {
        throw new InvalidArgumentException('Mission, user, ESXi credential and Ansible credential are required.');
    }

    // A mission job may only carry a mode the deploy form offers.
    deploy_job_normalize_mission_mode((string) ($payloadData['mode'] ?? VIRTUSPHERE_DEPLOY_MODE_FULL));
    $payload = deploy_job_payload($payloadData);

    return repo_transaction($db, static function () use ($db, $missionId, $userId, $esxiCredentialId, $ansibleCredentialId, $payload, $scheduledAtUtc, $createRetrySource): int {
        repo_deploy_assert_user_exists($db, $userId);

        $stmt = $db->prepare('SELECT id, mission_name, wds_vlan, hypervisor_datastorage, hypervisor_datacenter FROM deploy_missions WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('i', $missionId);
        $stmt->execute();
        $mission = $stmt->get_result()->fetch_assoc();
        if (!$mission) {
            throw new RuntimeException('Mission not found.');
        }
        repo_deploy_assert_mission_ready($db, $mission, $esxiCredentialId, (string) $payload['mode']);

        // Keep only VM ids that actually belong to this mission; drop the rest.
        // No selection at all means "whole mission"; a selection that filters down
        // to nothing (its VMs were deleted since the form was rendered) throws
        // rather than silently widening to the whole mission.
        $payload['vm_ids'] = repo_deploy_filter_mission_vm_ids($db, $missionId, $payload['vm_ids']);

        // A create-capable job resolves "whole mission" HERE instead of leaving
        // it to the worker (Etappe 14B). "Everything, decided later" means a VM
        // added between queueing and running silently joins a job nobody chose
        // it for, and after a scheduled delay that gap is hours wide. The
        // resolved list becomes both the payload and the materialized units, so
        // the job, its scope and its progress rows describe one selection.
        $createSelection = [];
        if (ansible_mode_creates_vms((string) $payload['mode'])) {
            $createSelection = deploy_create_resolve_selection($db, $missionId, $payload['vm_ids']);
            if ($createSelection === []) {
                // repo_deploy_assert_mission_ready() already refused an empty
                // mission above, so this is the narrower case: a selection that
                // resolved to nothing. Same sentence on purpose, because it is
                // the same thing to the operator and it is already localized.
                throw new RuntimeException('Mission has no VMs to deploy.');
            }
            $payload['vm_ids'] = array_map(static fn (array $vm): int => $vm['id'], $createSelection);
        }
        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);

        repo_deploy_assert_credential_type($db, $esxiCredentialId, VIRTUSPHERE_CREDENTIAL_TYPE_ESXI);
        repo_deploy_assert_credential_type($db, $ansibleCredentialId, VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE);
        repo_deploy_assert_no_vm_identity_conflicts($db, $missionId, $esxiCredentialId, $payload['vm_ids']);

        if (repo_deploy_active_job_exists($db, $missionId)) {
            throw new RuntimeException('This mission already has an active deploy job.');
        }
        repo_vm_network_assert_deploy_ready($db, $missionId, $payload['vm_ids'], (string) ($mission['wds_vlan'] ?? ''), (string) $payload['mode']);

        $correlationId = virtusphere_correlation_id();
        $stmt = $db->prepare('INSERT INTO deploy_jobs (mission_id, user_id, payload_json, credential_esxi_id, credential_ansible_id, scheduled_at, correlation_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('iisiiss', $missionId, $userId, $payloadJson, $esxiCredentialId, $ansibleCredentialId, $scheduledAtUtc, $correlationId);
        $stmt->execute();
        $jobId = (int) $db->insert_id;
        if ($createRetrySource !== null) {
            // Same transaction as the job row, and the same single
            // materialization: the retry plan decides per unit whether it is a
            // fresh create or the live verification of an earlier success.
            repo_deploy_create_materialize_retry($db, $jobId, $createRetrySource);
        } elseif ($createSelection !== []) {
            // Same transaction as the job row: a job with half its units would
            // look like one that had already processed the rest.
            repo_deploy_create_materialize($db, $jobId, $createSelection);
        }
        $logSuffix = $scheduledAtUtc !== null ? ' scheduled for ' . $scheduledAtUtc . ' UTC' : '';
        repo_insert_deploy_job_log_unlocked($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'Deploy job queued: ' . deploy_job_payload_summary($payloadJson) . $logSuffix);

        return $jobId;
    });
}

/**
 * Re-queues a retryable mission job as a NEW job with the old job's credential
 * snapshot. Runs immediately (no scheduled_at or group_id carry-over: "retry"
 * means "run it again now") and is attributed to the retrying user, not the
 * original author. The payload is the old one, unless deploy_job_retry_plan()
 * turns the retry into an export-only follow-up (partial jobs and diverged
 * failed jobs). Every create-time guard fires again via
 * repo_create_deploy_job(): one-active-job-per-mission, mission readiness,
 * VM-id filtering and the credential type asserts, so a mission or credential
 * deleted since the original run throws instead of half-queuing.
 */
function repo_retry_deploy_job(mysqli $db, int $jobId, int $userId): int
{
    if ($jobId <= 0 || $userId <= 0) {
        throw new InvalidArgumentException('Job and user are required.');
    }

    return repo_transaction($db, static function () use ($db, $jobId, $userId): int {
        $evaluation = deploy_retry_blockers($db, $jobId, true);
        if (!$evaluation['allowed']) {
            throw new DeployRetryBlockedException($evaluation);
        }
        $job = repo_deploy_job($db, $jobId);
        if ($job === null) {
            throw new RuntimeException('Deploy job not found.');
        }
        $missionId = $job['mission_id'] !== null ? (int) $job['mission_id'] : null;
        if (!deploy_job_is_retryable((string) $job['status'], $missionId)) {
            throw new RuntimeException('Only failed, cancelled or partial mission jobs can be retried.');
        }

        $payload = json_decode((string) ($job['payload_json'] ?? ''), true);
        $payload = is_array($payload) ? $payload : [];
        $plan = deploy_job_retry_plan(
            (string) $job['status'],
            mac_import_decode_result(isset($job['result_json']) ? (string) $job['result_json'] : null),
            deploy_job_normalize_vm_ids($payload['vm_ids'] ?? [])
        );
        if ($plan !== null) {
            $payload['mode'] = $plan['mode'];
            $payload['vm_ids'] = $plan['vm_ids'];
        }

        // The per-VM create retry (Etappe 14B-F, plan section 10.3). The WHOLE
        // original selection travels into the new job, not the failed part of
        // it: a full pipeline has to power-cycle, export and start every VM it
        // was asked for, including the ones the first job already created.
        // Those become verify_skip units - the work of proving live that the
        // earlier success still holds - rather than a second create.
        //
        // A source job whose retry no longer creates anything (the export-only
        // follow-up above) is deliberately untouched here: it materializes no
        // create units and claims nothing about them.
        $createRetrySource = null;
        $sourceCreateRows = repo_deploy_create_results($db, $jobId);
        if ($sourceCreateRows !== [] && ansible_mode_creates_vms((string) $payload['mode'])) {
            $createRetrySource = $sourceCreateRows;
            $payload['vm_ids'] = deploy_create_retry_vm_ids($sourceCreateRows);
        }

        $newJobId = repo_create_deploy_job(
            $db,
            (int) $missionId,
            $userId,
            (int) $job['credential_esxi_id'],
            (int) $job['credential_ansible_id'],
            $payload,
            null,
            $createRetrySource
        );
        $note = 'Retry of deploy job ' . $jobId;
        if ($plan !== null) {
            $note .= ' (export-only: ' . ($plan['scope'] === 'failed_vms' ? count($plan['vm_ids']) . ' failed VMs' : 'original selection') . ')';
        }
        if ($createRetrySource !== null) {
            $createPlan = deploy_create_retry_plan($createRetrySource);
            $note .= ' (create: ' . count($createPlan['verify']) . ' confirmed VM(s) to verify, '
                . count($createPlan['create']) . ' to create)';
        }
        // ADR-0032: the retry runs under a NEW correlation id (the retrying
        // request's); this line is the deliberate link back to the old trace.
        $oldCorrelation = trim((string) ($job['correlation_id'] ?? ''));
        if ($oldCorrelation !== '') {
            $note .= ' [correlation ' . $oldCorrelation . ']';
        }
        repo_insert_deploy_job_log_unlocked($db, $newJobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, $note);

        return $newJobId;
    });
}

/**
 * Enqueues a staggered group: one job per VM, same group_id, each scheduled
 * offset by $staggerMinutes from $baseUtc (or from now when $baseUtc is null).
 * The single-active-per-mission guard is checked ONCE for the whole group.
 *
 * @return array{group_id:string, count:int, schedule:array<int,array{vm_id:int,vm_name:string,scheduled_at:string}>}
 */
function repo_enqueue_deploy_group(mysqli $db, int $missionId, int $userId, int $esxiCredentialId, int $ansibleCredentialId, array $payloadData, ?string $baseUtc, int $staggerMinutes): array
{
    if ($missionId <= 0 || $userId <= 0 || $esxiCredentialId <= 0 || $ansibleCredentialId <= 0) {
        throw new InvalidArgumentException('Mission, user, ESXi credential and Ansible credential are required.');
    }
    if ($staggerMinutes < VIRTUSPHERE_DEPLOY_STAGGER_MIN || $staggerMinutes > VIRTUSPHERE_DEPLOY_STAGGER_MAX) {
        throw new InvalidArgumentException('Stagger interval out of range.');
    }

    $groupMode = deploy_job_normalize_mission_mode((string) ($payloadData['mode'] ?? VIRTUSPHERE_DEPLOY_MODE_FULL));
    // Staggering spreads a power operation over time. A config write has nothing
    // to spread: staggering `autostart` would queue one job per VM, each
    // rewriting the host's defaults. deploy_parse_schedule() refuses it too, but
    // that guard sits on the page and pages are bypassable.
    if (!in_array($groupMode, VIRTUSPHERE_DEPLOY_STAGGER_MODES, true)) {
        throw new InvalidArgumentException('Mode ' . $groupMode . ' cannot be staggered.');
    }
    $basePayload = deploy_job_payload($payloadData);

    return repo_transaction($db, static function () use ($db, $missionId, $userId, $esxiCredentialId, $ansibleCredentialId, $basePayload, $baseUtc, $staggerMinutes): array {
        repo_deploy_assert_user_exists($db, $userId);

        $stmt = $db->prepare('SELECT id, mission_name, wds_vlan, hypervisor_datastorage, hypervisor_datacenter FROM deploy_missions WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('i', $missionId);
        $stmt->execute();
        $mission = $stmt->get_result()->fetch_assoc();
        if (!$mission) {
            throw new RuntimeException('Mission not found.');
        }
        repo_deploy_assert_mission_ready($db, $mission, $esxiCredentialId, (string) $basePayload['mode']);
        repo_deploy_assert_credential_type($db, $esxiCredentialId, VIRTUSPHERE_CREDENTIAL_TYPE_ESXI);
        repo_deploy_assert_credential_type($db, $ansibleCredentialId, VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE);

        if (repo_deploy_active_job_exists($db, $missionId)) {
            throw new RuntimeException('This mission already has an active deploy job.');
        }

        // Resolve the ordered VM list (shared with the preview so both agree).
        $vms = repo_deploy_group_vm_list($db, $missionId, $basePayload['vm_ids']);
        if ($vms === []) {
            throw new RuntimeException('Mission has no VMs to deploy.');
        }
        repo_deploy_assert_no_vm_identity_conflicts(
            $db,
            $missionId,
            $esxiCredentialId,
            array_map(static fn (array $vm): int => (int) $vm['id'], $vms)
        );
        $scopeIds = array_map(static fn (array $vm): int => (int) $vm['id'], $vms);
        // Union pre-check for the whole group: it fans out into one job per VM
        // below, so the per-job scope cap is deliberately not applied here.
        repo_vm_network_assert_deploy_ready($db, $missionId, $scopeIds, (string) ($mission['wds_vlan'] ?? ''), (string) $basePayload['mode'], true, false);

        // Horizon guard for the LAST staggered slot (base + (n-1)*stagger).
        $baseEpoch = $baseUtc !== null ? strtotime($baseUtc . ' UTC') : time();
        $lastEpoch = $baseEpoch + (count($vms) - 1) * $staggerMinutes * 60;
        if ($lastEpoch > time() + VIRTUSPHERE_DEPLOY_SCHEDULE_HORIZON_DAYS * 86400) {
            throw new ValidationException(
                ['scheduled_at' => validator_text('validate.deploy_schedule_horizon', 'The last staggered start is beyond the :days-day scheduling horizon.', ['days' => VIRTUSPHERE_DEPLOY_SCHEDULE_HORIZON_DAYS])],
                validator_text('validate.deploy_schedule_horizon', 'The last staggered start is beyond the :days-day scheduling horizon.', ['days' => VIRTUSPHERE_DEPLOY_SCHEDULE_HORIZON_DAYS])
            );
        }

        $groupId = bin2hex(random_bytes(6)); // 12 hex chars -> CHAR(12)
        $schedule = [];
        $index = 0;
        foreach ($vms as $vm) {
            $vmId = (int) $vm['id'];
            repo_vm_network_assert_deploy_ready($db, $missionId, [$vmId], (string) ($mission['wds_vlan'] ?? ''), (string) $basePayload['mode']);
            $slotUtc = gmdate('Y-m-d H:i:s', $baseEpoch + $index * $staggerMinutes * 60);
            $payload = $basePayload;
            $payload['vm_ids'] = [$vmId];
            $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);

            // All slots share the enqueueing request's id (ADR-0032): one
            // click, one trace, even when it fans out into per-VM jobs.
            $correlationId = virtusphere_correlation_id();
            $stmt = $db->prepare('INSERT INTO deploy_jobs (mission_id, user_id, payload_json, credential_esxi_id, credential_ansible_id, scheduled_at, group_id, correlation_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('iisiisss', $missionId, $userId, $payloadJson, $esxiCredentialId, $ansibleCredentialId, $slotUtc, $groupId, $correlationId);
            $stmt->execute();
            $jobId = (int) $db->insert_id;
            if (ansible_mode_creates_vms((string) $payload['mode'])) {
                // The staggered path inserts its slots itself instead of going
                // through repo_create_deploy_job(), so it has to materialize the
                // unit itself as well. Without this a staggered create would be
                // the one create job with no per-VM row, and every consumer that
                // asks "is this job tracked" would answer no for a job that is.
                // One slot is one VM, hence one unit at position 1 of 1.
                repo_deploy_create_materialize($db, $jobId, [['id' => $vmId, 'vm_name' => (string) $vm['vm_name']]]);
            }
            repo_insert_deploy_job_log_unlocked($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'Deploy job queued (group ' . $groupId . ', slot ' . ($index + 1) . '/' . count($vms) . ') scheduled for ' . $slotUtc . ' UTC');
            $schedule[] = ['vm_id' => $vmId, 'vm_name' => (string) ($vm['vm_name'] ?? ''), 'scheduled_at' => $slotUtc];
            $index++;
        }

        return ['group_id' => $groupId, 'count' => count($vms), 'schedule' => $schedule];
    });
}

/**
 * Enqueues a mission-less system job (e.g. ESXi inventory, ADR-0023). Race-safe:
 * returns null if a queued/running system job already exists for the same ESXi
 * credential, so a manual refresh + the interval automation cannot pile up.
 */
function repo_create_system_job(mysqli $db, string $mode, int $esxiCredentialId, int $ansibleCredentialId, ?int $userId = null, bool $strictTrustProbe = false): ?int
{
    if ($esxiCredentialId <= 0 || $ansibleCredentialId <= 0) {
        throw new InvalidArgumentException('ESXi and Ansible credentials are required.');
    }
    // The mirror of deploy_job_normalize_mission_mode(): a mission-less job may
    // only carry a system mode. A `full` job without a mission would reach the
    // deploy branch and die on a NULL mission id.
    $mode = deploy_job_normalize_mode($mode);
    if (!array_key_exists($mode, VIRTUSPHERE_SYSTEM_PLAYBOOKS)) {
        throw new InvalidArgumentException('System jobs must use a system mode, not ' . $mode . '.');
    }

    return repo_transaction($db, static function () use ($db, $mode, $esxiCredentialId, $ansibleCredentialId, $userId, $strictTrustProbe): ?int {
        // Same active SSoT as the mission guard: a cancelling pull still owns
        // its credential until the worker confirms (ADR-0033).
        $active = VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES;
        $placeholders = implode(', ', array_fill(0, count($active), '?'));
        $existing = repo_fetch_one(
            $db,
            'SELECT id FROM deploy_jobs WHERE mission_id IS NULL AND credential_esxi_id = ? AND status IN (' . $placeholders . ') AND cancelled_at IS NULL LIMIT 1 FOR UPDATE',
            'i' . str_repeat('s', count($active)),
            array_merge([$esxiCredentialId], $active)
        );
        if ($existing !== null) {
            return null;
        }

        repo_deploy_assert_credential_type($db, $esxiCredentialId, VIRTUSPHERE_CREDENTIAL_TYPE_ESXI);
        repo_deploy_assert_credential_type($db, $ansibleCredentialId, VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE);

        $payloadJson = json_encode(['mode' => $mode, 'strict_trust_probe' => $strictTrustProbe], JSON_THROW_ON_ERROR);
        $userParam = $userId !== null && $userId > 0 ? $userId : null;
        $correlationId = virtusphere_correlation_id();
        $stmt = $db->prepare('INSERT INTO deploy_jobs (mission_id, user_id, payload_json, credential_esxi_id, credential_ansible_id, correlation_id) VALUES (NULL, ?, ?, ?, ?, ?)');
        $stmt->bind_param('isiis', $userParam, $payloadJson, $esxiCredentialId, $ansibleCredentialId, $correlationId);
        $stmt->execute();
        $jobId = (int) $db->insert_id;
        repo_insert_deploy_job_log_unlocked($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'System job queued: ' . $mode . ' for ESXi credential ' . $esxiCredentialId);

        return $jobId;
    });
}
