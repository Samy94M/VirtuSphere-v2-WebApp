<?php

declare(strict_types=1);

require_once __DIR__ . '/vm_progress.php';
require_once __DIR__ . '/log_redaction.php';
require_once __DIR__ . '/repo/vms.php';
// The enqueue (scheduler) and the access state (repo) both sit behind this facade.
require_once __DIR__ . '/esxi_inventory.php';

/**
 * The worker's two triggers of the automatic observation start (ADR-0038
 * amendment 2). Both run AFTER their job's own result is committed and are
 * fail-soft: the OS-install clock is display metadata, and a clock that could
 * not be set must never cost a deploy job its terminal state or an inventory
 * pull its cache. The next trigger asks the same VM again.
 *
 * Neither writes lifecycle or MECM state. `start` and `autostart` stay the
 * modes that own no VM state (deploy_worker_mode_preserves_vm_state()); the
 * clock is a separate column with its own writer in lib/repo/vms_progress_watch.php.
 */

/**
 * What a terminal mission job says about its start step, read back from the
 * stored row: `completed` is the proof virtusphere_vm_job_completed_start_step()
 * accepts, `ran` also covers a start step that failed, after which some VMs of
 * the job may be switched on and only the inventory can tell which.
 *
 * @return array{completed:bool, ran:bool}
 */
function deploy_worker_job_start_step(mysqli $db, int $jobId): array
{
    $row = repo_fetch_one($db, 'SELECT status, failed_step, payload_json FROM deploy_jobs WHERE id = ? LIMIT 1', 'i', [$jobId]);
    if ($row === null) {
        return ['completed' => false, 'ran' => false];
    }
    $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
    $completed = virtusphere_vm_job_completed_start_step([
        'status' => (string) $row['status'],
        'mode' => deploy_job_stored_mode($payload),
        'failed_step' => $row['failed_step'],
        'then_autostart' => is_array($payload) && !empty($payload['then_autostart']),
    ]);

    return [
        'completed' => $completed,
        'ran' => $completed || ((string) $row['status'] === VIRTUSPHERE_DEPLOY_STATUS_FAILED && (string) ($row['failed_step'] ?? '') === 'start'),
    ];
}

/**
 * Trigger 2, and the inventory pull that follows a start step. Called with the
 * job's terminal state already published.
 *
 * The pull is what turns a hand-started or half-started selection into
 * evidence: its `vm` kind carries the power state trigger 3 reads. An access
 * that is paused after an authentication failure is left alone (ADR-0023); the
 * job-deduplication of system jobs keeps a second request from piling up.
 */
function deploy_worker_after_start_step(mysqli $db, array $job): void
{
    $jobId = (int) ($job['id'] ?? 0);
    try {
        $step = deploy_worker_job_start_step($db, $jobId);
        if ($step['completed']) {
            repo_start_install_watches_after_job($db, $jobId);
        }
        $credentialId = (int) ($job['credential_esxi_id'] ?? 0);
        if ($step['ran'] && $credentialId > 0
            && (int) (repo_esxi_inventory_state($db, $credentialId)['paused_until_credential_change'] ?? 0) !== 1
        ) {
            esxi_inventory_enqueue_for_credential($db, $credentialId);
        }
    } catch (Throwable $exception) {
        error_log('[deploy-worker] job ' . $jobId . ': automatic observation start after the start step failed: ' . virtusphere_redact_log_text($exception->getMessage()));
    }
}

/**
 * Trigger 3. Called after an inventory pull was applied; only a pull whose `vm`
 * kind answered is evidence, and only for that access.
 *
 * @param array<string,mixed> $parsed the parsed pull, as repo_esxi_inventory_apply() received it
 * @param string $claimedAt `locked_at` of the pull's job: the host was read no earlier
 */
function deploy_worker_after_vm_inventory(mysqli $db, int $credentialId, array $parsed, string $claimedAt = ''): void
{
    try {
        $answered = repo_esxi_inventory_answered_kinds((array) ($parsed['queries'] ?? []), (array) ($parsed['name_failures'] ?? []));
        if (in_array(VIRTUSPHERE_INVENTORY_KIND_VM, $answered, true)) {
            repo_start_install_watches_after_inventory($db, $credentialId, $claimedAt);
        }
    } catch (Throwable $exception) {
        error_log('[deploy-worker] automatic observation start after the inventory of credential ' . $credentialId . ' failed: ' . virtusphere_redact_log_text($exception->getMessage()));
    }
}
