<?php

declare(strict_types=1);

// MECM site assessment (09.11.2026): the portal's reading of the official MECM
// site verdict. The site-health reporter stays simple: it sends the raw MECM
// verdict (SMS_SummarizerSiteStatus) and, since 2026.11.6, a bounded count of
// the site system roles (SMS_SiteSystemSummarizer.Role) and components
// (SMS_ComponentSummarizer.ComponentName) that MECM itself rates warning or
// critical. Nothing here knows a server, a site or a customer: the decision is
// made per TYPE through the one table below.
//
// Rule (ADR-0018 amendment 2026-11-09):
//  - MECM ok / warning / unknown stay as they are.
//  - MECM critical stays critical unless every critical entry is a supporting
//    type and the reporter sent the complete list. Then the site is "limited":
//    it works, one function (content, discovery, updates, cloud) does not.
//  - A type the table does not know, and a list the reporter had to cut, keep
//    the MECM verdict. The portal never rates a site better than MECM without
//    knowing every part that MECM rates critical, and never worse than MECM.
// Display only: nothing here drives a job, a retry or a wire answer.

require_once __DIR__ . '/mecm_site_parts.php';

const VIRTUSPHERE_MECM_SITE_PART_CORE = 'core';
const VIRTUSPHERE_MECM_SITE_PART_SUPPORTING = 'supporting';
const VIRTUSPHERE_MECM_SITE_PART_UNCLASSIFIED = 'unclassified';

const VIRTUSPHERE_MECM_SITE_VERDICT_OK = 'ok';
const VIRTUSPHERE_MECM_SITE_VERDICT_WARNING = 'warning';
const VIRTUSPHERE_MECM_SITE_VERDICT_LIMITED = 'limited';
const VIRTUSPHERE_MECM_SITE_VERDICT_CRITICAL = 'critical';
const VIRTUSPHERE_MECM_SITE_VERDICT_UNKNOWN = 'unknown';

/**
 * The one classification table (SSoT). Key: exact MECM type name as the
 * provider returns it. `class`: core or supporting. `label`: optional
 * language key suffix (system_status.site_role_<label>) for a role; components
 * are shown with their MECM name. A new type is one row here; the reasons and
 * sources for every row are in docs/register/2026-11-09-MSB.md.
 *
 * @var array<string, array<string, array{class: string, label?: string}>>
 */
const VIRTUSPHERE_MECM_SITE_PART_TYPES = [
    VIRTUSPHERE_SITE_PART_KIND_ROLES => [
        'SMS Site Server' => ['class' => VIRTUSPHERE_MECM_SITE_PART_CORE, 'label' => 'site_server'],
        'SMS SQL Server' => ['class' => VIRTUSPHERE_MECM_SITE_PART_CORE, 'label' => 'sql_server'],
        'SMS Provider' => ['class' => VIRTUSPHERE_MECM_SITE_PART_CORE, 'label' => 'provider'],
        'SMS Management Point' => ['class' => VIRTUSPHERE_MECM_SITE_PART_CORE, 'label' => 'management_point'],
        'SMS Distribution Point' => ['class' => VIRTUSPHERE_MECM_SITE_PART_SUPPORTING, 'label' => 'distribution_point'],
        'SMS Software Update Point' => ['class' => VIRTUSPHERE_MECM_SITE_PART_SUPPORTING, 'label' => 'software_update_point'],
        'SMS SRS Reporting Point' => ['class' => VIRTUSPHERE_MECM_SITE_PART_SUPPORTING, 'label' => 'reporting_point'],
        'SMS Fallback Status Point' => ['class' => VIRTUSPHERE_MECM_SITE_PART_SUPPORTING, 'label' => 'fallback_status_point'],
        'SMS Dmp Connector' => ['class' => VIRTUSPHERE_MECM_SITE_PART_SUPPORTING, 'label' => 'service_connection_point'],
        'SMS Cloud Proxy Connector' => ['class' => VIRTUSPHERE_MECM_SITE_PART_SUPPORTING, 'label' => 'cloud_management_gateway_connection_point'],
        'SMS State Migration Point' => ['class' => VIRTUSPHERE_MECM_SITE_PART_SUPPORTING, 'label' => 'state_migration_point'],
        'SMS Endpoint Protection Point' => ['class' => VIRTUSPHERE_MECM_SITE_PART_SUPPORTING, 'label' => 'endpoint_protection_point'],
    ],
    VIRTUSPHERE_SITE_PART_KIND_COMPONENTS => [
        'SMS_EXECUTIVE' => ['class' => VIRTUSPHERE_MECM_SITE_PART_CORE],
        'SMS_SITE_COMPONENT_MANAGER' => ['class' => VIRTUSPHERE_MECM_SITE_PART_CORE],
        'SMS_SITE_CONTROL_MANAGER' => ['class' => VIRTUSPHERE_MECM_SITE_PART_CORE],
        'SMS_HIERARCHY_MANAGER' => ['class' => VIRTUSPHERE_MECM_SITE_PART_CORE],
        'SMS_DATABASE_NOTIFICATION_MONITOR' => ['class' => VIRTUSPHERE_MECM_SITE_PART_CORE],
        'SMS_POLICY_PROVIDER' => ['class' => VIRTUSPHERE_MECM_SITE_PART_CORE],
        'SMS_MP_CONTROL_MANAGER' => ['class' => VIRTUSPHERE_MECM_SITE_PART_CORE],
        'SMS_STATE_SYSTEM' => ['class' => VIRTUSPHERE_MECM_SITE_PART_CORE],
        'SMS_STATUS_MANAGER' => ['class' => VIRTUSPHERE_MECM_SITE_PART_CORE],
        'SMS_CERTIFICATE_MANAGER' => ['class' => VIRTUSPHERE_MECM_SITE_PART_CORE],
        // User decision 09.11.: without it no content reaches any DP, so the
        // package import stalls; core although the DPs themselves are supporting.
        'SMS_DISTRIBUTION_MANAGER' => ['class' => VIRTUSPHERE_MECM_SITE_PART_CORE],
        'SMS_PACKAGE_TRANSFER_MANAGER' => ['class' => VIRTUSPHERE_MECM_SITE_PART_SUPPORTING],
        'SMS_AD_SYSTEM_DISCOVERY_AGENT' => ['class' => VIRTUSPHERE_MECM_SITE_PART_SUPPORTING],
        'SMS_AD_USER_DISCOVERY_AGENT' => ['class' => VIRTUSPHERE_MECM_SITE_PART_SUPPORTING],
        'SMS_AD_SECURITY_GROUP_DISCOVERY_AGENT' => ['class' => VIRTUSPHERE_MECM_SITE_PART_SUPPORTING],
        'SMS_AD_FOREST_DISCOVERY_MANAGER' => ['class' => VIRTUSPHERE_MECM_SITE_PART_SUPPORTING],
        'SMS_NETWORK_DISCOVERY' => ['class' => VIRTUSPHERE_MECM_SITE_PART_SUPPORTING],
        'SMS_SERVICE_CONNECTOR' => ['class' => VIRTUSPHERE_MECM_SITE_PART_SUPPORTING],
        'SMS_CLOUDCONNECTION' => ['class' => VIRTUSPHERE_MECM_SITE_PART_SUPPORTING],
        'SMS_DMP_DOWNLOADER' => ['class' => VIRTUSPHERE_MECM_SITE_PART_SUPPORTING],
        'SMS_DMP_UPLOADER' => ['class' => VIRTUSPHERE_MECM_SITE_PART_SUPPORTING],
        'SMS_CLOUD_PROXYCONNECTOR' => ['class' => VIRTUSPHERE_MECM_SITE_PART_SUPPORTING],
        'SMS_WSUS_SYNC_MANAGER' => ['class' => VIRTUSPHERE_MECM_SITE_PART_SUPPORTING],
        'SMS_WSUS_CONTROL_MANAGER' => ['class' => VIRTUSPHERE_MECM_SITE_PART_SUPPORTING],
        'SMS_WSUS_CONFIGURATION_MANAGER' => ['class' => VIRTUSPHERE_MECM_SITE_PART_SUPPORTING],
        'SMS_SRS_REPORTING_POINT' => ['class' => VIRTUSPHERE_MECM_SITE_PART_SUPPORTING],
        'SMS_FALLBACK_STATUS_POINT' => ['class' => VIRTUSPHERE_MECM_SITE_PART_SUPPORTING],
        'SMS_ENDPOINT_PROTECTION_MANAGER' => ['class' => VIRTUSPHERE_MECM_SITE_PART_SUPPORTING],
        'SMS_SOFTWARE_METERING_PROCESSOR' => ['class' => VIRTUSPHERE_MECM_SITE_PART_SUPPORTING],
        'SMS_INVENTORY_DATA_LOADER' => ['class' => VIRTUSPHERE_MECM_SITE_PART_SUPPORTING],
        'SMS_SITE_BACKUP' => ['class' => VIRTUSPHERE_MECM_SITE_PART_SUPPORTING],
        'SMS_SITE_VSS_WRITER' => ['class' => VIRTUSPHERE_MECM_SITE_PART_SUPPORTING],
        'SMS_COMPONENT_STATUS_SUMMARIZER' => ['class' => VIRTUSPHERE_MECM_SITE_PART_SUPPORTING],
        'SMS_SITE_SYSTEM_STATUS_SUMMARIZER' => ['class' => VIRTUSPHERE_MECM_SITE_PART_SUPPORTING],
    ],
];

/** Class of one MECM type: core, supporting or unclassified (MECM verdict applies). */
function mecm_site_part_class(string $kind, string $name): string
{
    return VIRTUSPHERE_MECM_SITE_PART_TYPES[$kind][$name]['class'] ?? VIRTUSPHERE_MECM_SITE_PART_UNCLASSIFIED;
}

/** Language key suffix for a known role, or null (shown with its MECM name). */
function mecm_site_part_label(string $kind, string $name): ?string
{
    return VIRTUSPHERE_MECM_SITE_PART_TYPES[$kind][$name]['label'] ?? null;
}

/**
 * The MECM verdict of a stored site row, before any reading of its parts.
 *
 * @param array<string,mixed> $row
 */
function mecm_site_reported_verdict(array $row): string
{
    $category = (string) ($row['last_error_category'] ?? '');
    $status = (string) ($row['last_status'] ?? '');
    if ($category === VIRTUSPHERE_RUN_ERROR_SITE_CRITICAL) {
        return VIRTUSPHERE_MECM_SITE_VERDICT_CRITICAL;
    }
    if ($category === VIRTUSPHERE_RUN_ERROR_SITE_WARNING || $status === VIRTUSPHERE_RUN_OUTCOME_WARNING) {
        return VIRTUSPHERE_MECM_SITE_VERDICT_WARNING;
    }

    return $status === VIRTUSPHERE_RUN_OUTCOME_OK ? VIRTUSPHERE_MECM_SITE_VERDICT_OK : VIRTUSPHERE_MECM_SITE_VERDICT_UNKNOWN;
}

/**
 * The stored part counts of a site row, or null when the reporter sent none
 * (older script, failed detail query) or the stored value is not the validated
 * shape. Read defensively: a row is display input, never trusted structure.
 *
 * @param array<string,mixed> $row
 * @return array{roles: list<array{name: string, status: int, count: int}>, components: list<array{name: string, status: int, count: int}>, omitted: int}|null
 */
function mecm_site_stored_parts(array $row): ?array
{
    $summary = !empty($row['last_summary']) ? json_decode((string) $row['last_summary'], true) : null;
    if (!is_array($summary) || !isset($summary[VIRTUSPHERE_SITE_PARTS_FIELD]) || !is_array($summary[VIRTUSPHERE_SITE_PARTS_FIELD])) {
        return null;
    }
    $checked = mecm_site_parts_shape($summary[VIRTUSPHERE_SITE_PARTS_FIELD]);

    return $checked;
}

/**
 * The portal's reading of one stored site row (see the rule at the top).
 *
 * `parts` merges the warning and critical counts of one type into one line,
 * critical lines first. `explained` is false when the portal had to keep the
 * MECM verdict because it lacked the complete critical list.
 *
 * @param array<string,mixed> $row
 * @return array{verdict: string, reported: string, has_parts: bool, explained: bool, omitted: int, parts: list<array{kind: string, name: string, class: string, label: ?string, critical: int, warning: int}>, critical: array<string,int>, warning: array<string,int>}
 */
function mecm_site_assessment(array $row): array
{
    $reported = mecm_site_reported_verdict($row);
    $stored = mecm_site_stored_parts($row);
    $parts = [];
    $critical = array_fill_keys(VIRTUSPHERE_SITE_PART_KINDS, 0);
    $warning = array_fill_keys(VIRTUSPHERE_SITE_PART_KINDS, 0);
    $onlySupportingCritical = true;
    foreach (VIRTUSPHERE_SITE_PART_KINDS as $kind) {
        $byName = [];
        foreach ($stored[$kind] ?? [] as $entry) {
            $name = $entry['name'];
            $byName[$name] ??= [
                'kind' => $kind,
                'name' => $name,
                'class' => mecm_site_part_class($kind, $name),
                'label' => mecm_site_part_label($kind, $name),
                'critical' => 0,
                'warning' => 0,
            ];
            if ($entry['status'] === VIRTUSPHERE_SITE_PART_STATUS_CRITICAL) {
                $byName[$name]['critical'] += $entry['count'];
                $critical[$kind] += $entry['count'];
                if ($byName[$name]['class'] !== VIRTUSPHERE_MECM_SITE_PART_SUPPORTING) {
                    $onlySupportingCritical = false;
                }
            } else {
                $byName[$name]['warning'] += $entry['count'];
                $warning[$kind] += $entry['count'];
            }
        }
        foreach ($byName as $part) {
            $parts[] = $part;
        }
    }
    usort($parts, static fn (array $a, array $b): int => [$b['critical'] > 0, $b['critical'], $b['warning'], $a['kind'], $a['name']]
        <=> [$a['critical'] > 0, $a['critical'], $a['warning'], $b['kind'], $b['name']]);

    $omitted = $stored[VIRTUSPHERE_SITE_PARTS_OMITTED] ?? 0;
    $complete = $stored !== null && $omitted === 0;
    $verdict = $reported;
    $explained = $reported !== VIRTUSPHERE_MECM_SITE_VERDICT_CRITICAL;
    if ($reported === VIRTUSPHERE_MECM_SITE_VERDICT_CRITICAL && $complete && array_sum($critical) > 0) {
        $explained = true;
        if ($onlySupportingCritical) {
            $verdict = VIRTUSPHERE_MECM_SITE_VERDICT_LIMITED;
        }
    }

    return [
        'verdict' => $verdict,
        'reported' => $reported,
        'has_parts' => $stored !== null,
        'explained' => $explained,
        'omitted' => $omitted,
        'parts' => $parts,
        'critical' => $critical,
        'warning' => $warning,
    ];
}

/** Heartbeat-rank state of a site verdict, for the worst-of roll-ups. */
function mecm_site_verdict_rank_state(string $verdict): string
{
    return match ($verdict) {
        VIRTUSPHERE_MECM_SITE_VERDICT_CRITICAL => 'danger',
        VIRTUSPHERE_MECM_SITE_VERDICT_LIMITED, VIRTUSPHERE_MECM_SITE_VERDICT_WARNING => 'warning',
        VIRTUSPHERE_MECM_SITE_VERDICT_OK => 'ok',
        default => 'unknown',
    };
}
