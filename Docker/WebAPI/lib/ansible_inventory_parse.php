<?php

declare(strict_types=1);

/**
 * Core inventory output normalization (ADR-0023). Split out of
 * lib/ansible_inventory.php (Etappe 7, ADR-0006): the marker decode, the two
 * item normalizers it dispatches to, the raw-vs-kept log line and the
 * playbook-failure categorizer all read the same base64-JSON marker or the
 * same raw stdout, unchanged.
 */

/**
 * One raw portgroup object (or a legacy plain string) from the inventory
 * playbook, normalized to a cache item with the VLAN id as meta. Standard
 * vSwitch objects name the portgroup 'portgroup', DVS objects 'portgroup_name'.
 * vlan_id is kept only as a plain integer; anything else (DVS trunk ranges like
 * "100-200", lists) sets the trunk flag instead, so the catalog never compares
 * a number to a range. Field extraction lives here, not in Jinja, because PHP
 * is unit-testable without an ESXi host.
 *
 * The two module families disagree on where the id sits: standard portgroups
 * carry `vlan_id` at the top level, DVS ones nest it under `vlan_info` next to
 * an explicit `trunk` flag. That flag wins over any id beside it, because a
 * trunk's id field holds a range.
 *
 * @return array{name:string, meta_json:?array{vlan_id:?int, trunk:bool}}|null null without a usable name
 */
function ansible_inventory_network_item(mixed $raw, string $source): ?array
{
    if (is_string($raw)) {
        $name = trim($raw);

        return $name === '' ? null : ['name' => $name, 'meta_json' => null];
    }
    if (!is_array($raw)) {
        return null;
    }

    $nameField = $source === 'dvs' ? 'portgroup_name' : 'portgroup';
    $name = trim((string) ($raw[$nameField] ?? $raw['name'] ?? ''));
    if ($name === '') {
        return null;
    }

    $vlanInfo = is_array($raw['vlan_info'] ?? null) ? $raw['vlan_info'] : [];
    $vlanRaw = $raw['vlan_id'] ?? $raw['vlan'] ?? $vlanInfo['vlan_id'] ?? null;
    $vlanId = null;
    $trunk = !empty($vlanInfo['trunk']);
    if ($trunk) {
        $vlanRaw = null;
    }
    if (is_int($vlanRaw) || (is_string($vlanRaw) && preg_match('/^\d+$/', trim($vlanRaw)) === 1)) {
        $vlanId = (int) $vlanRaw;
    } elseif ($vlanRaw !== null && $vlanRaw !== '') {
        $trunk = true;
    }

    return ['name' => $name, 'meta_json' => ['vlan_id' => $vlanId, 'trunk' => $trunk]];
}

/**
 * One VM the host holds, as `vmware_vm_info` reports it (decision 6). The name
 * is what the collision gate compares, the MOID is the handle it matches an
 * adopted VM against. The pinned module reports both the product `uuid` and
 * durable `instance_uuid`; only the latter decides whether a namesake is ours.
 *
 * A VM without a MOID is kept with a null handle rather than dropped: the name
 * still occupies the host, and a gate that cannot see it would wave through
 * exactly the collision it exists to catch. Only a nameless entry is a loss,
 * because a name comparison has nothing to work with.
 *
 * @return array{name:string, meta_json:array{moid:?string, instance_uuid:?string, power_state:?string}}|null
 */
function ansible_inventory_vm_item(mixed $raw): ?array
{
    if (!is_array($raw)) {
        return null;
    }

    $name = trim((string) ($raw['guest_name'] ?? $raw['name'] ?? ''));
    if ($name === '') {
        return null;
    }

    $moid = trim((string) ($raw['moid'] ?? ''));
    $instanceUuid = trim((string) ($raw['instance_uuid'] ?? ''));
    $powerState = trim((string) ($raw['power_state'] ?? ''));

    return [
        'name' => $name,
        'meta_json' => [
            'moid' => $moid === '' ? null : $moid,
            'instance_uuid' => $instanceUuid === '' ? null : $instanceUuid,
            'power_state' => $powerState === '' ? null : $powerState,
        ],
    ];
}

/**
 * Parses the base64-JSON marker the inventory playbook prints into the cache
 * shape. Throws when the marker is missing/corrupt (worker records "parse").
 *
 * @return array{datacenters:array<int,string>, datastores:array<int,array<string,mixed>>, networks:array<int,array<string,mixed>>, vms:array<int,array<string,mixed>>, hosts:array<int,array<string,mixed>>, capabilities:array<string,mixed>, queries:array<string,array{state:string,message:string}>, normalization:array<string,array{raw:int,kept:int}>}
 */
function ansible_parse_inventory_output(string $stdout): array
{
    if (preg_match('/VIRTUSPHERE_INVENTORY_B64_BEGIN(.*?)VIRTUSPHERE_INVENTORY_B64_END/s', $stdout, $matches) !== 1) {
        throw new RuntimeException('Inventory marker not found in playbook output.');
    }
    $decoded = base64_decode(trim($matches[1]), true);
    if ($decoded === false) {
        throw new RuntimeException('Inventory payload is not valid base64.');
    }
    $data = json_decode($decoded, true);
    if (!is_array($data)) {
        throw new RuntimeException('Inventory payload is not valid JSON.');
    }

    // Raw-vs-kept bookkeeping (B15): an entry whose shape stopped matching used
    // to vanish silently, which looks exactly like a host that has less. The
    // dedupe below is NOT a loss (a case-duplicate WAS parseable), so `kept`
    // counts parseable entries before de-duplication.
    $rawDatacenters = (array) ($data['datacenters'] ?? []);
    $datacenters = array_values(array_filter(array_map(static fn ($v): string => trim((string) $v), $rawDatacenters), static fn (string $v): bool => $v !== ''));

    $rawDatastores = (array) ($data['datastores'] ?? []);
    $datastores = [];
    foreach ($rawDatastores as $ds) {
        if (!is_array($ds)) {
            continue;
        }
        $name = trim((string) ($ds['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $capacity = $ds['capacity'] ?? $ds['capacity_bytes'] ?? null;
        $free = $ds['freeSpace'] ?? $ds['free_space'] ?? $ds['free_bytes'] ?? null;
        $datastores[] = [
            'name' => $name,
            'capacity_bytes' => $capacity !== null ? (int) $capacity : null,
            'free_bytes' => $free !== null ? (int) $free : null,
            'meta_json' => ansible_inventory_datastore_health($ds),
        ];
    }

    // Raw portgroup objects (or legacy plain names) from both module kinds,
    // de-duplicated case-insensitively; the first item's meta wins.
    $networks = [];
    $rawNetworks = 0;
    $keptNetworks = 0;
    foreach (['standard' => (array) ($data['networks_standard'] ?? []), 'dvs' => (array) ($data['networks_dvs'] ?? [])] as $source => $entries) {
        foreach ($entries as $raw) {
            $rawNetworks++;
            $item = ansible_inventory_network_item($raw, $source);
            if ($item === null) {
                continue;
            }
            $keptNetworks++;
            $key = esxi_inventory_name_key($item['name']);
            if (!isset($networks[$key])) {
                $networks[$key] = $item;
            }
        }
    }
    $networks = array_values($networks);

    // Same case-insensitive dedupe as the networks above, and for a harder
    // reason: (credential_id, kind, name) is unique, so two case variants of
    // one name would make the write fail instead of the cache disagree.
    $rawVms = (array) ($data['vms'] ?? []);
    $vms = [];
    $keptVms = 0;
    foreach ($rawVms as $raw) {
        $item = ansible_inventory_vm_item($raw);
        if ($item === null) {
            continue;
        }
        $keptVms++;
        $key = esxi_inventory_name_key($item['name']);
        if (!isset($vms[$key])) {
            $vms[$key] = $item;
        }
    }
    $vms = array_values($vms);

    return [
        'datacenters' => $datacenters,
        'datastores' => $datastores,
        'networks' => $networks,
        'vms' => $vms,
        'hosts' => ansible_parse_inventory_hosts($data['hosts'] ?? [], $data['fetched_epoch'] ?? null),
        'capabilities' => ansible_parse_inventory_capabilities($data['about'] ?? [], $data['host_runtime'] ?? []),
        'queries' => ansible_parse_inventory_queries($data['queries'] ?? []),
        'normalization' => [
            'datacenters' => ['raw' => count($rawDatacenters), 'kept' => count($datacenters)],
            'datastores' => ['raw' => count($rawDatastores), 'kept' => count($datastores)],
            'networks' => ['raw' => $rawNetworks, 'kept' => $keptNetworks],
            'vms' => ['raw' => count($rawVms), 'kept' => $keptVms],
        ],
    ];
}

/**
 * The job-log line for the raw-vs-kept balance of one pull, in the shape of the
 * query and datastore-health lines above and for the same reason: it speaks in
 * the good case too, because a line that only ever appears when something is
 * wrong does not teach the reader that the check exists. Null for a pull that
 * carried no raw entries at all (the counts line already says so).
 *
 * @param array<string, array{raw:int, kept:int}> $normalization
 */
function ansible_inventory_normalization_log_line(array $normalization): ?string
{
    $totalRaw = 0;
    $parts = [];
    foreach ($normalization as $kind => $counts) {
        $raw = $counts['raw'];
        $kept = $counts['kept'];
        $totalRaw += $raw;
        if ($raw > $kept) {
            $parts[] = sprintf('%s %d of %d unusable', (string) $kind, $raw - $kept, $raw);
        }
    }

    if ($totalRaw === 0) {
        return null;
    }
    if ($parts === []) {
        return sprintf('Inventory normalization: all %d raw entries usable.', $totalRaw);
    }

    return 'Inventory normalization: ' . implode('; ', $parts) . ' - the raw module output sits in the playbook log above.';
}

/**
 * Classifies an inventory fetch failure from the playbook output + exit code
 * into an error category so the portal can show a specific hint.
 */
function ansible_categorize_inventory_error(string $output, int $exitCode): string
{
    $lower = strtolower($output);
    // Our own deployment, not the host's answer. A missing playbook or a
    // missing collection means the run never reached ESXi, so it must not be
    // reported with a sentence about the host: an inventory pull once died on
    // "the playbook: inventoryESXi_playbook.yml could not be found" and told
    // the operator the host had answered unexpectedly, sending them to check a
    // network path that was never used.
    // Both strings are ansible-playbook's own wording for "I could not load
    // what you asked me to run". Deliberately narrow: a broader pattern would
    // swallow a real answer from ESXi that happens to mention a module.
    if (str_contains($lower, 'could not be found')
        || str_contains($lower, "couldn't resolve module/action")
    ) {
        return VIRTUSPHERE_INVENTORY_ERROR_CONFIG;
    }
    if (str_contains($lower, 'certificate verify failed')
        || str_contains($lower, 'unable to get local issuer')
        || str_contains($lower, 'self-signed certificate')
        || str_contains($lower, 'self signed certificate')
    ) {
        return VIRTUSPHERE_INVENTORY_ERROR_CERTIFICATE;
    }
    if (str_contains($lower, 'permission') || str_contains($lower, 'not authorized') || str_contains($lower, 'privilege') || str_contains($lower, 'restricted')) {
        return VIRTUSPHERE_INVENTORY_ERROR_AUTHZ;
    }
    if (str_contains($lower, 'incorrect user name or password') || str_contains($lower, 'login') || str_contains($lower, 'authentication') || str_contains($lower, 'invalid credentials')) {
        return VIRTUSPHERE_INVENTORY_ERROR_AUTH;
    }
    if (str_contains($lower, 'unreachable') || str_contains($lower, 'timed out') || str_contains($lower, 'unable to connect') || str_contains($lower, 'no route to host') || str_contains($lower, 'connection refused')) {
        return VIRTUSPHERE_INVENTORY_ERROR_UNREACHABLE;
    }

    return VIRTUSPHERE_INVENTORY_ERROR_PARSE;
}
