<?php

declare(strict_types=1);

/**
 * Collecting field errors out of the repo list validators for the import
 * preview. Split out of mission_transfer_import.php so both modules stay inside
 * the ADR-0006 budget; loaded by mission_transfer.php before the importer.
 */

/**
 * Runs a repo list validator over a canonical sub-list and returns EVERY message.
 *
 * repo_validate_interfaces()/repo_validate_disks() call throwIfInvalid() per
 * entry, so they stop at the first broken one: a file with three bad interfaces
 * reported one, the operator fixed it, uploaded again and met the next. The list
 * runs FIRST and unchanged, because a rule that only holds over the whole list
 * (the network/VLAN contract planned as Etappe 14A) has no per-entry equivalent
 * and must never be replaced by one; only when that run fails does this collect
 * per entry, and if no single entry fails alone the list-wide messages are the
 * answer. No rule is copied here.
 *
 * @param callable(array): mixed $validate the repo validator, called with a list
 * @param list<array<string, string>> $rows canonical rows
 * @return list<string>
 */
function mission_import_list_field_errors(callable $validate, string $field, array $rows): array
{
    try {
        $validate($rows);

        return [];
    } catch (ValidationException $listException) {
        // Collected per entry below; $listException stays the fallback.
    }

    $errors = [];
    foreach ($rows as $index => $row) {
        try {
            $validate([$row]);
        } catch (ValidationException $entryException) {
            foreach ($entryException->errors() as $key => $message) {
                $errors[] = mission_import_field_path($field, $key, $index + 1) . ': ' . $message;
            }
        }
    }

    return $errors !== [] ? $errors : array_values($listException->errors());
}

/**
 * Rewrites a repo validator field key onto the position in the FILE.
 *
 * The per-entry run above validates one-element lists, so every key comes back
 * as `interfaces.0.ip`; the operator needs the real position. Like the VM label,
 * this path is assembled here and never passes through a language catalog - it
 * is a technical locator inside the uploaded document, not prose.
 */
function mission_import_field_path(string $field, string $key, int $position): string
{
    $prefix = $field . '.0.';
    if (str_starts_with($key, $prefix)) {
        return $field . '[' . $position . '].' . substr($key, strlen($prefix));
    }

    return $field . '[' . $position . ']';
}
