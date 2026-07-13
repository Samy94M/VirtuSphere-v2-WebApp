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

    $vlanRaw = $raw['vlan_id'] ?? $raw['vlan'] ?? null;
    $vlanId = null;
    $trunk = false;
    if (is_int($vlanRaw) || (is_string($vlanRaw) && preg_match('/^\d+$/', trim($vlanRaw)) === 1)) {
        $vlanId = (int) $vlanRaw;
    } elseif ($vlanRaw !== null && $vlanRaw !== '') {
        $trunk = true;
    }

    return ['name' => $name, 'meta_json' => ['vlan_id' => $vlanId, 'trunk' => $trunk]];
}

/**
 * Parses the base64-JSON marker the inventory playbook prints into the cache
 * shape. Throws when the marker is missing/corrupt (worker records "parse").
 *
 * @return array{datacenters:array<int,string>, datastores:array<int,array<string,mixed>>, networks:array<int,array<string,mixed>>, hosts:array<int,array<string,mixed>>}
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

    $datacenters = array_values(array_filter(array_map(static fn ($v): string => trim((string) $v), (array) ($data['datacenters'] ?? [])), static fn (string $v): bool => $v !== ''));

    $datastores = [];
    foreach ((array) ($data['datastores'] ?? []) as $ds) {
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
        ];
    }

    // Raw portgroup objects (or legacy plain names) from both module kinds,
    // de-duplicated case-insensitively; the first item's meta wins.
    $networks = [];
    foreach (['standard' => (array) ($data['networks_standard'] ?? []), 'dvs' => (array) ($data['networks_dvs'] ?? [])] as $source => $entries) {
        foreach ($entries as $raw) {
            $item = ansible_inventory_network_item($raw, $source);
            if ($item === null) {
                continue;
            }
            $key = esxi_inventory_name_key($item['name']);
            if (!isset($networks[$key])) {
                $networks[$key] = $item;
            }
        }
    }
    $networks = array_values($networks);

    return [
        'datacenters' => $datacenters,
        'datastores' => $datastores,
        'networks' => $networks,
        'hosts' => ansible_parse_inventory_hosts($data['hosts'] ?? [], $data['fetched_epoch'] ?? null),
        'capabilities' => ansible_parse_inventory_capabilities($data['about'] ?? [], $data['host_runtime'] ?? []),
    ];
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

/** Tri-state bool: null when the key is absent, so "unknown" survives. */
function ansible_capability_bool(array $source, array $keys): ?bool
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $source)) {
            continue;
        }
        $value = $source[$key];
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
        if (!array_key_exists($key, $runtime)) {
            continue;
        }
        $value = $runtime[$key];
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
