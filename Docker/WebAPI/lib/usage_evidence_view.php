<?php

declare(strict_types=1);

// Renders the "Verwendet in" section (F08) from a reader result. The page decides
// where the section sits and whether a job may be opened (deploy.run); this module
// decides how a result reads. Every value it prints is escaped here. No credential
// field reaches it: the credential page passes only the name, which labels the row.
//
// It needs the portal bootstrap (h(), __t(), portal_format_timestamp(), the job status badge),
// so it is loaded by the portal pages and never by a CLI entrypoint.

require_once __DIR__ . '/usage_evidence.php';
require_once __DIR__ . '/defaults.php';
require_once __DIR__ . '/deploy_display.php';
require_once __DIR__ . '/deploy_urls.php';
require_once __DIR__ . '/vm_urls.php';
require_once __DIR__ . '/layout_presenters.php';
require_once __DIR__ . '/layout_response.php';

/**
 * @param array<string, mixed> $evidence a reader result from lib/repo/usage_evidence.php
 */
function usage_evidence_section_html(array $evidence, bool $canOpenJobs, bool $withHeading): string
{
    $object = (string) ($evidence['object'] ?? '');
    $notes = array_map('strval', (array) ($evidence['notes'] ?? []));
    $html = '<div class="usage-evidence">';
    if ($withHeading) {
        $html .= '<h3>' . h(__t('usage_evidence.heading')) . '</h3>';
    }
    foreach (VIRTUSPHERE_USAGE_GROUPS as $key) {
        $group = $evidence['groups'][$key] ?? null;
        if (!is_array($group) || ($group['applies'] ?? false) !== true || (int) ($group['total'] ?? 0) === 0) {
            continue;
        }
        // The groups sit one level below the section's own heading; the credential
        // disclosure has no heading of its own, so its groups follow the page's h2.
        $html .= usage_evidence_group_html($object, $key, $group, $canOpenJobs, $withHeading ? 4 : 3);
    }
    if (usage_evidence_is_unused($evidence)) {
        $html .= '<p>' . h(usage_evidence_empty_text($object, $notes)) . '</p>';
    }
    foreach ($notes as $note) {
        $text = usage_evidence_note_text($note);
        if ($text !== '') {
            $html .= '<p class="muted">' . h($text) . '</p>';
        }
    }

    return $html . '</div>';
}

/**
 * A catalog page's expansion: one full-width row under the selected record.
 */
function usage_evidence_expansion_row(string $anchorId, int $colspan, string $sectionHtml): string
{
    return '<tr class="row-usage" id="' . h($anchorId) . '"><td colspan="' . $colspan . '">' . $sectionHtml . '</td></tr>';
}

/**
 * A credential's details row. It follows the host identity row's pattern: a
 * native disclosure, no script, so the section is reachable without JavaScript.
 */
function usage_evidence_disclosure_row(string $anchorId, int $colspan, string $name, string $sectionHtml): string
{
    return '<tr class="row-usage" id="' . h($anchorId) . '"><td colspan="' . $colspan . '"><details>'
        . '<summary class="button button-secondary" aria-label="' . h(__t('usage_evidence.link_label', ['name' => $name])) . '">'
        . h(__t('usage_evidence.link')) . '</summary>'
        . $sectionHtml . '</details></td></tr>';
}

/**
 * The address of a catalog page that shows one record's expansion. The filter
 * and sort travel with it, so the selected row is still listed after the click.
 *
 * @param array<string, scalar> $query
 */
function usage_evidence_toggle_href(string $script, array $query, int $id): string
{
    $params = array_merge($query, ['usage' => $id]);
    $encoded = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

    return $script . '?' . $encoded . '#' . usage_evidence_anchor($id);
}

/**
 * The link into the section. Its accessible name starts with the visible text,
 * so a screen reader hears the same words a sighted reader sees.
 */
function usage_evidence_toggle_html(string $href, string $name): string
{
    return '<a href="' . h($href) . '" aria-label="' . h(__t('usage_evidence.link_label', ['name' => $name])) . '">'
        . h(__t('usage_evidence.link')) . '</a>';
}

/** @param array<string, mixed> $group */
function usage_evidence_group_html(string $object, string $key, array $group, bool $canOpenJobs, int $level): string
{
    $total = (int) ($group['total'] ?? 0);
    $rows = is_array($group['rows'] ?? null) ? $group['rows'] : [];
    $tag = $level === 3 ? 'h3' : 'h4';
    $html = '<div class="usage-group"><' . $tag . '>'
        . h(usage_evidence_group_heading($object, $key) . ' (' . $total . ')') . '</' . $tag . '><ul>';
    foreach ($rows as $row) {
        $html .= '<li>' . usage_evidence_row_html((array) $row, $canOpenJobs) . '</li>';
    }
    $html .= '</ul>';
    if (usage_evidence_hidden_count($total, count($rows)) > 0) {
        $html .= '<p class="muted">' . h(__t('usage_evidence.shown', [
            'shown' => (string) count($rows),
            'total' => (string) $total,
        ])) . '</p>';
    }

    return $html . '</div>';
}

/** @param array<string, mixed> $row */
function usage_evidence_row_html(array $row, bool $canOpenJobs): string
{
    if (array_key_exists('job_id', $row)) {
        return usage_evidence_job_row_html($row, $canOpenJobs);
    }
    if (array_key_exists('vm_id', $row)) {
        return usage_evidence_vm_row_html($row);
    }

    return usage_evidence_mission_row_html($row);
}

/** @param array<string, mixed> $row */
function usage_evidence_vm_row_html(array $row): string
{
    $missionId = (int) ($row['mission_id'] ?? 0);
    $missionName = (string) ($row['mission_name'] ?? '');

    return usage_evidence_object_kind_html($missionName)
        . ' <a href="' . h(mission_details_url($missionId)) . '">' . h($missionName) . '</a>'
        . ' <span class="muted" aria-hidden="true">&middot;</span> '
        . '<a href="' . h(vm_edit_url($missionId, (int) ($row['vm_id'] ?? 0))) . '">' . h((string) ($row['vm_name'] ?? '')) . '</a>';
}

/** @param array<string, mixed> $row */
function usage_evidence_mission_row_html(array $row): string
{
    $missionId = (int) ($row['mission_id'] ?? 0);
    $missionName = (string) ($row['mission_name'] ?? '');

    return usage_evidence_object_kind_html($missionName)
        . ' <a href="' . h(mission_details_url($missionId)) . '">' . h($missionName) . '</a>';
}

/**
 * A job row. Its status badge is the existing deploy status presenter, so a
 * queued job never reads as a finished one. A system job has no mission and
 * therefore no mission link (A38).
 *
 * @param array<string, mixed> $row
 */
function usage_evidence_job_row_html(array $row, bool $canOpenJobs): string
{
    $jobId = (int) ($row['job_id'] ?? 0);
    $label = __t('usage_evidence.job_label', ['id' => (string) $jobId]);
    $jobHtml = $canOpenJobs
        ? '<a href="' . h(deploy_job_log_url($jobId)) . '">' . h($label) . '</a>'
        : h($label);
    $missionId = (int) ($row['mission_id'] ?? 0);
    $targetHtml = $missionId > 0
        ? '<a href="' . h(mission_details_url($missionId)) . '">' . h((string) ($row['mission_name'] ?? '')) . '</a>'
        : '<span class="muted">' . h(__t('usage_evidence.job_system')) . '</span>';

    return deploy_job_status_badge((string) ($row['status'] ?? ''))
        . ' ' . $jobHtml
        . ' <span class="muted" aria-hidden="true">&middot;</span> ' . $targetHtml
        . ' <span class="muted">' . h(portal_format_timestamp((string) ($row['created_at'] ?? ''))) . '</span>';
}

/**
 * Template or mission, by the name prefix (mission_name_is_template(), the one
 * predicate). The label keeps the two apart: a template's VMs are blueprints that
 * clones copy, while a mission's VMs are the machines a deployment actually builds.
 */
function usage_evidence_object_kind_html(string $missionName): string
{
    $label = mission_name_is_template($missionName)
        ? __t('usage_evidence.object_template')
        : __t('usage_evidence.object_mission');

    return '<span class="muted">' . h($label) . '</span>';
}

function usage_evidence_group_heading(string $object, string $key): string
{
    if ($key === VIRTUSPHERE_USAGE_GROUP_MISSION) {
        return __t('usage_evidence.group_mission_vlan');
    }
    if ($key === VIRTUSPHERE_USAGE_GROUP_JOB_OPEN) {
        return __t('usage_evidence.group_job_open');
    }
    if ($key === VIRTUSPHERE_USAGE_GROUP_JOB_HISTORY) {
        return __t('usage_evidence.group_job_history');
    }

    return match ($object) {
        VIRTUSPHERE_USAGE_OBJECT_PACKAGE => __t('usage_evidence.group_direct_package'),
        VIRTUSPHERE_USAGE_OBJECT_OS => __t('usage_evidence.group_direct_os'),
        VIRTUSPHERE_USAGE_OBJECT_VLAN => __t('usage_evidence.group_direct_vlan'),
        default => __t('usage_evidence.group_direct_credential'),
    };
}

/**
 * The sentence for an object with no known use. It names the limit of the claim
 * where the schema cannot prove absence (a credential without a binding proof).
 *
 * @param list<string> $notes
 */
function usage_evidence_empty_text(string $object, array $notes): string
{
    return match ($object) {
        VIRTUSPHERE_USAGE_OBJECT_PACKAGE => __t('usage_evidence.empty_package'),
        VIRTUSPHERE_USAGE_OBJECT_OS => __t('usage_evidence.empty_os'),
        VIRTUSPHERE_USAGE_OBJECT_VLAN => __t('usage_evidence.empty_vlan'),
        VIRTUSPHERE_USAGE_OBJECT_CREDENTIAL => in_array(VIRTUSPHERE_USAGE_NOTE_VM_BINDING_UNPROVEN, $notes, true)
            ? __t('usage_evidence.empty_credential_esxi')
            : __t('usage_evidence.empty_credential_ansible'),
        default => __t('usage_evidence.empty_generic'),
    };
}

function usage_evidence_note_text(string $note): string
{
    return match ($note) {
        VIRTUSPHERE_USAGE_NOTE_VM_BINDING_UNPROVEN => __t('usage_evidence.note_vm_binding'),
        VIRTUSPHERE_USAGE_NOTE_ANSIBLE_JOBS_ONLY => __t('usage_evidence.note_ansible_jobs'),
        default => '',
    };
}
