<?php

declare(strict_types=1);

require_once __DIR__ . '/esxi_inventory_evidence.php';
require_once __DIR__ . '/portal_time.php';
require_once __DIR__ . '/layout_response.php';

/** @return list<string> */
function system_status_esxi_evidence_lines(array $evidence): array
{
    $lines = [__t((int) ($evidence['semantics'] ?? 1) === 2
        ? 'system_status.inv_name_semantics_exact'
        : 'system_status.inv_name_semantics_legacy')];
    $observation = is_array($evidence['observation'] ?? null) ? $evidence['observation'] : [];
    $outcome = (string) ($observation['outcome'] ?? '');
    if ($outcome !== '') {
        $outcome = in_array($outcome, ['answered', 'failed', 'skipped'], true) ? $outcome : 'unknown';
        $lines[] = __t('system_status.inv_observation_' . $outcome, [
            'time' => portal_format_timestamp($observation['attempted_at'] ?? null),
            'reason' => (string) ($observation['reason_code'] ?? ''),
        ]);
    }
    $resolution = is_array($evidence['datacenter_resolution'] ?? null) ? $evidence['datacenter_resolution'] : null;
    if ($resolution !== null) {
        $lines[] = esxi_datacenter_detail_message($resolution);
    }
    return $lines;
}
