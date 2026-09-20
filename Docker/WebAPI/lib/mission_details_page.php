<?php

declare(strict_types=1);

/**
 * Build the inventory-backed field state rendered by mission_details.php.
 *
 * @param array<string, mixed> $mission
 * @return array{
 *   vlans: list<array<string, mixed>>,
 *   storedVlan: string,
 *   wdsImpactTotal: int,
 *   wdsImpactCounts: array<string, int>,
 *   datacenterOptions: array<string, mixed>,
 *   datastoreOptions: array<string, mixed>,
 *   datacenterValue: string,
 *   datastoreValue: string,
 *   hideMissionDatacenter: bool,
 *   locationNotes: list<'host_choice'|'buckets'|'never_pulled'>
 * }
 */
function mission_details_view_state(mysqli $connection, int $missionId, array $mission, bool $isTemplate): array
{
    // ESXi-owned VLAN catalog: offer active entries; the stored value stays
    // selectable even if retired/unknown (decoupling, ADR-0023).
    $vlans = repo_active_vlans($connection);
    // Through form_old() like every other field of this form: a failed
    // validation elsewhere must not silently restore the stored VLAN.
    $storedVlan = form_old('update', 'wds_vlan', (string) ($mission['wds_vlan'] ?? ''));
    $wdsImpact = repo_vm_network_preflight($connection, $missionId, [], $storedVlan);
    $wdsImpactCounts = [
        VIRTUSPHERE_WDS_READY => 0,
        VIRTUSPHERE_WDS_MISSION_MISSING => 0,
        VIRTUSPHERE_WDS_PORTAL_MISSING => 0,
        VIRTUSPHERE_WDS_PORTAL_CASE_MISMATCH => 0,
        VIRTUSPHERE_WDS_PORTAL_AMBIGUOUS => 0,
    ];
    foreach ($wdsImpact['wds'] as $verdict) {
        $code = (string) ($verdict['code'] ?? '');
        if (array_key_exists($code, $wdsImpactCounts)) {
            $wdsImpactCounts[$code]++;
        }
    }

    $datacenterOptions = esxi_inventory_options($connection, VIRTUSPHERE_INVENTORY_KIND_DATACENTER);
    $datastoreOptions = esxi_inventory_options($connection, VIRTUSPHERE_INVENTORY_KIND_DATASTORE);
    $datacenterValue = form_old('update', 'hypervisor_datacenter', (string) ($mission['hypervisor_datacenter'] ?? ''));
    $datastoreValue = form_old('update', 'hypervisor_datastorage', (string) ($mission['hypervisor_datastorage'] ?? ''));

    // Datastore is mandatory and has no fallback, so one exact value is a safe
    // convenience. Datacenter stays derived from the deploy target.
    if (!$isTemplate && $datastoreValue === '' && esxi_inventory_options_are_exact($datastoreOptions) && count($datastoreOptions['names']) === 1) {
        $datastoreValue = $datastoreOptions['names'][0];
    }
    $hideMissionDatacenter = $datacenterValue === ''
        && esxi_inventory_options_are_exact($datacenterOptions)
        && count($datacenterOptions['names']) === 1;

    // Notes describe only controls that actually render.
    $renderedLocationOptions = [$datastoreOptions];
    if (!$hideMissionDatacenter) {
        $renderedLocationOptions[] = $datacenterOptions;
    }

    return [
        'vlans' => $vlans,
        'storedVlan' => $storedVlan,
        'wdsImpactTotal' => count($wdsImpact['vms']),
        'wdsImpactCounts' => $wdsImpactCounts,
        'datacenterOptions' => $datacenterOptions,
        'datastoreOptions' => $datastoreOptions,
        'datacenterValue' => $datacenterValue,
        'datastoreValue' => $datastoreValue,
        'hideMissionDatacenter' => $hideMissionDatacenter,
        'locationNotes' => esxi_inventory_location_notes($renderedLocationOptions),
    ];
}
