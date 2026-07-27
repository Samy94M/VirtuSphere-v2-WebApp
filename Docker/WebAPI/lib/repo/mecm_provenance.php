<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * MECM membership provenance (ADR-0034, decisions 1-3): which direct
 * membership rules are VirtuSphere's OWN. The device-sync reports the rules it
 * added or removed; the portal's transfer preview and the script's
 * reconciliation plan read this record, and a remove is only ever planned for
 * an owned rule - a rule an administrator created by hand in MECM has no row
 * here and is untouchable by construction.
 *
 * Focused module on purpose (not repo/vms.php): the provenance is a contract
 * of its own, with its own vocabularies, and the VM repo is already at its
 * size budget.
 */

// PHP-validated vocabularies (deliberately not ENUM columns; no new
// order-exact schema mirror, ADR-0016 scope unchanged).
const VIRTUSPHERE_MECM_RULE_TYPE_OS = 'os';
const VIRTUSPHERE_MECM_RULE_TYPE_PACKAGE = 'package';
const VIRTUSPHERE_MECM_RULE_TYPE_MISSION = 'mission';
const VIRTUSPHERE_MECM_RULE_TYPES = [
    VIRTUSPHERE_MECM_RULE_TYPE_OS,
    VIRTUSPHERE_MECM_RULE_TYPE_PACKAGE,
    VIRTUSPHERE_MECM_RULE_TYPE_MISSION,
];

const VIRTUSPHERE_MECM_RULE_ORIGIN_CREATED = 'created';
const VIRTUSPHERE_MECM_RULE_ORIGIN_ADOPTED = 'explicitly_adopted';
const VIRTUSPHERE_MECM_RULE_ORIGINS = [
    VIRTUSPHERE_MECM_RULE_ORIGIN_CREATED,
    VIRTUSPHERE_MECM_RULE_ORIGIN_ADOPTED,
];

// What one membership report entry may say happened. The script never reports
// `adopted`: adopting an existing foreign rule is an explicit portal action
// with a human actor, never a sync side effect (decision: nothing is adopted
// silently).
const VIRTUSPHERE_MECM_RULE_CHANGE_ADDED = 'added';
const VIRTUSPHERE_MECM_RULE_CHANGE_REMOVED = 'removed';
const VIRTUSPHERE_MECM_RULE_CHANGES = [
    VIRTUSPHERE_MECM_RULE_CHANGE_ADDED,
    VIRTUSPHERE_MECM_RULE_CHANGE_REMOVED,
];

// A report is bounded like every machine-API input: a device carries one OS,
// one mission and a package list, so a legitimate report is small.
const VIRTUSPHERE_MECM_RULE_REPORT_MAX_ENTRIES = 200;

/**
 * The owned rules of one VM, oldest first (stable for the wire and the plan).
 *
 * @return array<int, array<string, mixed>>
 */
function repo_mecm_rules_for_vm(mysqli $db, int $vmId): array
{
    $stmt = $db->prepare('SELECT collection_id, collection_name, collection_type, origin, actor, created_at FROM deploy_vm_mecm_rules WHERE vm_id = ? ORDER BY id');
    $stmt->bind_param('i', $vmId);
    $stmt->execute();

    return repo_fetch_all($stmt->get_result());
}

/**
 * Validates one raw membership report entry into its normalized shape, or
 * null. The endpoint rejects the whole report on the first bad entry: the
 * script builds the report from its own applied plan, so a malformed entry is
 * a coding error, never partial weather.
 *
 * @param mixed $entry
 * @return array{collection_id: string, collection_name: string, type: string, change: string}|null
 */
function mecm_rule_report_entry(mixed $entry): ?array
{
    if (!is_array($entry)) {
        return null;
    }
    $collectionId = trim((string) ($entry['collection_id'] ?? ''));
    $collectionName = trim((string) ($entry['collection_name'] ?? ''));
    $type = (string) ($entry['type'] ?? '');
    $change = (string) ($entry['change'] ?? '');
    if ($collectionId === '' || strlen($collectionId) > 16 || $collectionName === '' || mb_strlen($collectionName) > 255) {
        return null;
    }
    if (!in_array($type, VIRTUSPHERE_MECM_RULE_TYPES, true) || !in_array($change, VIRTUSPHERE_MECM_RULE_CHANGES, true)) {
        return null;
    }

    return ['collection_id' => $collectionId, 'collection_name' => $collectionName, 'type' => $type, 'change' => $change];
}

/**
 * Applies one validated membership report atomically. Idempotent in both
 * directions: re-reporting an add refreshes name/type and keeps the original
 * origin and timestamp; re-reporting a remove of a rule that is already gone
 * changes nothing. That is what lets a half-failed apply converge on the next
 * sync run instead of erroring forever.
 *
 * @param array<int, array{collection_id: string, collection_name: string, type: string, change: string}> $entries
 */
function repo_mecm_rules_apply_report(mysqli $db, int $vmId, array $entries, ?string $actor = null): void
{
    repo_transaction($db, static function () use ($db, $vmId, $entries, $actor): void {
        foreach ($entries as $entry) {
            if ($entry['change'] === VIRTUSPHERE_MECM_RULE_CHANGE_REMOVED) {
                repo_execute($db, 'DELETE FROM deploy_vm_mecm_rules WHERE vm_id = ? AND collection_id = ?', 'is', [$vmId, $entry['collection_id']]);
                continue;
            }
            // Row-alias syntax (VALUES() in ODKU is deprecated on MySQL 8.4).
            // origin stays what it was on a re-report: a script re-adding its
            // own rule must not overwrite an explicit portal adoption.
            $origin = VIRTUSPHERE_MECM_RULE_ORIGIN_CREATED;
            $stmt = $db->prepare('INSERT INTO deploy_vm_mecm_rules (vm_id, collection_id, collection_name, collection_type, origin, actor) VALUES (?, ?, ?, ?, ?, ?) AS new ON DUPLICATE KEY UPDATE collection_name = new.collection_name, collection_type = new.collection_type');
            $stmt->bind_param('isssss', $vmId, $entry['collection_id'], $entry['collection_name'], $entry['type'], $origin, $actor);
            $stmt->execute();
        }
    });
}
