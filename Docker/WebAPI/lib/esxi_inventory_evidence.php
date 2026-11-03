<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/status_evidence.php';
require_once __DIR__ . '/esxi_datacenter_presenter.php';
require_once __DIR__ . '/esxi_object_names.php';

/** @return array<string,mixed> */
function esxi_inventory_kind_evidence(?array $state, string $kind, array $rows, int $now): array
{
    $freshness = json_decode((string) ($state['kind_freshness_json'] ?? ''), true);
    $semantics = json_decode((string) ($state['kind_name_semantics_json'] ?? ''), true);
    $observations = json_decode((string) ($state['kind_observation_json'] ?? ''), true);
    $freshness = is_array($freshness) ? $freshness : [];
    $semantics = is_array($semantics) ? $semantics : [];
    $observations = is_array($observations) ? $observations : [];
    $version = (int) ($semantics[$kind] ?? 1);
    $observation = is_array($observations[$kind] ?? null) ? $observations[$kind] : [];
    $supported = 0;
    $unsupported = 0;
    foreach ($rows as $row) {
        if (esxi_object_name_classify_raw((string) ($row['name'] ?? ''))['supported']) {
            $supported++;
        } else {
            $unsupported++;
        }
    }
    $evidence = [
        'kind' => $kind,
        'freshness' => isset($freshness[$kind]) ? (string) $freshness[$kind] : null,
        'semantics' => $version === 2 ? 2 : 1,
        'observation' => $observation,
        'supported_name_count' => $supported,
        'unsupported_name_count' => $unsupported,
        'datacenter_resolution' => null,
    ];
    if ($kind === VIRTUSPHERE_INVENTORY_KIND_DATACENTER) {
        $evidence['datacenter_resolution'] = esxi_datacenter_resolution(
            $rows,
            $freshness[$kind] ?? null,
            $version,
            $observation,
            $now
        );
    }
    return $evidence;
}

/** The automation schedule does not suspend the expiry of successful evidence. */
function esxi_inventory_evidence_window_seconds(int $intervalHours): int
{
    return $intervalHours > 0
        ? VIRTUSPHERE_ESXI_INVENTORY_STALE_FACTOR * $intervalHours * 3600
        : VIRTUSPHERE_ESXI_INVENTORY_STALE_AFTER_DAYS * 86400;
}

/** @param array<string,mixed>|null $state */
function esxi_inventory_success_evidence_state(?array $state, int $intervalHours, ?int $now = null): string
{
    $current = $now ?? time();
    $timestamp = virtusphere_evidence_timestamp(isset($state['last_success_at']) ? (string) $state['last_success_at'] : null, $current);
    if ($timestamp === null) {
        return 'unknown';
    }
    return ($current - $timestamp) > esxi_inventory_evidence_window_seconds($intervalHours) ? 'stale' : 'ok';
}
