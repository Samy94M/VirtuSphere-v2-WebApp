<?php

declare(strict_types=1);

// Wire shape of `site_parts` (ADR-0018 amendment 2026-11-09, MSB): an optional
// top-level object on a completed mecm-site-health report. It counts the site
// system roles (SMS_SiteSystemSummarizer.Role) and components
// (SMS_ComponentSummarizer.ComponentName) that MECM rates warning (1) or
// critical (2), per type name and status:
// `{"roles":[{"name":..,"status":2,"count":4}],"components":[..],"omitted":0}`.
// Type names only, never a server name or a MECM message: the name pattern
// admits no dot, backslash or at sign, so no FQDN, path or account fits.
// A top-level field (not a summary key) on purpose: an older portal ignores
// unknown top-level fields but rejects unknown summary keys, so a new reporter
// keeps its verdict there. The portal stores the validated object inside
// last_summary; lib/mecm_site_assessment.php reads it through the type table.
// Own module like lib/device_sync_cause_constants.php, so the constant registry
// does not grow. Mirror: $script:VsSitePart* in
// Powershell-MECM/mecm/VirtuSphere-Common.ps1 (VirtuSphere.SiteParts.Tests.ps1).

require_once __DIR__ . '/constants.php';

const VIRTUSPHERE_SITE_PARTS_FIELD = 'site_parts';
const VIRTUSPHERE_SITE_PART_KIND_ROLES = 'roles';
const VIRTUSPHERE_SITE_PART_KIND_COMPONENTS = 'components';
const VIRTUSPHERE_SITE_PART_KINDS = [
    VIRTUSPHERE_SITE_PART_KIND_ROLES,
    VIRTUSPHERE_SITE_PART_KIND_COMPONENTS,
];
const VIRTUSPHERE_SITE_PARTS_OMITTED = 'omitted';
const VIRTUSPHERE_SITE_PART_STATUS_WARNING = 1;
const VIRTUSPHERE_SITE_PART_STATUS_CRITICAL = 2;
const VIRTUSPHERE_SITE_PART_STATUSES = [
    VIRTUSPHERE_SITE_PART_STATUS_WARNING,
    VIRTUSPHERE_SITE_PART_STATUS_CRITICAL,
];
const VIRTUSPHERE_SITE_PART_NAME_PATTERN = '/\A[A-Za-z][A-Za-z0-9 _-]{0,63}\z/';
const VIRTUSPHERE_SITE_PART_MAX_ENTRIES = 24;

/**
 * Shared shape check of a site_parts object (wire and storage). Returns the
 * normalized object or null. Each kind is a list of {name, status, count} with
 * a name from the allowlist, status 1 (warning) or 2 (critical), a positive
 * count, no repeated name/status pair and at most the entry cap; `omitted`
 * counts the rows the reporter could not send.
 *
 * @param array<mixed> $parts
 * @return array{roles: list<array{name: string, status: int, count: int}>, components: list<array{name: string, status: int, count: int}>, omitted: int}|null
 */
function mecm_site_parts_shape(array $parts): ?array
{
    if (array_is_list($parts) && $parts !== []) {
        return null;
    }
    $allowedKeys = [...VIRTUSPHERE_SITE_PART_KINDS, VIRTUSPHERE_SITE_PARTS_OMITTED];
    foreach (array_keys($parts) as $key) {
        if (!is_string($key) || !in_array($key, $allowedKeys, true)) {
            return null;
        }
    }
    $clean = [];
    foreach (VIRTUSPHERE_SITE_PART_KINDS as $kind) {
        $entries = $parts[$kind] ?? [];
        if (!is_array($entries) || !array_is_list($entries) || count($entries) > VIRTUSPHERE_SITE_PART_MAX_ENTRIES) {
            return null;
        }
        $seen = [];
        $list = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || array_is_list($entry)) {
                return null;
            }
            $keys = array_keys($entry);
            sort($keys);
            if ($keys !== ['count', 'name', 'status']) {
                return null;
            }
            $name = $entry['name'];
            $status = $entry['status'];
            $count = $entry['count'];
            if (!is_string($name) || preg_match(VIRTUSPHERE_SITE_PART_NAME_PATTERN, $name) !== 1
                || !is_int($status) || !in_array($status, VIRTUSPHERE_SITE_PART_STATUSES, true)
                || !is_int($count) || $count < 1 || $count > VIRTUSPHERE_RUN_SUMMARY_VALUE_MAX
            ) {
                return null;
            }
            $pair = $name . "\0" . $status;
            if (isset($seen[$pair])) {
                return null;
            }
            $seen[$pair] = true;
            $list[] = ['name' => $name, 'status' => $status, 'count' => $count];
        }
        $clean[$kind] = $list;
    }
    $omitted = $parts[VIRTUSPHERE_SITE_PARTS_OMITTED] ?? 0;
    if (!is_int($omitted) || $omitted < 0 || $omitted > VIRTUSPHERE_RUN_SUMMARY_VALUE_MAX) {
        return null;
    }
    $clean[VIRTUSPHERE_SITE_PARTS_OMITTED] = $omitted;

    return $clean;
}
