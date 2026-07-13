<?php

declare(strict_types=1);

require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../deploy_constants.php';
require_once __DIR__ . '/helpers.php';

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
 * Replaces the cached rows of one (credential, kind). Empty input keeps the
 * existing rows (empty-guard) and reports it.
 *
 * The DELETE and the INSERTs are one unit: an interrupted rewrite must never
 * commit the empty middle state, because repo_esxi_vlan_sync() reads this table
 * as positive evidence and would retire the credential's portgroups. Nested
 * inside repo_esxi_inventory_apply()'s transaction, this joins it rather than
 * opening a second one.
 *
 * @param array<int, array<string, mixed>> $items name + optional capacity_bytes/free_bytes/meta_json
 * @return array{written:int, removed:int, kept_empty:bool}
 */
function repo_esxi_inventory_replace_kind(mysqli $db, int $credentialId, string $kind, array $items): array
{
    if (!in_array($kind, VIRTUSPHERE_INVENTORY_KINDS, true)) {
        throw new InvalidArgumentException('Unknown inventory kind: ' . $kind);
    }

    $deduped = repo_esxi_inventory_dedupe($items);
    if ($deduped === []) {
        return ['written' => 0, 'removed' => 0, 'kept_empty' => true];
    }

    return repo_transaction($db, static function () use ($db, $credentialId, $kind, $deduped): array {
        $before = (int) repo_scalar($db, 'SELECT COUNT(*) FROM deploy_esxi_inventory WHERE credential_id = ? AND kind = ?', 'is', [$credentialId, $kind]);
        repo_execute($db, 'DELETE FROM deploy_esxi_inventory WHERE credential_id = ? AND kind = ?', 'is', [$credentialId, $kind]);

        $written = 0;
        foreach ($deduped as $item) {
            $name = (string) $item['name'];
            $capacity = isset($item['capacity_bytes']) && $item['capacity_bytes'] !== null ? (int) $item['capacity_bytes'] : null;
            $free = isset($item['free_bytes']) && $item['free_bytes'] !== null ? (int) $item['free_bytes'] : null;
            $meta = isset($item['meta_json']) && $item['meta_json'] !== null
                ? json_encode($item['meta_json'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                : null;

            $stmt = $db->prepare('INSERT INTO deploy_esxi_inventory (credential_id, kind, name, capacity_bytes, free_bytes, meta_json) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('issiis', $credentialId, $kind, $name, $capacity, $free, $meta);
            $stmt->execute();
            $written++;
        }

        return ['written' => $written, 'removed' => $before, 'kept_empty' => false];
    });
}

/**
 * Applies a full parsed inventory for one credential in a single transaction.
 * Each kind is replaced independently with its own empty-guard.
 *
 * @param array{datacenters?:array, datastores?:array, networks?:array, hosts?:array} $parsed
 * @return array<string, array{written:int, removed:int, kept_empty:bool}>
 */
function repo_esxi_inventory_apply(mysqli $db, int $credentialId, array $parsed): array
{
    return repo_transaction($db, static function () use ($db, $credentialId, $parsed): array {
        $summary = [];
        $summary['datacenter'] = repo_esxi_inventory_replace_kind($db, $credentialId, VIRTUSPHERE_INVENTORY_KIND_DATACENTER, repo_esxi_inventory_name_items($parsed['datacenters'] ?? []));
        $summary['datastore'] = repo_esxi_inventory_replace_kind($db, $credentialId, VIRTUSPHERE_INVENTORY_KIND_DATASTORE, (array) ($parsed['datastores'] ?? []));
        // Networks may arrive as plain names (legacy) or as items with a
        // vlan_id meta; both are accepted.
        $summary['network'] = repo_esxi_inventory_replace_kind($db, $credentialId, VIRTUSPHERE_INVENTORY_KIND_NETWORK, repo_esxi_inventory_mixed_items((array) ($parsed['networks'] ?? [])));
        $summary['host'] = repo_esxi_inventory_replace_kind($db, $credentialId, VIRTUSPHERE_INVENTORY_KIND_HOST, (array) ($parsed['hosts'] ?? []));

        return $summary;
    });
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
 * Cached datastore rows (name + bytes) of several credentials in one query. The
 * deploy page's storage table needs every credential in the picker at once; a
 * query per credential would scale the page load with the host count.
 *
 * @param array<int, int> $credentialIds
 * @return array<int, array<string, mixed>> rows with credential_id, name, capacity_bytes, free_bytes
 */
function repo_esxi_inventory_datastore_rows(mysqli $db, array $credentialIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $credentialIds), static fn (int $id): bool => $id > 0)));
    if ($ids === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare('SELECT credential_id, name, capacity_bytes, free_bytes FROM deploy_esxi_inventory WHERE kind = ? AND credential_id IN (' . $placeholders . ') ORDER BY credential_id, name');
    $types = 's' . str_repeat('i', count($ids));
    $params = array_merge([VIRTUSPHERE_INVENTORY_KIND_DATASTORE], $ids);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();

    return repo_fetch_all($stmt->get_result());
}

/**
 * Records a successful fetch: clears the failure streak and pause, and stores
 * the capability facts of this pull.
 *
 * $capabilities may be null (a caller that has none) or carry nulls of its own
 * (the module did not report a field). Both write SQL NULL, which the portal
 * reads as "not known". They are overwritten wholesale on every success rather
 * than merged, because a host that stopped reporting a fact no longer supports
 * the claim the old value made.
 *
 * @param array{api_type?:?string, product_version?:?string, license_product?:?string, license_free?:?bool, in_ha_cluster?:?bool, in_maintenance?:?bool}|null $capabilities
 */
function repo_esxi_inventory_record_success(mysqli $db, int $credentialId, ?array $capabilities = null): void
{
    $ok = 'ok';
    $capabilities ??= [];
    $apiType = $capabilities['api_type'] ?? null;
    $productVersion = $capabilities['product_version'] ?? null;
    $licenseProduct = $capabilities['license_product'] ?? null;
    // mysqli binds a PHP null as SQL NULL; a bool has to become 0/1 first, and
    // must stay null when the fact is unknown.
    $toFlag = static fn (mixed $value): ?int => $value === null ? null : ((bool) $value ? 1 : 0);
    $licenseFree = $toFlag($capabilities['license_free'] ?? null);
    $inHaCluster = $toFlag($capabilities['in_ha_cluster'] ?? null);
    $inMaintenance = $toFlag($capabilities['in_maintenance'] ?? null);

    $stmt = $db->prepare(
        'INSERT INTO deploy_esxi_inventory_state (credential_id, last_success_at, last_attempt_at, last_status, last_error_category, failure_streak, paused_until_credential_change, api_type, product_version, license_product, license_free, in_ha_cluster, in_maintenance)
         VALUES (?, NOW(), NOW(), ?, NULL, 0, 0, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE last_success_at = NOW(), last_attempt_at = NOW(), last_status = ?, last_error_category = NULL, failure_streak = 0, paused_until_credential_change = 0,
             api_type = ?, product_version = ?, license_product = ?, license_free = ?, in_ha_cluster = ?, in_maintenance = ?'
    );
    // i, s(status), s s s(text facts), i i i(flags), then the same for the UPDATE.
    $stmt->bind_param(
        'issssiiissssiii',
        $credentialId,
        $ok,
        $apiType,
        $productVersion,
        $licenseProduct,
        $licenseFree,
        $inHaCluster,
        $inMaintenance,
        $ok,
        $apiType,
        $productVersion,
        $licenseProduct,
        $licenseFree,
        $inHaCluster,
        $inMaintenance
    );
    $stmt->execute();
}

/**
 * Records a failed fetch. Auth failures pause the auto-pull until the credential
 * changes (protects the ESXi account from lockout).
 */
function repo_esxi_inventory_record_failure(mysqli $db, int $credentialId, string $category): void
{
    $pause = $category === VIRTUSPHERE_INVENTORY_ERROR_AUTH ? 1 : 0;
    $failed = 'failed';
    $stmt = $db->prepare(
        'INSERT INTO deploy_esxi_inventory_state (credential_id, last_attempt_at, last_status, last_error_category, failure_streak, paused_until_credential_change)
         VALUES (?, NOW(), ?, ?, 1, ?)
         ON DUPLICATE KEY UPDATE last_attempt_at = NOW(), last_status = ?, last_error_category = ?, failure_streak = failure_streak + 1, paused_until_credential_change = GREATEST(paused_until_credential_change, ?)'
    );
    $stmt->bind_param('ississi', $credentialId, $failed, $category, $pause, $failed, $category, $pause);
    $stmt->execute();
}

/** Clears the auth pause + failure streak when a credential is (re)saved. */
function repo_esxi_inventory_clear_pause(mysqli $db, int $credentialId): void
{
    repo_execute($db, 'UPDATE deploy_esxi_inventory_state SET paused_until_credential_change = 0, failure_streak = 0 WHERE credential_id = ?', 'i', [$credentialId]);
}

/** @return array<string, mixed>|null */
function repo_esxi_inventory_state(mysqli $db, int $credentialId): ?array
{
    return repo_fetch_one($db, 'SELECT * FROM deploy_esxi_inventory_state WHERE credential_id = ? LIMIT 1', 'i', [$credentialId]);
}

/**
 * Syncs the ESXi-owned VLAN catalog (deploy_vlan) from the cached portgroups
 * (ADR-0023, E4b). Present = the union of ALL cached network rows (fresh AND
 * frozen), so a portgroup on a currently-unreachable host stays present via its
 * frozen cache. A name present is upserted/un-retired; a name absent is retired
 * (never deleted) only when there is fresh positive evidence (some credential
 * recorded a successful fetch, see repo_esxi_inventory_has_fresh_success) and
 * the present set is non-empty (empty-guard). Stored assignments in missions/VMs
 * are name-strings and are never touched here.
 *
 * Reads and writes share one transaction, so evidence and decision come from one
 * consistent snapshot. That is the whole guarantee: the plain SELECTs take no
 * row locks, so a fetch that commits while this sync runs is invisible to it,
 * and a portgroup that fetch just restored can still be retired here. Retire is
 * not delete, so the next sync un-retires it; FOR SHARE locks would trade that
 * short-lived mislabel for deadlock risk against the workers' inventory rewrites.
 *
 * @return array{upserted:int, unretired:int, retired:int}
 */
function repo_esxi_vlan_sync(mysqli $db): array
{
    return repo_transaction($db, static function () use ($db): array {
        $result = ['upserted' => 0, 'unretired' => 0, 'retired' => 0];

        // Evidence and decision must come from one snapshot. Read inside the
        // transaction: a concurrent inventory rewrite that commits between the
        // read and the retire loop would otherwise make us retire names that
        // are, by then, present again.
        $present = repo_esxi_vlan_present_names($db);
        $hasFreshFetch = repo_esxi_inventory_has_fresh_success($db);

        foreach ($present as $name) {
            $before = repo_fetch_one($db, 'SELECT id, retired_at FROM deploy_vlan WHERE vlan_name = ? LIMIT 1', 's', [$name]);
            // Row-alias syntax (VALUES() in ODKU is deprecated on MySQL 8.4).
            // Refreshing vlan_name follows a case change on ESXi; an assignment
            // that now differs only by case shows the "(not in inventory)"
            // fallback option until re-saved, never a false warning (all warn
            // logic is case-insensitive). A case-only refresh counts neither as
            // upsert nor as un-retire.
            $stmt = $db->prepare('INSERT INTO deploy_vlan (vlan_name, retired_at) VALUES (?, NULL) AS new ON DUPLICATE KEY UPDATE vlan_name = new.vlan_name, retired_at = NULL');
            $stmt->bind_param('s', $name);
            $stmt->execute();
            if ($before === null) {
                $result['upserted']++;
            } elseif ($before['retired_at'] !== null) {
                $result['unretired']++;
            }
        }

        if ($hasFreshFetch && $present !== []) {
            $activeVlans = repo_fetch_all($db->query('SELECT id, vlan_name FROM deploy_vlan WHERE retired_at IS NULL'));
            foreach ($activeVlans as $row) {
                if (!isset($present[esxi_inventory_name_key((string) $row['vlan_name'])])) {
                    repo_execute($db, 'UPDATE deploy_vlan SET retired_at = NOW() WHERE id = ?', 'i', [(int) $row['id']]);
                    $result['retired']++;
                }
            }
        }

        return $result;
    });
}

/**
 * Union of every cached portgroup name, fresh AND frozen, keyed case-insensitively.
 *
 * No DISTINCT here: the *_ci collation would collapse case variants of the same
 * name non-deterministically. Ordering by credential_id and taking the first
 * spelling per name key in PHP pins WHICH case the catalog carries (the lowest
 * credential id's), stable across syncs.
 *
 * @return array<string, string> name key => the spelling the catalog carries
 */
function repo_esxi_vlan_present_names(mysqli $db): array
{
    $present = [];
    $stmt = $db->prepare('SELECT credential_id, name FROM deploy_esxi_inventory WHERE kind = ? ORDER BY credential_id, name');
    $network = VIRTUSPHERE_INVENTORY_KIND_NETWORK;
    $stmt->bind_param('s', $network);
    $stmt->execute();
    foreach (repo_fetch_all($stmt->get_result()) as $row) {
        $name = trim((string) $row['name']);
        $key = esxi_inventory_name_key($name);
        if ($name !== '' && !isset($present[$key])) {
            $present[$key] = $name;
        }
    }

    return $present;
}

/**
 * Is there positive evidence that some credential's cache reflects a real host?
 *
 * A status word alone does not prove it: a state row can carry last_status='ok'
 * without ever having completed a fetch (backfills, hand-edited rows). Retiring
 * catalog entries is only allowed on the back of a recorded success timestamp.
 */
function repo_esxi_inventory_has_fresh_success(mysqli $db): bool
{
    $count = (int) repo_scalar(
        $db,
        'SELECT COUNT(*) FROM deploy_esxi_inventory_state WHERE last_status = ? AND last_success_at IS NOT NULL',
        's',
        ['ok']
    );

    return $count > 0;
}

/**
 * Distinct inventory names of one kind across all credentials (for the
 * datacenter/datastore suggestion lists in the mission editor).
 *
 * @return array<int, string>
 */
function repo_esxi_inventory_names_by_kind(mysqli $db, string $kind): array
{
    $stmt = $db->prepare('SELECT DISTINCT name FROM deploy_esxi_inventory WHERE kind = ? ORDER BY name');
    $stmt->bind_param('s', $kind);
    $stmt->execute();

    return array_map(static fn (array $r): string => (string) $r['name'], repo_fetch_all($stmt->get_result()));
}

/**
 * Inventory names of one kind grouped by the credential that reported them, for
 * the datacenter/datastore pickers. A mission does not store its target ESXi
 * (it is chosen at deploy time), so with several credentials the picker shows
 * whose host contributed which name instead of one anonymous union.
 *
 * `names` stays a plain string list: it is the only thing the grouped/exact
 * decision (esxi_inventory_option_flags) may look at. `free_by_key` carries the
 * optional free space of the same rows, keyed by esxi_inventory_name_key, so the
 * datastore picker can decorate its labels without a second query. Only
 * datastore rows have it; for every other kind the map is all-null.
 *
 * @return array<int, array{credential_id:int, credential_name:string, names:array<int,string>, free_by_key:array<string, ?int>}>
 */
function repo_esxi_inventory_names_by_credential(mysqli $db, string $kind): array
{
    $stmt = $db->prepare('SELECT i.credential_id, c.name AS credential_name, i.name, i.free_bytes FROM deploy_esxi_inventory i INNER JOIN deploy_credentials c ON c.id = i.credential_id WHERE i.kind = ? ORDER BY c.name, i.name');
    $stmt->bind_param('s', $kind);
    $stmt->execute();

    $groups = [];
    foreach (repo_fetch_all($stmt->get_result()) as $row) {
        $credentialId = (int) $row['credential_id'];
        if (!isset($groups[$credentialId])) {
            $groups[$credentialId] = [
                'credential_id' => $credentialId,
                'credential_name' => (string) $row['credential_name'],
                'names' => [],
                'free_by_key' => [],
            ];
        }
        $name = (string) $row['name'];
        $groups[$credentialId]['names'][] = $name;
        $groups[$credentialId]['free_by_key'][esxi_inventory_name_key($name)] = $row['free_bytes'] !== null ? (int) $row['free_bytes'] : null;
    }

    return array_values($groups);
}

/**
 * Datacenter names one credential currently reports. A standalone ESXi has
 * exactly one (its implicit `ha-datacenter`), a vCenter has one per named
 * datacenter.
 *
 * @return array<int, string>
 */
function repo_esxi_datacenters_for_credential(mysqli $db, int $credentialId): array
{
    $kind = VIRTUSPHERE_INVENTORY_KIND_DATACENTER;
    $stmt = $db->prepare('SELECT name FROM deploy_esxi_inventory WHERE credential_id = ? AND kind = ? ORDER BY name');
    $stmt->bind_param('is', $credentialId, $kind);
    $stmt->execute();

    return array_map(static fn (array $r): string => (string) $r['name'], repo_fetch_all($stmt->get_result()));
}

/**
 * The single datacenter of one credential, or null when it reports none (never
 * pulled) or several (vCenter). Only an unambiguous answer may stand in for a
 * mission that leaves its datacenter empty; anything else has to be decided by a
 * human. Lives in the repo layer so repo/deploy_jobs.php can use it without
 * pulling lib/esxi_inventory.php and creating a require cycle.
 */
function repo_esxi_sole_datacenter(mysqli $db, int $credentialId): ?string
{
    if ($credentialId <= 0) {
        return null;
    }

    $names = repo_esxi_datacenters_for_credential($db, $credentialId);

    return count($names) === 1 ? $names[0] : null;
}

/**
 * Aggregates cached network rows into per-name VLAN-id and trunk maps (pure,
 * feeds the catalog's VLAN-ID column). IDs come only from integer vlan_id
 * metas; trunk-flagged rows are listed separately and never enter the ID
 * comparison (a number cannot be compared to a range). Rows without meta (old
 * cache, legacy names) contribute nothing.
 *
 * @param array<int, array<string, mixed>> $rows name + meta_json + credential_name
 * @return array{ids: array<string, array<int, array<int, string>>>, trunks: array<string, array<int, string>>}
 */
function repo_esxi_vlan_id_aggregate(array $rows): array
{
    $ids = [];
    $trunks = [];
    foreach ($rows as $row) {
        $key = esxi_inventory_name_key((string) ($row['name'] ?? ''));
        if ($key === '') {
            continue;
        }
        $meta = json_decode((string) ($row['meta_json'] ?? ''), true);
        if (!is_array($meta)) {
            continue;
        }
        $credential = (string) ($row['credential_name'] ?? '');
        if (!empty($meta['trunk'])) {
            $trunks[$key][] = $credential;
            continue;
        }
        if (isset($meta['vlan_id']) && is_int($meta['vlan_id'])) {
            $ids[$key][$meta['vlan_id']][] = $credential;
        }
    }

    return ['ids' => $ids, 'trunks' => $trunks];
}

/**
 * Presence report for the read-only VLAN catalog: which ESXi credentials
 * currently report each cached portgroup name, the list of credentials that
 * ever pulled successfully, and the VLAN-id/trunk aggregation for the ID
 * column. Only successful credentials count into the "on X of Y hosts"
 * denominator; a never-pulled credential cannot prove what it holds
 * (ADR-0023).
 *
 * @return array{eligible: array<int, string>, by_name: array<string, array<int, string>>, ids: array<string, array<int, array<int, string>>>, trunks: array<string, array<int, string>>}
 */
function repo_esxi_vlan_presence_report(mysqli $db): array
{
    $eligible = array_map(
        static fn (array $r): string => (string) $r['name'],
        repo_fetch_all($db->query('SELECT c.name FROM deploy_esxi_inventory_state s INNER JOIN deploy_credentials c ON c.id = s.credential_id WHERE s.last_success_at IS NOT NULL ORDER BY c.name'))
    );

    $network = VIRTUSPHERE_INVENTORY_KIND_NETWORK;
    $stmt = $db->prepare('SELECT i.name, i.meta_json, c.name AS credential_name FROM deploy_esxi_inventory i INNER JOIN deploy_credentials c ON c.id = i.credential_id WHERE i.kind = ? ORDER BY c.name');
    $stmt->bind_param('s', $network);
    $stmt->execute();
    $rows = repo_fetch_all($stmt->get_result());

    $byName = [];
    foreach ($rows as $row) {
        $byName[esxi_inventory_name_key((string) $row['name'])][] = (string) $row['credential_name'];
    }

    return ['eligible' => $eligible, 'by_name' => $byName] + repo_esxi_vlan_id_aggregate($rows);
}
