<?php

declare(strict_types=1);

/**
 * The one canonical reading of an uploaded mission transfer document (ADR-0021).
 *
 * Preview and write used to read the SAME file twice, in two different ways: the
 * report counted `(array) ($vm['interfaces'] ?? [])` while the write projected
 * VIRTUSPHERE_MISSION_TRANSFER_INTERFACE_FIELDS out of it. Both castings are
 * silent, and they disagree in every direction that matters. `interfaces: "oops"`
 * counted as ONE interface and produced no finding at all, so the operator
 * confirmed an unblocked import; `repo_replace_disks()` then wrote zero disks for
 * the same string, because it takes the is_iterable() branch instead. An extra
 * `interfaces[*].mac` in the file blocked the preview over a value the write
 * deliberately drops. Nothing noticed, because a cast never fails.
 *
 * This module canonicalizes ONCE, before anything counts, validates or writes:
 * a known field list per container, list-shape decided by array_is_list() rather
 * than by a cast, every rejected shape reported with its position, and unknown
 * fields (MAC, ids, runtime state) dropped without a word. What comes out is
 * exactly what the write receives, so a count in the preview is a promise.
 *
 * It performs no query and no write, which is the point: every shape edge is a
 * fast unit test, and no shape decision can hide behind a database fixture.
 */

require_once __DIR__ . '/validate.php';
require_once __DIR__ . '/repo/missions.php';
require_once __DIR__ . '/repo/vms.php';

/**
 * A document that cannot be read at all: wrong format version, or a top-level
 * shape that carries no mission/VM structure to report on.
 *
 * Typed rather than a bare RuntimeException so the portal can tell "this file is
 * not importable" (localized, hand-off dropped) apart from an unexpected fault
 * (reference plus one server-log line). Its message is already localized.
 */
final class MissionTransferDocumentException extends RuntimeException
{
}

/**
 * Canonicalizes one decoded transfer document.
 *
 * @param array<string, mixed> $payload Decoded JSON document, untrusted.
 * @return array{
 *     format_version: int,
 *     document_error: string,
 *     exported_at: string,
 *     mission: array<string, mixed>,
 *     suggested_name: string,
 *     vms: list<array<string, mixed>>,
 *     mission_shape_errors: list<string>,
 *     vm_shape_errors: list<string>,
 *     counts: array{vms: int, interfaces: int, disks: int, packages: int}
 * }
 */
function mission_transfer_document_analyze(array $payload): array
{
    $analysis = [
        'format_version' => 0,
        'document_error' => '',
        'exported_at' => '',
        'mission' => [],
        'suggested_name' => '',
        'vms' => [],
        'mission_shape_errors' => [],
        'vm_shape_errors' => [],
        'counts' => ['vms' => 0, 'interfaces' => 0, 'disks' => 0, 'packages' => 0],
    ];

    $version = $payload['format_version'] ?? null;
    $analysis['format_version'] = is_scalar($version) ? (int) $version : 0;
    if ($analysis['format_version'] !== VIRTUSPHERE_MISSION_EXPORT_VERSION) {
        $analysis['document_error'] = validator_text('missions.import_err_version', 'Unknown export version. The file comes from a different version.');

        return $analysis;
    }

    // array_is_list() and not a cast: a JSON object under "vms" is a document
    // whose VM list was never a list, and reading it as one would import rows
    // nobody wrote. The mission block is the opposite shape and only has to be
    // an array; an unknown key in it is ignored below, not an error.
    $missionSrc = $payload['mission'] ?? null;
    $vmsSrc = $payload['vms'] ?? null;
    if (!is_array($missionSrc) || !is_array($vmsSrc) || !array_is_list($vmsSrc)) {
        $analysis['document_error'] = validator_text('missions.import_err_structure', 'The export document is malformed and cannot be read.');

        return $analysis;
    }

    $analysis['exported_at'] = mission_transfer_document_timestamp($payload['exported_at'] ?? null);
    // Only the suggestion for the editable target field, never a written value:
    // a broken shape here becomes an empty field, not a finding the operator
    // cannot act on.
    $analysis['suggested_name'] = is_scalar($missionSrc['mission_name'] ?? null)
        ? trim((string) $missionSrc['mission_name'])
        : '';

    [$analysis['mission'], $analysis['mission_shape_errors']] = mission_transfer_document_mission($missionSrc);

    foreach ($vmsSrc as $index => $vmSrc) {
        $position = $index + 1;
        if (!is_array($vmSrc)) {
            // Reported, not thrown: the preview stays renderable for the rest of
            // the file, and the finding blocks the write on its own.
            $analysis['vm_shape_errors'][] = validator_text(
                'validate.mission_import_vm_entry_invalid',
                'VM no. :position in the file is not a readable entry.',
                ['position' => $position]
            );
            continue;
        }

        $vm = mission_transfer_document_vm($vmSrc, $position);
        foreach ($vm['errors'] as $message) {
            $analysis['vm_shape_errors'][] = $vm['label'] . ': ' . $message;
        }
        unset($vm['errors']);

        $analysis['vms'][] = $vm;
        // Counted from the canonical entries alone. A discarded scalar raises no
        // counter, so for any report that is not blocked the counts are exactly
        // the units the write processes.
        $analysis['counts']['vms']++;
        $analysis['counts']['interfaces'] += count($vm['interfaces']);
        $analysis['counts']['disks'] += count($vm['disks']);
        $analysis['counts']['packages'] += count($vm['packages']);
    }

    return $analysis;
}

/**
 * Display metadata, never a written value. Anything that is not an ISO-style
 * date becomes '' so the preview omits the row: portal_format_datetime() hands
 * an unparseable string straight back, which would render arbitrary file content
 * under the heading "exported on".
 */
function mission_transfer_document_timestamp(mixed $value): string
{
    if (!is_scalar($value)) {
        return '';
    }
    $raw = trim((string) $value);
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $raw) !== 1) {
        return '';
    }

    try {
        new DateTimeImmutable($raw);
    } catch (Throwable) {
        return '';
    }

    return $raw;
}

/**
 * Projects the writable mission block.
 *
 * REPO_MISSION_COPYABLE_COLUMNS is the field-list SSoT, so mission_name and
 * mission_status are absent by construction: the write sets the first from the
 * confirm form and always replaces the second with
 * VIRTUSPHERE_MISSION_STATUS_DEFAULT. A mission_status in the file is therefore
 * neither validated nor written, and must not be able to block a preview.
 *
 * An explicit null is read as absent (forward compatible), a non-scalar as a
 * finding plus the safe canonical replacement repo_mission_copyable_values()
 * applies for an absent key.
 *
 * @param array<string, mixed> $missionSrc
 * @return array{0: array<string, mixed>, 1: list<string>}
 */
function mission_transfer_document_mission(array $missionSrc): array
{
    $errors = [];
    $clean = [];
    foreach (REPO_MISSION_COPYABLE_COLUMNS as $column) {
        if (!array_key_exists($column, $missionSrc) || $missionSrc[$column] === null) {
            continue;
        }
        if (!is_scalar($missionSrc[$column])) {
            $errors[] = mission_transfer_document_scalar_error('mission.' . $column);
            continue;
        }
        $clean[$column] = $missionSrc[$column];
    }

    return [repo_mission_copyable_values($clean), $errors];
}

/**
 * Projects one VM entry onto REPO_VM_COLUMNS plus its three sub-containers.
 *
 * Everything outside those field lists is unknown to the transfer format and is
 * dropped here: interfaces[*].mac, ids, MECM and lifecycle state. Dropped means
 * dropped in both directions - such a field can neither be written nor produce a
 * finding, which is why an invalid MAC in a file no longer blocks a preview over
 * a value the import never carries.
 *
 * @param array<string, mixed> $vmSrc
 * @return array<string, mixed>
 */
function mission_transfer_document_vm(array $vmSrc, int $position): array
{
    $errors = [];
    $fields = [];
    foreach (REPO_VM_COLUMNS as $column) {
        if (!array_key_exists($column, $vmSrc) || $vmSrc[$column] === null) {
            continue;
        }
        if (!is_scalar($vmSrc[$column])) {
            $errors[] = mission_transfer_document_scalar_error($column);
            continue;
        }
        $fields[$column] = $vmSrc[$column];
    }

    $name = isset($fields['vm_name']) ? trim((string) $fields['vm_name']) : '';

    [$interfaces, $interfaceErrors] = mission_transfer_document_rows($vmSrc, 'interfaces', VIRTUSPHERE_MISSION_TRANSFER_INTERFACE_FIELDS);
    [$disks, $diskErrors] = mission_transfer_document_rows($vmSrc, 'disks', VIRTUSPHERE_MISSION_TRANSFER_DISK_FIELDS);
    [$packages, $packageErrors] = mission_transfer_document_packages($vmSrc);

    return [
        'position' => $position,
        // The same rule the importer already used for a field error: the name when there
        // is one, the bare position otherwise, because a blank or repeated name
        // is not a handle. Assembled here and never passed through a catalog.
        'label' => $name !== '' ? $name : '#' . $position,
        'vm_name' => $name,
        'fields' => $fields,
        'interfaces' => $interfaces,
        'disks' => $disks,
        'packages' => $packages,
        'errors' => array_merge($errors, $interfaceErrors, $diskErrors, $packageErrors),
    ];
}

/**
 * One sub-container (interfaces, disks) projected onto its field-list SSoT.
 *
 * A non-list becomes [] plus a finding, so a string can no longer count as one
 * interface; a non-array entry is skipped with its position; a known field that
 * is not scalar is reported and replaced with '', never cast to the string
 * "Array".
 *
 * @param array<string, mixed> $vmSrc
 * @param list<string> $fieldList
 * @return array{0: list<array<string, string>>, 1: list<string>}
 */
function mission_transfer_document_rows(array $vmSrc, string $field, array $fieldList): array
{
    if (!array_key_exists($field, $vmSrc) || $vmSrc[$field] === null) {
        return [[], []];
    }

    $raw = $vmSrc[$field];
    if (!is_array($raw) || !array_is_list($raw)) {
        return [[], [mission_transfer_document_list_error($field)]];
    }

    $rows = [];
    $errors = [];
    foreach ($raw as $index => $entry) {
        $position = $index + 1;
        if (!is_array($entry)) {
            $errors[] = mission_transfer_document_entry_error($field, $position);
            continue;
        }

        $row = [];
        foreach ($fieldList as $name) {
            $value = $entry[$name] ?? null;
            if ($value !== null && !is_scalar($value)) {
                $errors[] = mission_transfer_document_scalar_error($field . '[' . $position . '].' . $name);
                $value = null;
            }
            // Stringified exactly as the write already did, so the repo validator
            // sees one value set in the preview and in the transaction.
            $row[$name] = $value === null ? '' : (string) $value;
        }
        $rows[] = $row;
    }

    return [$rows, $errors];
}

/**
 * Package references, keyed by VIRTUSPHERE_MISSION_TRANSFER_PACKAGE_FIELDS.
 *
 * A reference without a name is a file finding and blocks, where it used to be
 * dropped in silence: "the file lists a package link the import will not make"
 * is data loss, and confirming it unknowingly is the thing this report exists to
 * prevent. A well-formed reference the catalog cannot resolve stays the
 * non-blocking warning it always was.
 *
 * @param array<string, mixed> $vmSrc
 * @return array{0: list<array{name: string, version: string}>, 1: list<string>}
 */
function mission_transfer_document_packages(array $vmSrc): array
{
    if (!array_key_exists('packages', $vmSrc) || $vmSrc['packages'] === null) {
        return [[], []];
    }

    $raw = $vmSrc['packages'];
    if (!is_array($raw) || !array_is_list($raw)) {
        return [[], [mission_transfer_document_list_error('packages')]];
    }

    $rows = [];
    $errors = [];
    foreach ($raw as $index => $entry) {
        $position = $index + 1;
        if (!is_array($entry)) {
            $errors[] = mission_transfer_document_entry_error('packages', $position);
            continue;
        }

        $row = [];
        $nameBroken = false;
        foreach (array_keys(VIRTUSPHERE_MISSION_TRANSFER_PACKAGE_FIELDS) as $name) {
            $value = $entry[$name] ?? null;
            if ($value !== null && !is_scalar($value)) {
                $errors[] = mission_transfer_document_scalar_error('packages[' . $position . '].' . $name);
                $nameBroken = $nameBroken || $name === 'name';
                $value = null;
            }
            $row[$name] = $value === null ? '' : trim((string) $value);
        }

        if ($row['name'] === '') {
            // A name that was reported as non-scalar one line above is the same
            // finding twice; say it once.
            if (!$nameBroken) {
                $errors[] = validator_text(
                    'validate.mission_import_package_name_required',
                    'Package link :position has no name.',
                    ['position' => $position]
                );
            }
            continue;
        }
        $rows[] = $row;
    }

    return [$rows, $errors];
}

function mission_transfer_document_list_error(string $field): string
{
    return validator_text('validate.mission_import_list_required', ':field must be a list of entries.', ['field' => $field]);
}

function mission_transfer_document_entry_error(string $field, int $position): string
{
    return validator_text(
        'validate.mission_import_entry_invalid',
        'Entry :position in :field is not a readable entry.',
        ['field' => $field, 'position' => $position]
    );
}

function mission_transfer_document_scalar_error(string $field): string
{
    return validator_text('validate.mission_import_scalar_required', ':field must be a single value.', ['field' => $field]);
}
