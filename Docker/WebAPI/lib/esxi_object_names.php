<?php

declare(strict_types=1);

require_once __DIR__ . '/network_mac_constants.php';

const VIRTUSPHERE_ESXI_OBJECT_NAME_MAX_CHARS = 255;

const VIRTUSPHERE_ESXI_NAME_EXACT = 'exact';
const VIRTUSPHERE_ESXI_NAME_CASE_MISMATCH = 'case_mismatch';
const VIRTUSPHERE_ESXI_NAME_MISSING = 'missing';
const VIRTUSPHERE_ESXI_NAME_INVENTORY_UNKNOWN = 'inventory_unknown';
const VIRTUSPHERE_ESXI_NAME_UNSUPPORTED = 'unsupported_name';

/**
 * Classifies an ESXi-owned raw object name without changing a byte of it.
 *
 * @return array{persistable:bool,supported:bool,reason:?string,name:string}
 */
function esxi_object_name_classify_raw(string $name): array
{
    if (!mb_check_encoding($name, 'UTF-8')) {
        return ['persistable' => false, 'supported' => false, 'reason' => 'invalid_utf8', 'name' => ''];
    }
    if ($name === '') {
        return ['persistable' => false, 'supported' => false, 'reason' => 'empty', 'name' => ''];
    }
    if (str_contains($name, "\0")) {
        return ['persistable' => false, 'supported' => false, 'reason' => 'nul', 'name' => ''];
    }
    if (preg_match('/[\p{Cc}\p{Cf}\p{Cs}]/u', $name) === 1) {
        return ['persistable' => false, 'supported' => false, 'reason' => 'control_character', 'name' => ''];
    }
    if (mb_strlen($name, 'UTF-8') > VIRTUSPHERE_ESXI_OBJECT_NAME_MAX_CHARS) {
        return ['persistable' => false, 'supported' => false, 'reason' => 'exceeds_internal_name_limit', 'name' => ''];
    }
    if (preg_match('/^\s*$/u', $name) === 1) {
        return ['persistable' => false, 'supported' => false, 'reason' => 'whitespace_only', 'name' => ''];
    }
    if (preg_match('/^[\p{Zs}\p{Zl}\p{Zp}]|[\p{Zs}\p{Zl}\p{Zp}]$/u', $name) === 1) {
        return ['persistable' => true, 'supported' => false, 'reason' => 'boundary_whitespace', 'name' => $name];
    }

    return ['persistable' => true, 'supported' => true, 'reason' => null, 'name' => $name];
}

function esxi_object_name_equals(string $left, string $right): bool
{
    return $left === $right;
}

/** Diagnostic only. Never use this key to authorize a write or remote action. */
function esxi_object_name_diagnostic_key(string $name): string
{
    return mb_strtolower($name, 'UTF-8');
}

/**
 * Candidate names come from an inventory whose size nothing here controls, so
 * every return carries the bounded list plus the two complete counts
 * (correction plan 16.4). An exact hit is one candidate by definition.
 *
 * @param list<string> $inventoryNames
 * @return array{state:string,configured_value:string,candidates:list<string>,candidate_total:int,candidate_omitted_count:int,unsupported_reason:?string}
 */
function esxi_object_name_match(string $configuredValue, array $inventoryNames, bool $inventoryEvaluable = true): array
{
    $configured = esxi_object_name_classify_raw($configuredValue);
    if (!$configured['persistable'] || !$configured['supported']) {
        return esxi_object_name_match_result(VIRTUSPHERE_ESXI_NAME_UNSUPPORTED, $configuredValue, [], $configured['reason']);
    }
    if (!$inventoryEvaluable) {
        return esxi_object_name_match_result(VIRTUSPHERE_ESXI_NAME_INVENTORY_UNKNOWN, $configuredValue, []);
    }

    $similar = [];
    foreach ($inventoryNames as $candidate) {
        $classification = esxi_object_name_classify_raw($candidate);
        if (!$classification['persistable'] || !$classification['supported']) {
            continue;
        }
        if (esxi_object_name_equals($configuredValue, $candidate)) {
            return esxi_object_name_match_result(VIRTUSPHERE_ESXI_NAME_EXACT, $configuredValue, [$candidate]);
        }
        if (esxi_object_name_diagnostic_key($configuredValue) === esxi_object_name_diagnostic_key($candidate)) {
            $similar[$candidate] = true;
        }
    }

    $candidates = array_keys($similar);
    sort($candidates, SORT_STRING);

    return esxi_object_name_match_result(
        $candidates === [] ? VIRTUSPHERE_ESXI_NAME_MISSING : VIRTUSPHERE_ESXI_NAME_CASE_MISMATCH,
        $configuredValue,
        $candidates
    );
}

/**
 * @param list<string> $candidates already sorted, already exact raw names
 * @return array{state:string,configured_value:string,candidates:list<string>,candidate_total:int,candidate_omitted_count:int,unsupported_reason:?string}
 */
function esxi_object_name_match_result(string $state, string $configuredValue, array $candidates, ?string $unsupportedReason = null): array
{
    $shown = array_slice($candidates, 0, VIRTUSPHERE_ESXI_SIMILAR_NAME_CANDIDATE_LIMIT);

    return [
        'state' => $state,
        'configured_value' => $configuredValue,
        'candidates' => $shown,
        'candidate_total' => count($candidates),
        'candidate_omitted_count' => count($candidates) - count($shown),
        'unsupported_reason' => $unsupportedReason,
    ];
}
