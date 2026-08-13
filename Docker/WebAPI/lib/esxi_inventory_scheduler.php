<?php

declare(strict_types=1);

/**
 * Resolves the Ansible SSH credential used by every inventory job.
 *
 * @return array{state:string,credential_id:?int,configured_id:int,credentials:array<int,array<string,mixed>>}
 */
function esxi_inventory_ansible_resolution(mysqli $db): array
{
    $ansible = repo_credentials_by_type($db, VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE);
    $configured = (int) repo_setting_value($db, VIRTUSPHERE_SETTING_ESXI_INVENTORY_ANSIBLE_CREDENTIAL, '0');

    return esxi_inventory_ansible_resolve($ansible, $configured);
}
/**
 * Pure decision core for the global Ansible credential used by ESXi inventory.
 * Keeping this independent of the database makes all 0/1/many edge cases
 * deterministic and gives settings, status and worker the same semantics.
 *
 * @param array<int,array<string,mixed>> $ansible
 * @return array{state:string,credential_id:?int,configured_id:int,credentials:array<int,array<string,mixed>>}
 */
function esxi_inventory_ansible_resolve(array $ansible, int $configured): array
{
    $configured = max(0, $configured);
    if ($ansible === []) {
        return ['state' => 'none', 'credential_id' => null, 'configured_id' => $configured, 'credentials' => []];
    }
    if (count($ansible) === 1) {
        return [
            'state' => 'automatic',
            'credential_id' => (int) $ansible[0]['id'],
            'configured_id' => $configured,
            'credentials' => $ansible,
        ];
    }

    foreach ($ansible as $credential) {
        if ((int) $credential['id'] === $configured && $configured > 0) {
            return [
                'state' => 'selected',
                'credential_id' => $configured,
                'configured_id' => $configured,
                'credentials' => $ansible,
            ];
        }
    }

    return [
        'state' => $configured > 0 ? 'invalid' : 'ambiguous',
        'credential_id' => null,
        'configured_id' => $configured,
        'credentials' => $ansible,
    ];
}

function esxi_inventory_ansible_credential_id(mysqli $db): ?int
{
    return esxi_inventory_ansible_resolution($db)['credential_id'];
}

/**
 * Clears a dangling explicit selection after deleting an Ansible credential or
 * changing its type. Returns true only when a setting was actually removed.
 */
function esxi_inventory_clear_ansible_selection_if_matches(mysqli $db, int $credentialId): bool
{
    if ($credentialId <= 0) {
        return false;
    }
    $configured = (int) repo_setting_value($db, VIRTUSPHERE_SETTING_ESXI_INVENTORY_ANSIBLE_CREDENTIAL, '0');
    if ($configured !== $credentialId) {
        return false;
    }
    repo_delete_setting($db, VIRTUSPHERE_SETTING_ESXI_INVENTORY_ANSIBLE_CREDENTIAL);

    return true;
}

/**
 * Enqueues one inventory job for an ESXi credential.
 *
 * @return array{enqueued:bool, reason?:string, job_id?:int, message?:string}
 */
function esxi_inventory_enqueue_for_credential(mysqli $db, int $esxiCredentialId, ?int $userId = null, bool $strictTrustProbe = false): array
{
    $resolution = esxi_inventory_ansible_resolution($db);
    $ansibleId = $resolution['credential_id'];
    if ($ansibleId === null) {
        $reason = match ($resolution['state']) {
            'ambiguous' => 'ambiguous_ansible_credential',
            'invalid' => 'invalid_ansible_credential',
            default => 'no_ansible_credential',
        };

        return ['enqueued' => false, 'reason' => $reason];
    }

    try {
        $jobId = repo_create_system_job($db, VIRTUSPHERE_DEPLOY_MODE_INVENTORY, $esxiCredentialId, $ansibleId, $userId, $strictTrustProbe);
    } catch (Throwable $exception) {
        return ['enqueued' => false, 'reason' => 'error', 'message' => $exception->getMessage()];
    }

    if ($jobId === null) {
        return ['enqueued' => false, 'reason' => 'already_pending'];
    }

    return ['enqueued' => true, 'job_id' => $jobId];
}

/**
 * ESXi credentials eligible for a bulk ("refresh all") inventory pull.
 * Auth-paused credentials are skipped with the same predicate as
 * esxi_inventory_enqueue_due: the pause protects the ESXi account from lockout
 * (ADR-0023), and a bulk click means "refresh what works", not "retry the
 * known-bad password". A targeted single-credential refresh stays a deliberate
 * retry and must not use this filter.
 *
 * @return array{ids: array<int, int>, skipped_paused: int}
 */
function esxi_inventory_refresh_all_targets(mysqli $db): array
{
    $ids = [];
    $skippedPaused = 0;
    foreach (repo_credentials_by_type($db, VIRTUSPHERE_CREDENTIAL_TYPE_ESXI) as $credential) {
        $credentialId = (int) $credential['id'];
        $state = repo_esxi_inventory_state($db, $credentialId);
        if ($state !== null && (int) $state['paused_until_credential_change'] === 1) {
            $skippedPaused++;
            continue;
        }
        $ids[] = $credentialId;
    }

    return ['ids' => $ids, 'skipped_paused' => $skippedPaused];
}

/** Configured pull interval in hours (0 = automation off). */
function esxi_inventory_interval_hours(mysqli $db): int
{
    return (int) repo_setting_value($db, VIRTUSPHERE_SETTING_ESXI_INVENTORY_INTERVAL_HOURS, (string) VIRTUSPHERE_ESXI_INVENTORY_INTERVAL_HOURS_DEFAULT);
}

/**
 * Enqueues inventory jobs for every ESXi credential whose last successful pull
 * is older than the configured interval (and which is not auth-paused). Returns
 * the number of jobs enqueued. Interval 0 disables the automation.
 */
function esxi_inventory_enqueue_due(mysqli $db): int
{
    $hours = (int) repo_setting_value($db, VIRTUSPHERE_SETTING_ESXI_INVENTORY_INTERVAL_HOURS, (string) VIRTUSPHERE_ESXI_INVENTORY_INTERVAL_HOURS_DEFAULT);
    $ansibleHostSelected = esxi_inventory_ansible_resolution($db)['credential_id'] !== null;
    // Same predicate the Credentials page names in its cadence line, so the two
    // cannot disagree about when this loop runs. The global blockers are checked
    // once with a null state before any credential is read.
    if (esxi_inventory_automation_blocker($hours, null, $ansibleHostSelected) !== null) {
        return 0;
    }
    $intervalSeconds = $hours * 3600;
    $now = time();
    $enqueued = 0;

    foreach (repo_credentials_by_type($db, VIRTUSPHERE_CREDENTIAL_TYPE_ESXI) as $credential) {
        $credentialId = (int) $credential['id'];
        $state = repo_esxi_inventory_state($db, $credentialId);
        if (esxi_inventory_automation_blocker($hours, $state, $ansibleHostSelected) !== null) {
            continue;
        }
        // Interval gate on the last ATTEMPT (success or failure), not just the
        // last success: record_success/record_failure both stamp last_attempt_at,
        // so a credential that never succeeded (misconfigured host/rights) retries
        // once per interval instead of every check cycle (~5 min).
        $lastAttempt = $state['last_attempt_at'] ?? null;
        if ($lastAttempt !== null && ($now - (int) strtotime((string) $lastAttempt . ' UTC')) < $intervalSeconds) {
            continue;
        }
        if (!empty(esxi_inventory_enqueue_for_credential($db, $credentialId)['enqueued'])) {
            $enqueued++;
        }
    }

    return $enqueued;
}
