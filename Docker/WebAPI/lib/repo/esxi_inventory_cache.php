<?php

declare(strict_types=1);

/**
 * ESXi inventory cache (ADR-0023). ESXi is the source; these rows are a
 * read-only mirror used only for display and warnings, never to block a deploy.
 *
 * Write rules:
 * - Replace the rows of one (credential, kind) atomically.
 * - Empty-result guard: a successful fetch that returns 0 items for a kind
 *   keeps the existing rows (a transient blank must not wipe the cache).
 * - Case-insensitive de-duplication (the UNIQUE key uses a *_ci collation, so
 *   "Prod" and "prod" would otherwise collide).
 */

/**
 * Canonical case- and whitespace-insensitive key for inventory names. The single
 * definition of "same name" for dedupe, presence, deviation and picker logic;
 * every comparison must go through it so the pages can never disagree.
 */
function esxi_inventory_name_key(string $name): string
{
    return mb_strtolower(trim($name));
}

/**
 * De-duplicates normalized items by lower-cased name (first wins) and drops
 * empty names.
 *
 * @param array<int, array<string, mixed>> $items
 * @return array<int, array<string, mixed>>
 */
function repo_esxi_inventory_dedupe(array $items): array
{
    $seen = [];
    foreach ($items as $item) {
        $name = trim((string) ($item['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $key = esxi_inventory_name_key($name);
        if (!isset($seen[$key])) {
            $item['name'] = $name;
            $seen[$key] = $item;
        }
    }

    return array_values($seen);
}

/**
 * Replaces the cached rows of one (credential, kind).
 *
 * Empty input is two different facts, and $authoritativeEmpty says which one
 * this is (B15): false keeps the existing rows (empty-guard, a transient blank
 * or a rejected query must not wipe the cache), true DELETES them, because the
 * kind's every query answered and the host genuinely reports none; keeping the
 * old rows would show portgroups the host lost. The caller derives the flag
 * from the per-query report (repo_esxi_inventory_answered_kinds); a pull
 * without the report is never authoritative.
 *
 * The DELETE and the INSERTs are one unit: an interrupted rewrite must never
 * commit the empty middle state, because repo_esxi_vlan_sync() reads this table
 * as positive evidence and would retire the credential's portgroups. Nested
 * inside repo_esxi_inventory_apply()'s transaction, this joins it rather than
 * opening a second one.
 *
 * @param array<int, array<string, mixed>> $items name + optional capacity_bytes/free_bytes/meta_json
 * @return array{written:int, removed:int, kept_empty:bool, cleared:bool}
 */
function repo_esxi_inventory_replace_kind(mysqli $db, int $credentialId, string $kind, array $items, bool $authoritativeEmpty = false): array
{
    if (!in_array($kind, VIRTUSPHERE_INVENTORY_KINDS, true)) {
        throw new InvalidArgumentException('Unknown inventory kind: ' . $kind);
    }

    $deduped = repo_esxi_inventory_dedupe($items);
    if ($deduped === []) {
        if (!$authoritativeEmpty) {
            return ['written' => 0, 'removed' => 0, 'kept_empty' => true, 'cleared' => false];
        }

        return repo_transaction($db, static function () use ($db, $credentialId, $kind): array {
            $before = (int) repo_scalar($db, 'SELECT COUNT(*) FROM deploy_esxi_inventory WHERE credential_id = ? AND kind = ?', 'is', [$credentialId, $kind]);
            repo_execute($db, 'DELETE FROM deploy_esxi_inventory WHERE credential_id = ? AND kind = ?', 'is', [$credentialId, $kind]);

            return ['written' => 0, 'removed' => $before, 'kept_empty' => false, 'cleared' => true];
        });
    }

    return repo_transaction($db, static function () use ($db, $credentialId, $kind, $deduped): array {
        $before = (int) repo_scalar($db, 'SELECT COUNT(*) FROM deploy_esxi_inventory WHERE credential_id = ? AND kind = ?', 'is', [$credentialId, $kind]);
        repo_execute($db, 'DELETE FROM deploy_esxi_inventory WHERE credential_id = ? AND kind = ?', 'is', [$credentialId, $kind]);

        $written = 0;
        foreach ($deduped as $item) {
            $name = (string) $item['name'];
            $capacity = isset($item['capacity_bytes']) ? (int) $item['capacity_bytes'] : null;
            $free = isset($item['free_bytes']) ? (int) $item['free_bytes'] : null;
            $meta = isset($item['meta_json'])
                ? json_encode($item['meta_json'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                : null;

            $stmt = $db->prepare('INSERT INTO deploy_esxi_inventory (credential_id, kind, name, capacity_bytes, free_bytes, meta_json) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('issiis', $credentialId, $kind, $name, $capacity, $free, $meta);
            $stmt->execute();
            $written++;
        }

        return ['written' => $written, 'removed' => $before, 'kept_empty' => false, 'cleared' => false];
    });
}

/**
 * The kinds whose EVERY query answered in this pull, derived from the
 * per-query report. Only these may decide authoritatively about emptiness and
 * only these get a freshness stamp. The network kind is a union of two
 * queries, so both must have answered: with one of them failed or skipped the
 * union is incomplete and proves nothing. A pull without the report (old
 * playbook) answers nothing.
 *
 * @param array<string, array{state?:string}> $queries
 * @return array<int, string>
 */
function repo_esxi_inventory_answered_kinds(array $queries): array
{
    if ($queries === []) {
        return [];
    }

    $map = [
        VIRTUSPHERE_INVENTORY_KIND_DATACENTER => ['datacenters'],
        VIRTUSPHERE_INVENTORY_KIND_DATASTORE => ['datastores'],
        VIRTUSPHERE_INVENTORY_KIND_NETWORK => ['networks_standard', 'networks_dvs'],
        VIRTUSPHERE_INVENTORY_KIND_HOST => ['hosts'],
        VIRTUSPHERE_INVENTORY_KIND_VM => ['vms'],
    ];

    $answered = [];
    foreach ($map as $kind => $queryNames) {
        foreach ($queryNames as $queryName) {
            if ((string) ($queries[$queryName]['state'] ?? '') !== VIRTUSPHERE_INVENTORY_QUERY_ANSWERED) {
                continue 2;
            }
        }
        $answered[] = $kind;
    }

    return $answered;
}

/**
 * Applies a full parsed inventory for one credential in a single transaction.
 * Each kind is replaced independently; the per-query report decides which
 * kinds may treat an empty result as authoritative (B15), and every answered
 * kind gets its freshness stamped, including the empty ones.
 *
 * @param array{datacenters?:array, datastores?:array, networks?:array, hosts?:array, vms?:array, queries?:array} $parsed
 * @return array<string, array{written:int, removed:int, kept_empty:bool, cleared:bool}>
 */
function repo_esxi_inventory_apply(mysqli $db, int $credentialId, array $parsed): array
{
    $answeredKinds = repo_esxi_inventory_answered_kinds((array) ($parsed['queries'] ?? []));

    return repo_transaction($db, static function () use ($db, $credentialId, $parsed, $answeredKinds): array {
        $authoritative = static fn (string $kind): bool => in_array($kind, $answeredKinds, true);

        $summary = [];
        $summary['datacenter'] = repo_esxi_inventory_replace_kind($db, $credentialId, VIRTUSPHERE_INVENTORY_KIND_DATACENTER, repo_esxi_inventory_name_items($parsed['datacenters'] ?? []), $authoritative(VIRTUSPHERE_INVENTORY_KIND_DATACENTER));
        $summary['datastore'] = repo_esxi_inventory_replace_kind($db, $credentialId, VIRTUSPHERE_INVENTORY_KIND_DATASTORE, (array) ($parsed['datastores'] ?? []), $authoritative(VIRTUSPHERE_INVENTORY_KIND_DATASTORE));
        // Networks may arrive as plain names (legacy) or as items with a
        // vlan_id meta; both are accepted.
        $summary['network'] = repo_esxi_inventory_replace_kind($db, $credentialId, VIRTUSPHERE_INVENTORY_KIND_NETWORK, repo_esxi_inventory_mixed_items((array) ($parsed['networks'] ?? [])), $authoritative(VIRTUSPHERE_INVENTORY_KIND_NETWORK));
        $summary['host'] = repo_esxi_inventory_replace_kind($db, $credentialId, VIRTUSPHERE_INVENTORY_KIND_HOST, (array) ($parsed['hosts'] ?? []), $authoritative(VIRTUSPHERE_INVENTORY_KIND_HOST));
        $summary['vm'] = repo_esxi_inventory_replace_kind($db, $credentialId, VIRTUSPHERE_INVENTORY_KIND_VM, (array) ($parsed['vms'] ?? []), $authoritative(VIRTUSPHERE_INVENTORY_KIND_VM));

        repo_esxi_inventory_touch_kind_freshness($db, $credentialId, $answeredKinds);

        return $summary;
    });
}

/**
 * Stamps "answered as of now" for the given kinds on the credential's state
 * row, creating the row when the first pull gets here before
 * repo_esxi_inventory_record_success() does. Merge, not overwrite: a kind
 * whose query failed this time keeps its older stamp, which is exactly the
 * age the frozen rows have.
 *
 * @param array<int, string> $kinds
 */
function repo_esxi_inventory_touch_kind_freshness(mysqli $db, int $credentialId, array $kinds): void
{
    $kinds = array_values(array_intersect($kinds, VIRTUSPHERE_INVENTORY_KINDS));
    if ($kinds === []) {
        return;
    }

    // Database clock, like every other timestamp on this row (NOW() writes).
    $now = (string) repo_scalar($db, 'SELECT NOW()');
    $row = repo_fetch_one($db, 'SELECT kind_freshness_json FROM deploy_esxi_inventory_state WHERE credential_id = ? LIMIT 1', 'i', [$credentialId]);
    $decoded = $row !== null ? json_decode((string) ($row['kind_freshness_json'] ?? ''), true) : null;
    $map = is_array($decoded) ? $decoded : [];
    foreach ($kinds as $kind) {
        $map[$kind] = $now;
    }

    $json = json_encode($map, JSON_THROW_ON_ERROR);
    // Row-alias syntax (VALUES() in ODKU is deprecated on MySQL 8.4).
    $stmt = $db->prepare('INSERT INTO deploy_esxi_inventory_state (credential_id, kind_freshness_json) VALUES (?, ?) AS new ON DUPLICATE KEY UPDATE kind_freshness_json = new.kind_freshness_json');
    $stmt->bind_param('is', $credentialId, $json);
    $stmt->execute();
}

/**
 * Wraps a plain list of names into name-item arrays.
 *
 * @param array<int, mixed> $names
 * @return array<int, array{name:string}>
 */
function repo_esxi_inventory_name_items(array $names): array
{
    $items = [];
    foreach ($names as $name) {
        $items[] = ['name' => (string) $name];
    }

    return $items;
}

/**
 * Accepts a mix of plain names and full item arrays (name + optional meta) and
 * normalizes everything to item arrays.
 *
 * @param array<int, mixed> $entries
 * @return array<int, array<string, mixed>>
 */
function repo_esxi_inventory_mixed_items(array $entries): array
{
    $items = [];
    foreach ($entries as $entry) {
        $items[] = is_array($entry) ? $entry : ['name' => (string) $entry];
    }

    return $items;
}

/**
 * Reads the cached inventory rows for a credential grouped by kind.
 *
 * @return array<string, array<int, array<string, mixed>>>
 */
function repo_esxi_inventory_for_credential(mysqli $db, int $credentialId): array
{
    $stmt = $db->prepare('SELECT kind, name, capacity_bytes, free_bytes, meta_json, fetched_at FROM deploy_esxi_inventory WHERE credential_id = ? ORDER BY kind, name');
    $stmt->bind_param('i', $credentialId);
    $stmt->execute();

    $grouped = [];
    foreach (repo_fetch_all($stmt->get_result()) as $row) {
        $grouped[(string) $row['kind']][] = $row;
    }

    return $grouped;
}

/**
 * Cached datastore rows (name + bytes + health meta) of several credentials in
 * one query. The deploy page's storage table needs every credential in the
 * picker at once; a query per credential would scale the page load with the
 * host count.
 *
 * meta_json rides along because the free number alone does not answer whether
 * the space is usable: a datastore in maintenance reports a size nobody can
 * deploy onto (esxi_datastore_usable_free_bytes).
 *
 * @param array<int, int> $credentialIds
 * @return array<int, array<string, mixed>> rows with credential_id, name, capacity_bytes, free_bytes, meta_json
 */
function repo_esxi_inventory_datastore_rows(mysqli $db, array $credentialIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $credentialIds), static fn (int $id): bool => $id > 0)));
    if ($ids === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare('SELECT credential_id, name, capacity_bytes, free_bytes, meta_json FROM deploy_esxi_inventory WHERE kind = ? AND credential_id IN (' . $placeholders . ') ORDER BY credential_id, name');
    $types = 's' . str_repeat('i', count($ids));
    $params = array_merge([VIRTUSPHERE_INVENTORY_KIND_DATASTORE], $ids);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();

    return repo_fetch_all($stmt->get_result());
}
