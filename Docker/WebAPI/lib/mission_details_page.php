<?php

declare(strict_types=1);

require_once __DIR__ . '/edit_conflict_view.php';

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

/**
 * The mission columns one settings POST saves. The update action and the
 * conflict comparison read the same list (QL-01/QL-03 B).
 *
 * @param array<string, mixed> $post
 * @return array<string, string>
 */
function mission_details_submitted_changes(array $post): array
{
    return [
        'mission_name' => request_trimmed($post, 'mission_name'),
        'mission_notes' => request_string($post, 'mission_notes'),
        'wds_vlan' => request_string($post, 'wds_vlan'),
        'hypervisor_datastorage' => request_string($post, 'hypervisor_datastorage'),
        'hypervisor_datacenter' => request_string($post, 'hypervisor_datacenter'),
        'domain' => request_string($post, 'domain'),
        // Autostart defaults (ADR-0025). The checkboxes ship a hidden "0",
        // so an unchecked box arrives as 0 rather than as an absent key.
        'autostart_enabled' => request_string($post, 'autostart_enabled', '0'),
        'autostart_start_delay' => request_string($post, 'autostart_start_delay'),
        'autostart_stop_delay' => request_string($post, 'autostart_stop_delay'),
        'autostart_stop_action' => request_string($post, 'autostart_stop_action'),
        'autostart_wait_for_heartbeat' => request_string($post, 'autostart_wait_for_heartbeat', '0'),
    ];
}

/**
 * Labels and display rules for every column mission_details_submitted_changes() returns.
 *
 * @return array<string, array{label:string, kind:'text'|'int'|'bool', options?:array<int|string, string>, empty?:string}>
 */
function mission_details_conflict_fields(): array
{
    $stopActions = [];
    foreach (VIRTUSPHERE_AUTOSTART_STOP_ACTIONS as $action) {
        $stopActions[$action] = __t('mission_details.autostart_stop_' . $action);
    }

    return [
        'mission_name' => ['label' => __t('common.name'), 'kind' => 'text'],
        'mission_notes' => ['label' => __t('mission_details.label_notes'), 'kind' => 'text'],
        'wds_vlan' => ['label' => __t('mission_details.label_wds_vlan'), 'kind' => 'text', 'empty' => __t('mission_details.vlan_none')],
        'hypervisor_datastorage' => ['label' => __t('mission_details.label_datastore'), 'kind' => 'text'],
        'hypervisor_datacenter' => ['label' => __t('mission_details.label_datacenter'), 'kind' => 'text', 'empty' => __t('mission_details.datacenter_from_host')],
        'domain' => ['label' => __t('mission_details.label_domain'), 'kind' => 'text'],
        'autostart_enabled' => ['label' => __t('mission_details.autostart_enabled'), 'kind' => 'bool'],
        'autostart_start_delay' => ['label' => __t('mission_details.autostart_start_delay'), 'kind' => 'int'],
        'autostart_stop_delay' => ['label' => __t('mission_details.autostart_stop_delay'), 'kind' => 'int'],
        'autostart_stop_action' => ['label' => __t('mission_details.autostart_stop_action'), 'kind' => 'text', 'options' => $stopActions],
        'autostart_wait_for_heartbeat' => ['label' => __t('mission_details.autostart_wait_heartbeat'), 'kind' => 'bool'],
    ];
}

/**
 * The comparison after a redirected settings POST that saved nothing: the
 * remembered input against the row this GET just read, or null when the
 * remembered version is current and the operator did not just continue.
 *
 * @param array<string, mixed> $mission
 * @return array{mode:string, version:string, rows:list<array{label:string, submitted:string, current:string, rows:bool}>}|null
 */
function mission_details_conflict(array $mission): ?array
{
    if (!form_has_state('update')) {
        return null;
    }
    $old = form_old_all('update');
    $storedVersion = (string) ($mission['edit_version'] ?? '');
    $mode = edit_conflict_mode(
        form_old('update', 'edit_version'),
        $storedVersion,
        ($old['edit_conflict'] ?? null) === VIRTUSPHERE_EDIT_CONFLICT_CONTINUED
    );
    if ($mode === '') {
        return null;
    }

    return [
        'mode' => $mode,
        'version' => $storedVersion,
        'rows' => edit_conflict_rows(mission_details_submitted_changes($old), $mission, mission_details_conflict_fields()),
    ];
}
