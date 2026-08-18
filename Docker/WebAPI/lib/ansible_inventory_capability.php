<?php

declare(strict_types=1);

/**
 * ESXi capability facts and host facts of one inventory pull (ADR-0023).
 * Split out of lib/ansible_inventory.php (Etappe 7, ADR-0006): the tri-state
 * capability readers and the host RAM/CPU/clock-skew extractor share the same
 * "absent means not known" contract, unchanged.
 */

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
