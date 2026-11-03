<?php

declare(strict_types=1);

require_once __DIR__ . '/esxi_inventory_deviation_report.php';
require_once __DIR__ . '/esxi_inventory_display.php';
require_once __DIR__ . '/esxi_inventory_scheduler.php';

/** @return string|null */
function esxi_inventory_deviation_issue_kind(string $field): ?string
{
    return match ($field) {
        'datacenter', 'vm_datacenter' => VIRTUSPHERE_INVENTORY_KIND_DATACENTER,
        'datastore', 'vm_datastore' => VIRTUSPHERE_INVENTORY_KIND_DATASTORE,
        'vlan' => VIRTUSPHERE_INVENTORY_KIND_NETWORK,
        default => null,
    };
}

/**
 * Keep only findings whose exact-name evidence is qualified and current.
 * Historical comparisons remain available on the System status page, where
 * they are explicitly labeled as diagnostics rather than current warnings.
 *
 * @param array<string,mixed> $report
 * @return list<array<string,mixed>>
 */
function esxi_inventory_current_deviation_rows(array $report): array
{
    $rows = [];
    foreach (($report['deviations'] ?? []) as $entry) {
        $issues = [];
        foreach (($entry['issues'] ?? []) as $issue) {
            $kind = esxi_inventory_deviation_issue_kind((string) ($issue['field'] ?? ''));
            if ($kind !== null && !empty($report['kinds'][$kind]['current'])) {
                $issues[] = $issue;
            }
        }
        if ($issues !== []) {
            $entry['issues'] = $issues;
            $rows[] = $entry;
        }
    }

    return $rows;
}

/** @return array<int,true> */
function esxi_inventory_current_deviation_mission_ids(array $report): array
{
    $ids = [];
    foreach (esxi_inventory_current_deviation_rows($report) as $entry) {
        $ids[(int) $entry['mission_id']] = true;
    }

    return $ids;
}

/**
 * Per-current-host values absent there but confirmed on another current host.
 * Each single-host report uses the same evidence-qualified comparison; stale
 * or legacy names never become current host warnings.
 *
 * @param list<array{credential:array<string,mixed>,state:?array<string,mixed>}> $summaries
 * @return array<int,list<string>> credential id => missing values
 */
function esxi_inventory_current_mission_missing_by_credential(
    mysqli $db,
    int $missionId,
    array $summaries,
    int $intervalHours,
    int $now
): array {
    $mission = repo_fetch_one(
        $db,
        'SELECT hypervisor_datacenter, hypervisor_datastorage, wds_vlan FROM deploy_missions WHERE id = ? LIMIT 1',
        'i',
        [$missionId]
    );
    if ($mission === null || count($summaries) < 2) {
        return [];
    }

    $valuesByKind = [
        VIRTUSPHERE_INVENTORY_KIND_DATACENTER => [(string) ($mission['hypervisor_datacenter'] ?? '')],
        VIRTUSPHERE_INVENTORY_KIND_DATASTORE => [(string) ($mission['hypervisor_datastorage'] ?? '')],
        VIRTUSPHERE_INVENTORY_KIND_NETWORK => [(string) ($mission['wds_vlan'] ?? '')],
    ];
    $stmt = $db->prepare('SELECT vm_datacenter, vm_datastore FROM deploy_vms WHERE mission_id = ?');
    $stmt->bind_param('i', $missionId);
    $stmt->execute();
    foreach (repo_fetch_all($stmt->get_result()) as $row) {
        $valuesByKind[VIRTUSPHERE_INVENTORY_KIND_DATACENTER][] = (string) ($row['vm_datacenter'] ?? '');
        $valuesByKind[VIRTUSPHERE_INVENTORY_KIND_DATASTORE][] = (string) ($row['vm_datastore'] ?? '');
    }
    $stmt = $db->prepare(
        'SELECT i.vlan FROM deploy_interfaces i INNER JOIN deploy_vms v ON v.id = i.vm_id WHERE v.mission_id = ?'
    );
    $stmt->bind_param('i', $missionId);
    $stmt->execute();
    foreach (repo_fetch_all($stmt->get_result()) as $row) {
        $valuesByKind[VIRTUSPHERE_INVENTORY_KIND_NETWORK][] = (string) ($row['vlan'] ?? '');
    }

    $reports = [];
    $missingByCredential = [];
    foreach ($summaries as $summary) {
        $credentialId = (int) ($summary['credential']['id'] ?? 0);
        if ($credentialId <= 0) {
            continue;
        }
        $report = esxi_inventory_deviation_report($db, [$summary], $intervalHours, $now);
        $reports[$credentialId] = $report;
        foreach (($report['deviations'] ?? []) as $entry) {
            if ((int) ($entry['mission_id'] ?? 0) !== $missionId) {
                continue;
            }
            foreach (($entry['issues'] ?? []) as $issue) {
                $kind = esxi_inventory_deviation_issue_kind((string) ($issue['field'] ?? ''));
                $value = (string) ($issue['value'] ?? '');
                if ($kind !== null && $value !== '' && !empty($report['kinds'][$kind]['current'])) {
                    $missingByCredential[$credentialId][$kind][$value] = true;
                }
            }
        }
    }

    $out = [];
    foreach ($reports as $credentialId => $report) {
        foreach ($valuesByKind as $kind => $values) {
            if (empty($report['kinds'][$kind]['current'])) {
                continue;
            }
            foreach (array_unique(array_filter($values, static fn (string $value): bool => $value !== '')) as $value) {
                if (empty($missingByCredential[$credentialId][$kind][$value])) {
                    continue;
                }
                foreach ($reports as $otherCredentialId => $otherReport) {
                    if ($otherCredentialId === $credentialId || empty($otherReport['kinds'][$kind]['current'])) {
                        continue;
                    }
                    if (empty($missingByCredential[$otherCredentialId][$kind][$value])) {
                        $out[$credentialId][$value] = true;
                        break;
                    }
                }
            }
        }
    }

    foreach ($out as $credentialId => $values) {
        $out[$credentialId] = array_keys($values);
        sort($out[$credentialId], SORT_NATURAL | SORT_FLAG_CASE);
    }

    return $out;
}
