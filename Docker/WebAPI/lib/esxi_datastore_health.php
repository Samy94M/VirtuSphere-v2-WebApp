<?php

declare(strict_types=1);

/**
 * Whether a cached datastore's free space may be read as available space.
 *
 * Three renderers ask the same question and used to answer it three ways: the
 * inventory detail printed `(int) null` as "0 B free", the deploy table called
 * the same NULL "unknown", and neither knew that a datastore can be in
 * maintenance mode, where its free space is not space anybody can deploy onto.
 * One predicate, three callers.
 *
 * Every fact is tri-state, like the host capabilities (ADR-0023 amendment 3):
 * an absent field means "not known" and must never be read as healthy. The
 * reverse also holds, and is why an unknown health never suppresses a number:
 * the cache is a mirror and may not turn silence into a refusal
 * (cache-never-blocks).
 */

/**
 * vSphere's DatastoreSummaryMaintenanceModeState values that mean the datastore
 * is not available for placement. `normal` is the fourth, and the only one that
 * is not in here. Compared lower-cased; some module versions report a bool.
 */
const VIRTUSPHERE_ESXI_DATASTORE_MAINTENANCE_STATES = ['inmaintenance', 'enteringmaintenance'];

/**
 * Normalizes a cached datastore row's meta into the two health facts.
 *
 * @param mixed $metaJson the meta_json column (a JSON string), a decoded array, or null
 * @return array{accessible:?bool, maintenance:?bool}
 */
function esxi_datastore_health(mixed $metaJson): array
{
    $meta = is_string($metaJson) ? json_decode($metaJson, true) : $metaJson;
    if (!is_array($meta)) {
        return ['accessible' => null, 'maintenance' => null];
    }

    return [
        'accessible' => array_key_exists('accessible', $meta) ? esxi_datastore_flag($meta['accessible']) : null,
        'maintenance' => array_key_exists('maintenance', $meta) ? esxi_datastore_maintenance_flag($meta['maintenance']) : null,
    ];
}

/** Tri-state bool from whatever the module reported; null when it is unusable. */
function esxi_datastore_flag(mixed $value): ?bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (!is_scalar($value)) {
        return null;
    }
    $text = mb_strtolower(trim((string) $value));

    return match ($text) {
        '1', 'true', 'yes', 'on' => true,
        '0', 'false', 'no', 'off' => false,
        default => null,
    };
}

/**
 * Maintenance state from either the vSphere enum string or a plain bool. An
 * unrecognized string stays null rather than being read as "normal": guessing
 * healthy is the one answer this module may not give.
 */
function esxi_datastore_maintenance_flag(mixed $value): ?bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (!is_scalar($value)) {
        return null;
    }
    $text = mb_strtolower(trim((string) $value));
    if ($text === '' ) {
        return null;
    }
    if (in_array($text, VIRTUSPHERE_ESXI_DATASTORE_MAINTENANCE_STATES, true)) {
        return true;
    }

    return $text === 'normal' ? false : esxi_datastore_flag($value);
}

/**
 * True when the datastore is provably not usable as a deploy target right now:
 * in (or entering) maintenance, or reported inaccessible. Unknown is not
 * unusable; a cache that did not report a field may not withdraw a number.
 *
 * @param mixed $metaJson the meta_json column, a decoded array, or null
 */
function esxi_datastore_is_unusable(mixed $metaJson): bool
{
    $health = esxi_datastore_health($metaJson);

    return $health['maintenance'] === true || $health['accessible'] === false;
}

/**
 * The free bytes a caller may present as available, or null for "unknown".
 *
 * The single reader of the two ways a number can be missing: the cache never
 * had one, or it has one that no longer means anything. Both render the same
 * way, because both answer the operator's question identically.
 *
 * @param mixed $metaJson the meta_json column, a decoded array, or null
 */
function esxi_datastore_usable_free_bytes(?int $freeBytes, mixed $metaJson = null): ?int
{
    if ($freeBytes === null || esxi_datastore_is_unusable($metaJson)) {
        return null;
    }

    return $freeBytes;
}
