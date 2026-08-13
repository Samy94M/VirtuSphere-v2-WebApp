<?php

declare(strict_types=1);

/**
 * Traffic-light state for one credential's fetch health: danger when auth-paused
 * or the failure streak reaches VIRTUSPHERE_ESXI_INVENTORY_FAILURE_STREAK_DANGER,
 * warning when the last fetch failed, none ever succeeded, or the last success
 * is older than VIRTUSPHERE_ESXI_INVENTORY_STALE_FACTOR x interval; unknown
 * before the first attempt, else ok. Interval 0 means the automation is off:
 * age proves nothing then, so staleness never warns and only real failures
 * colour the light. $now is injectable for tests.
 */
function esxi_inventory_ampel(?array $state, int $intervalHours, ?int $now = null): string
{
    if ($state === null || empty($state['last_attempt_at'])) {
        return 'unknown';
    }
    if ((int) ($state['paused_until_credential_change'] ?? 0) === 1
        || (int) ($state['failure_streak'] ?? 0) >= VIRTUSPHERE_ESXI_INVENTORY_FAILURE_STREAK_DANGER) {
        return 'danger';
    }
    $lastSuccess = $state['last_success_at'] ?? null;
    if ($lastSuccess === null) {
        return 'warning';
    }
    if ($intervalHours > 0) {
        $staleSeconds = VIRTUSPHERE_ESXI_INVENTORY_STALE_FACTOR * $intervalHours * 3600;
        if ((($now ?? time()) - (int) strtotime((string) $lastSuccess . ' UTC')) > $staleSeconds) {
            return 'warning';
        }
    }

    return ($state['last_status'] ?? '') === 'failed' ? 'warning' : 'ok';
}
/**
 * Compact cards in one fixed set of bulk queries. Full inventory rows are not
 * loaded until esxi_inventory_detail() is called for one explicitly requested
 * ESXi credential.
 *
 * Carries the raw `state` row and deliberately NOT a traffic-light state. The
 * badge the portal shows is esxi_credential_state() (lib/esxi_capabilities.php),
 * which is the fetch health. Returning a second state field here would hand a
 * caller a parallel "ampel" to render, and the two would eventually disagree on
 * the same page.
 *
 * @return array<int,array{credential:array<string,mixed>,state:?array<string,mixed>,counts:array<string,int>,pending_job:?array<string,mixed>}>
 */
function esxi_inventory_summaries(mysqli $db): array
{
    $states = repo_esxi_inventory_states($db);
    $counts = repo_esxi_inventory_counts($db);
    $pendingJobs = repo_esxi_inventory_pending_jobs($db);
    $out = [];
    foreach (repo_credentials_by_type($db, VIRTUSPHERE_CREDENTIAL_TYPE_ESXI) as $credential) {
        $credentialId = (int) $credential['id'];
        $out[] = [
            'credential' => $credential,
            'state' => $states[$credentialId] ?? null,
            'counts' => $counts[$credentialId] ?? [],
            'pending_job' => $pendingJobs[$credentialId] ?? null,
        ];
    }

    return $out;
}

/**
 * @return array<string,array<int,array<string,mixed>>>|null
 */
function esxi_inventory_detail(mysqli $db, int $credentialId): ?array
{
    $credential = repo_credential($db, $credentialId);
    if ($credential === null || (string) $credential['type'] !== VIRTUSPHERE_CREDENTIAL_TYPE_ESXI) {
        return null;
    }

    return repo_esxi_inventory_for_credential($db, $credentialId);
}
