<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/esxi_object_names.php';

/**
 * Pure automatic-datacenter decision. $now is injected once per request.
 *
 * @param list<array<string,mixed>> $rows
 * @return array<string,mixed>
 */
function esxi_datacenter_resolution(array $rows, mixed $freshness, mixed $semantics, mixed $observation, int $now): array
{
    $observation = is_array($observation) ? $observation : [];
    $result = [
        'resolution' => 'never_confirmed',
        'name' => null,
        'confirmed_at' => is_string($freshness) && $freshness !== '' ? $freshness : null,
        'age_seconds' => null,
        'remaining_seconds' => null,
        'semantics' => in_array($semantics, [1, 2], true) ? $semantics : 'unknown',
        'supported_name_count' => 0,
        'observation' => [
            'attempted_at' => isset($observation['attempted_at']) ? (string) $observation['attempted_at'] : null,
            'outcome' => isset($observation['outcome']) ? (string) $observation['outcome'] : null,
            'reason_code' => isset($observation['reason_code']) ? (string) $observation['reason_code'] : null,
            'job_id' => isset($observation['job_id']) ? (int) $observation['job_id'] : null,
        ],
    ];
    if ($semantics !== 2) {
        $result['resolution'] = 'semantics_unverified';
        return $result;
    }

    $supported = [];
    $sourceCount = 0;
    $hasUnsupportedName = false;
    foreach ($rows as $row) {
        $name = (string) ($row['name'] ?? '');
        $classification = esxi_object_name_classify_raw($name);
        if (!$classification['supported']) {
            $hasUnsupportedName = true;
            continue;
        }
        $meta = is_array($row['meta_json'] ?? null)
            ? $row['meta_json']
            : json_decode((string) ($row['meta_json'] ?? ''), true);
        $count = is_array($meta) ? max(1, (int) ($meta['source_count'] ?? 1)) : 1;
        $supported[$name] = true;
        $sourceCount += $count;
    }
    $names = array_keys($supported);
    sort($names, SORT_STRING);
    $result['supported_name_count'] = count($names);

    if ($freshness === null || !is_string($freshness) || $freshness === '') {
        $result['resolution'] = 'never_confirmed';
        return $result;
    }
    $confirmed = strtotime($freshness . (preg_match('/(?:Z|[+-]\d\d:\d\d)$/', $freshness) === 1 ? '' : ' UTC'));
    if ($confirmed === false || $confirmed > $now) {
        $result['resolution'] = 'timestamp_invalid';
        return $result;
    }
    $age = $now - $confirmed;
    $result['age_seconds'] = $age;
    $result['remaining_seconds'] = max(0, VIRTUSPHERE_ESXI_DATACENTER_DERIVATION_MAX_AGE_SECONDS - $age);
    if ($age > VIRTUSPHERE_ESXI_DATACENTER_DERIVATION_MAX_AGE_SECONDS) {
        $result['resolution'] = 'expired';
        return $result;
    }
    if ($hasUnsupportedName) {
        $result['resolution'] = 'unsupported';
        return $result;
    }
    if ($names === []) {
        // Semantics-v2 freshness belongs to the last fully answered snapshot.
        // A later failed attempt is a separate observation axis and cannot
        // reinterpret that durable zero-match evidence as an unsupported name.
        $result['resolution'] = 'answered_empty';
        return $result;
    }
    if (count($names) !== 1 || $sourceCount !== 1) {
        $result['resolution'] = 'ambiguous';
        return $result;
    }

    $result['resolution'] = 'resolved';
    $result['name'] = $names[0];
    return $result;
}

function esxi_datacenter_blocker_code(array $resolution): string
{
    return match ((string) ($resolution['resolution'] ?? 'never_confirmed')) {
        'expired' => 'datacenter_name_expired',
        'semantics_unverified' => 'datacenter_name_semantics_unverified',
        'answered_empty' => 'datacenter_name_answered_empty',
        'ambiguous' => 'datacenter_name_ambiguous',
        'unsupported' => 'datacenter_name_unsupported',
        'timestamp_invalid' => 'datacenter_name_timestamp_invalid',
        default => 'datacenter_name_never_confirmed',
    };
}
