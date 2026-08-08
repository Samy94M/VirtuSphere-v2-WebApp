<?php

declare(strict_types=1);

/**
 * ESXi inventory transport and parsing (ADR-0023).
 *
 * Split out of ansible.php, which had grown past its size budget (ADR-0006) and
 * was carrying two unrelated jobs: generating deploy artifacts, and running plus
 * decoding the read-only inventory pull. Everything here belongs to the second.
 *
 * The playbook is a dumb transport: it forwards raw module output inside one
 * base64-JSON marker line. Every field path is resolved on this side, because
 * PHP is unit-testable without an ESXi host and Jinja is not.
 */

require_once __DIR__ . '/ansible.php';
require_once __DIR__ . '/esxi_datastore_health.php';
// --- ESXi inventory (ADR-0023) -------------------------------------------------

/**
 * Prepares the artifacts for an inventory run: the ansible source (with the
 * inventory playbook) plus an ESXi accounts.yml. No serverlist, no MAC upload.
 *
 * @return array{local_dir:string, remote_dir:string, files:array<int,string>}
 */
function ansible_prepare_inventory_artifacts(mysqli $db, array $job, array $esxiCredential, string $esxiSecret, array $ansibleCredential, string $apiBaseUrl): array
{
    $jobId = (int) ($job['id'] ?? 0);
    if ($jobId <= 0) {
        throw new InvalidArgumentException('Deploy job is required.');
    }

    $label = 'inventory';
    $workDir = ansible_create_job_work_dir($jobId, $label);
    ansible_copy_source_files(ansible_source_dir(), $workDir);
    ansible_write_file(
        $workDir . DIRECTORY_SEPARATOR . 'accounts.yml',
        ansible_accounts_yml($esxiCredential, $esxiSecret, $ansibleCredential, $apiBaseUrl)
    );

    return [
        'local_dir' => $workDir,
        'remote_dir' => ansible_remote_dir($jobId, $label),
        'files' => array_values(array_unique(array_merge(ansible_required_files(), ['accounts.yml']))),
    ];
}

/** Remote command that runs the read-only inventory playbook. */
function ansible_inventory_remote_command(string $remoteDir, bool $verbose = false): string
{
    $playbook = VIRTUSPHERE_SYSTEM_PLAYBOOKS[VIRTUSPHERE_DEPLOY_MODE_INVENTORY];
    $commands = [
        'cd ' . ansible_sh_quote($remoteDir),
        'chmod 600 accounts.yml',
        'ansible-playbook ' . ansible_sh_quote($playbook) . ($verbose ? ' -vvv' : '') . ' 2>&1',
    ];
    $cleanup = 'rm -rf -- ' . ansible_sh_quote($remoteDir);

    return 'trap ' . ansible_sh_quote($cleanup) . ' EXIT; ' . implode(' && ', $commands);
}

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
 * Health of one raw datastore object, kept as its cache meta. `capacity` and
 * `freeSpace` say how big it is; these two say whether that size means anything
 * right now, and the parser used to throw them away.
 *
 * Field paths follow the documented vmware_datastore_info output and carry
 * fallbacks like the size fields do. Both are tri-state: a field the module did
 * not report stays absent, so the reader says "not known" instead of guessing
 * "healthy" for a datastore that may be in maintenance (ADR-0023 tri-state
 * contract). Null when neither field arrived, which keeps meta_json NULL rather
 * than writing an object full of nulls.
 *
 * @param array<string, mixed> $raw
 * @return array<string, mixed>|null
 */
function ansible_inventory_datastore_health(array $raw): ?array
{
    $meta = [];
    foreach (['accessible', 'is_accessible'] as $key) {
        if (array_key_exists($key, $raw)) {
            $meta['accessible'] = $raw[$key];
            break;
        }
    }
    foreach (['maintenanceMode', 'maintenance_mode'] as $key) {
        if (array_key_exists($key, $raw)) {
            $meta['maintenance'] = $raw[$key];
            break;
        }
    }

    return $meta !== [] ? $meta : null;
}

/**
 * The job-log line for the datastore health of one pull, in the shape of
 * `Inventory queries:` and `ESXi capabilities:` above.
 *
 * Written on every successful pull including the all-good case, and for the
 * same reason those two are: a field path that silently stopped matching looks
 * exactly like a fleet where nothing is in maintenance, and only a line that
 * also speaks when everything is fine lets an operator tell the two apart. That
 * is the lesson of the portgroup query that reported 0 for months.
 *
 * English, like every other job-log line (operator diagnostics, not portal
 * prose). Null when the pull carried no datastores at all: there is nothing to
 * report on, and the item counts above already say so.
 *
 * @param array<int, array<string, mixed>> $datastores parsed cache items
 */
function ansible_inventory_datastore_health_log_line(array $datastores): ?string
{
    $total = count($datastores);
    if ($total === 0) {
        return null;
    }

    $withAccessible = 0;
    $withMaintenance = 0;
    $inMaintenance = [];
    $inaccessible = [];
    foreach ($datastores as $datastore) {
        $health = esxi_datastore_health($datastore['meta_json'] ?? null);
        if ($health['accessible'] !== null) {
            $withAccessible++;
        }
        if ($health['maintenance'] !== null) {
            $withMaintenance++;
        }
        if ($health['maintenance'] === true) {
            $inMaintenance[] = (string) ($datastore['name'] ?? '?');
        }
        if ($health['accessible'] === false) {
            $inaccessible[] = (string) ($datastore['name'] ?? '?');
        }
    }

    $line = sprintf(
        'Datastore health: %d datastore(s), accessibility reported for %d, maintenance mode reported for %d.',
        $total,
        $withAccessible,
        $withMaintenance
    );
    if ($inMaintenance !== []) {
        $line .= ' In maintenance: ' . implode(', ', $inMaintenance) . '.';
    }
    if ($inaccessible !== []) {
        $line .= ' Inaccessible: ' . implode(', ', $inaccessible) . '.';
    }

    return $line;
}

/**
 * What every single query of one pull did. A pull is several separate queries
 * and only the first is the connection canary, so it can succeed while one
 * query answered nothing at all. An empty list cannot say which of the three
 * happened (the host has none / the account may not look / the call was
 * rejected before reaching ESXi), and that ambiguity is what let a rejected
 * portgroup query read as "this host has no portgroups" for months.
 *
 * Absent for a pull whose playbook predates the report; the caller then says
 * nothing rather than claiming every query answered.
 *
 * @return array<string, array{state:string, message:string}>
 */
function ansible_parse_inventory_queries(mixed $raw): array
{
    if (!is_array($raw)) {
        return [];
    }

    $queries = [];
    foreach ($raw as $name => $entry) {
        $name = trim((string) $name);
        if ($name === '' || !is_array($entry)) {
            continue;
        }
        $state = VIRTUSPHERE_INVENTORY_QUERY_ANSWERED;
        if (!empty($entry['failed'])) {
            $state = VIRTUSPHERE_INVENTORY_QUERY_REJECTED;
        } elseif (!empty($entry['skipped'])) {
            $state = VIRTUSPHERE_INVENTORY_QUERY_SKIPPED;
        }
        // Collapsed to one line before truncation: a module message may carry a
        // traceback, and a summary line that silently becomes twelve lines is
        // no longer the one line an operator can scan. The full text stays in
        // the playbook output above it either way.
        // Bytewise on purpose: `\s` without /u cannot match inside a UTF-8
        // sequence, so it collapses newlines without the /u failure mode of
        // returning null on malformed input and losing the message entirely.
        $message = trim((string) preg_replace('/\s+/', ' ', (string) ($entry['msg'] ?? '')));
        $queries[$name] = [
            'state' => $state,
            'message' => mb_substr($message, 0, VIRTUSPHERE_INVENTORY_QUERY_MESSAGE_MAX_LENGTH),
        ];
    }

    return $queries;
}

/**
 * The job-log line that turns a 0 from a verdict into a fact with a reason.
 * Written on every successful pull, including the all-good case: a reader who
 * only ever sees the line when something is wrong does not learn that a pull
 * has parts, and this line is where an operator finds out which part was quiet.
 *
 * Queries that failed the same way are named together. Rendered in the actual
 * job log, the ungrouped version turned a systematic failure into fourteen
 * lines of the same sentence, which hides the one thing the line is for: which
 * queries were silent.
 *
 * English like every other job-log line (operator diagnostics, not portal
 * prose). Null for a pull without the report, so an old playbook stays silent
 * instead of claiming completeness it never measured.
 */
function ansible_inventory_queries_log_line(array $queries): ?string
{
    if ($queries === []) {
        return null;
    }

    // Grouped by state and message, because the common failure is systematic:
    // a wrong credential, a module version or a bad argument list hits several
    // queries with the identical sentence, and repeating it once per query
    // buried the six names that matter under six copies of the same text.
    $groups = [];
    $quiet = 0;
    foreach ($queries as $name => $query) {
        $state = (string) ($query['state'] ?? '?');
        if ($state === VIRTUSPHERE_INVENTORY_QUERY_ANSWERED) {
            continue;
        }
        $quiet++;
        $message = trim((string) ($query['message'] ?? ''));
        $groups[$state . "\0" . $message][] = (string) $name;
    }

    $total = count($queries);
    if ($quiet === 0) {
        return sprintf('Inventory queries: all %d answered.', $total);
    }

    $parts = [];
    foreach ($groups as $key => $names) {
        [$state, $message] = explode("\0", $key, 2);
        $parts[] = implode(', ', $names) . ' ' . $state . ($message !== '' ? ' (' . $message . ')' : '');
    }

    return sprintf(
        'Inventory queries: %d of %d answered, %d without an answer - %s',
        $total - $quiet,
        $total,
        $quiet,
        implode('; ', $parts)
    );
}

/**
 * Capability facts of a successful pull: what the endpoint is (api_type,
 * product, licence) and what it is doing (maintenance, HA). These are NOT error
 * categories; a fetch that reached this point succeeded.
 *
 * Every value is nullable and stays null when the module did not report it. That
 * is the whole contract: a missing field must read as "not known", never as
 * false, because a false license_free would silently promise a write the host
 * cannot perform. Field paths are best-effort with fallbacks and are verified
 * against the productive host on rollout (docs/operations/esxi-inventory.md).
 *
 * @return array{api_type:?string, product_version:?string, license_product:?string, license_free:?bool, in_ha_cluster:?bool, in_maintenance:?bool}
 */
function ansible_parse_inventory_capabilities(mixed $about, mixed $runtime): array
{
    $about = is_array($about) ? $about : [];
    $runtime = is_array($runtime) ? $runtime : [];

    $apiType = ansible_capability_string($about, ['apiType', 'api_type']);
    $product = ansible_capability_string($about, ['productFullName', 'product_full_name', 'fullName', 'productLineId']);
    $version = ansible_capability_string($about, ['version', 'apiVersion', 'api_version']);
    $license = ansible_capability_string($about, ['licenseProductName', 'license_product_name']);

    // "ESXi 8.0.3" from product + version, without repeating a version the
    // product name already carries.
    $productVersion = null;
    if ($product !== null || $version !== null) {
        $productVersion = trim(($product ?? '') . ' ' . (($version !== null && ($product === null || !str_contains($product, $version))) ? $version : ''));
        $productVersion = $productVersion !== '' ? $productVersion : null;
    }

    // Free licence detection is a name match, never a write probe: attempting a
    // write to find out would be the exact thing we are trying to warn about.
    $licenseFree = null;
    if ($license !== null) {
        $lower = mb_strtolower($license);
        $licenseFree = false;
        foreach (VIRTUSPHERE_ESXI_FREE_LICENSE_MARKERS as $marker) {
            if (str_contains($lower, $marker)) {
                $licenseFree = true;
                break;
            }
        }
    }

    return [
        'api_type' => $apiType,
        'product_version' => $productVersion !== null ? mb_substr($productVersion, 0, 191) : null,
        'license_product' => $license !== null ? mb_substr($license, 0, 191) : null,
        'license_free' => $licenseFree,
        // A standalone host reports no dasHostState at all; a clustered one
        // reports its HA agent state. Absence of the key is the signal, so an
        // empty runtime block (task failed) yields null, not false.
        'in_ha_cluster' => ansible_capability_ha_state($runtime),
        'in_maintenance' => ansible_capability_bool($runtime, ['runtime.inMaintenanceMode', 'inMaintenanceMode']),
    ];
}

/** First non-empty string among $keys, trimmed; null when none is usable. */
function ansible_capability_string(array $source, array $keys): ?string
{
    foreach ($keys as $key) {
        $value = $source[$key] ?? null;
        if (is_scalar($value)) {
            $text = trim((string) $value);
            if ($text !== '') {
                return $text;
            }
        }
    }

    return null;
}

/**
 * Resolves a capability key against both shapes the module family emits: the
 * flat dotted key and the official NESTED dict of vmware_host_facts with
 * schema=vsphere ({"runtime": {"inMaintenanceMode": ...}}). The parser only
 * knew the flat form, so a host answering in the official shape read as "not
 * known" on every runtime fact (B15). Presence and value are separate answers,
 * because "gathered as null/empty" and "not gathered" are different facts
 * (tri-state contract).
 *
 * @return array{0: bool, 1: mixed} [found, value]
 */
function ansible_capability_resolve(array $source, string $key): array
{
    if (array_key_exists($key, $source)) {
        return [true, $source[$key]];
    }

    $node = $source;
    foreach (explode('.', $key) as $part) {
        if (!is_array($node) || !array_key_exists($part, $node)) {
            return [false, null];
        }
        $node = $node[$part];
    }

    return [true, $node];
}

/** Tri-state bool: null when the key is absent, so "unknown" survives. */
function ansible_capability_bool(array $source, array $keys): ?bool
{
    foreach ($keys as $key) {
        [$found, $value] = ansible_capability_resolve($source, $key);
        if (!$found) {
            continue;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_scalar($value)) {
            return in_array(mb_strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
        }
    }

    return null;
}

/**
 * HA cluster membership from the host's dasHostState. Present and non-empty
 * means the host runs a vSphere HA agent, which is exactly the condition under
 * which ESXi disables autostart. An absent key means the property was not
 * gathered, which we cannot distinguish from "standalone" - so it stays null and
 * the portal says "not known" instead of promising the host is standalone.
 */
function ansible_capability_ha_state(array $runtime): ?bool
{
    foreach (['runtime.dasHostState', 'dasHostState'] as $key) {
        [$found, $value] = ansible_capability_resolve($runtime, $key);
        if (!$found) {
            continue;
        }
        if ($value === null || $value === '' || $value === []) {
            return false;
        }

        return true;
    }

    return null;
}

/**
 * Extracts host RAM/CPU (and an optional ESXi clock skew) from the gathered
 * facts. Field paths are best-effort with fallbacks and are verified against the
 * productive host on rollout (docs/operations/esxi-inventory.md).
 *
 * @return array<int, array{name:string, meta_json:array<string,mixed>}>
 */
function ansible_parse_inventory_hosts(mixed $facts, mixed $fetchedEpoch): array
{
    if (!is_array($facts) || $facts === []) {
        return [];
    }

    $name = trim((string) ($facts['ansible_hostname'] ?? $facts['hw_name'] ?? $facts['ansible_nodename'] ?? ''));
    if ($name === '') {
        $name = 'esxi-host';
    }

    $ramMb = null;
    if (isset($facts['ansible_memtotal_mb'])) {
        $ramMb = (int) $facts['ansible_memtotal_mb'];
    } elseif (isset($facts['hardware_memory_size'])) {
        $ramMb = intdiv((int) $facts['hardware_memory_size'], 1048576);
    }

    $meta = [
        'ram_mb' => $ramMb,
        'cpu_cores' => isset($facts['ansible_processor_cores']) ? (int) $facts['ansible_processor_cores'] : (isset($facts['hardware_num_cpu_cores']) ? (int) $facts['hardware_num_cpu_cores'] : null),
        'cpu_model' => trim((string) ($facts['hw_processor_model'] ?? ($facts['ansible_processor'][2] ?? ''))),
    ];

    // Optional ESXi clock skew: only when the facts expose a host epoch.
    $hostEpoch = $facts['ansible_host_date_time_epoch'] ?? $facts['host_date_time_epoch'] ?? null;
    if ($hostEpoch !== null && is_numeric($hostEpoch)) {
        $meta['clock_skew_seconds'] = time() - (int) $hostEpoch;
    }

    return [['name' => $name, 'meta_json' => $meta]];
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
