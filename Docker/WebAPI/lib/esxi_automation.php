<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_constants.php';

/**
 * The one decision "does the interval automation run for this ESXi credential".
 *
 * Deliberately dependency-free (scalars and one state row), so both sides can
 * load it: esxi_inventory_enqueue_due() skips on it, and the Credentials page
 * turns its answer into the cadence line under the status badge. Those two used
 * to restate the same three conditions in two places, which is the defect the
 * cadence line exists to prevent, one level up: a blocker added to the scheduler
 * alone would leave the row promising a cycle that no longer runs.
 *
 * @param int $intervalHours Effective setting; 0 switches the automation off.
 * @param array<string, mixed>|null $esxiState Inventory state row of the
 *        credential, or null when it was never pulled. Never pulled is not
 *        paused: the scheduler will pick it up.
 * @param bool $ansibleHostSelected esxi_inventory_ansible_resolution() resolved
 *        a host. Without one the pull has nothing to run over and is not even
 *        attempted, so no timestamp moves either.
 * @param bool $deployWorkerAlive The deploy worker reported in within its
 *        staleness window. The pull is a deploy JOB: the maintenance worker only
 *        enqueues it, the deploy worker runs it. Without a worker the job sits
 *        `queued` forever while the cadence line promises a cycle - the same
 *        defect the cadence line exists to prevent, one component over. Defaults
 *        to true so a caller that genuinely cannot know does not invent a blocker.
 * @return string|null One of VIRTUSPHERE_ESXI_AUTOMATION_BLOCKERS, or null when
 *         nothing stops the credential from being enqueued on its cycle.
 */
function esxi_inventory_automation_blocker(int $intervalHours, ?array $esxiState, bool $ansibleHostSelected, bool $deployWorkerAlive = true): ?string
{
    if ($intervalHours <= 0) {
        return VIRTUSPHERE_ESXI_AUTOMATION_INTERVAL_OFF;
    }
    if (!$ansibleHostSelected) {
        return VIRTUSPHERE_ESXI_AUTOMATION_NO_ANSIBLE_HOST;
    }
    if (!$deployWorkerAlive) {
        return VIRTUSPHERE_ESXI_AUTOMATION_NO_WORKER;
    }
    if ($esxiState !== null && (int) ($esxiState['paused_until_credential_change'] ?? 0) === 1) {
        return VIRTUSPHERE_ESXI_AUTOMATION_PAUSED;
    }

    return null;
}
