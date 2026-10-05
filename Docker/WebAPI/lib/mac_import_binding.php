<?php

declare(strict_types=1);

require_once __DIR__ . '/mac_import_constants.php';

// WM-E1 / K3 decision (b): how a MAC callback treats a VM with a stored MECM
// ResourceID. Pure helpers shared by the worker marking, the plan, its V2
// result, the import writer and the portal reader of the job-log marker.

/**
 * Whether a VM row carries a stored MECM ResourceID. The one predicate for
 * "is this VM bound?", so the worker and the callback cannot answer it two ways.
 */
function virtusphere_vm_is_mecm_bound(array $vm): bool
{
    return (string) ($vm['mecm_id'] ?? '') !== '';
}

/**
 * The interface MACs a successful VM plan actually writes. An unbound VM
 * imports every mapped card. A bound VM only takes over cards without a stored
 * MAC; equal cards are a comparison, not a write.
 *
 * @return list<array<string,mixed>>
 */
function mac_import_plan_writes(array $vmPlan): array
{
    $updates = array_values((array) ($vmPlan['updates'] ?? []));
    if (!virtusphere_vm_is_mecm_bound((array) ($vmPlan['vm'] ?? []))) {
        return $updates;
    }

    return array_values(array_filter($updates, static fn (array $update): bool => !empty($update['first_mac'])));
}

/**
 * Job-log marker of a bound VM whose first MAC the callback took over. It is
 * written in the callback transaction and is the portal's only source for the
 * "first MAC" notice: the V2 result has no field for it and stays frozen.
 */
function mac_import_bound_first_mac_log_line(int $vmId): string
{
    return VIRTUSPHERE_MAC_IMPORT_BOUND_FIRST_MAC_LOG_PREFIX . $vmId . '; lifecycle and MECM binding kept.';
}

function mac_import_bound_first_mac_log_vm_id(string $line): ?int
{
    $pattern = '/^' . preg_quote(VIRTUSPHERE_MAC_IMPORT_BOUND_FIRST_MAC_LOG_PREFIX, '/') . '([1-9][0-9]{0,9});/';
    if (preg_match($pattern, $line, $match) !== 1) {
        return null;
    }
    $vmId = (int) $match[1];

    return $vmId > 0 ? $vmId : null;
}
