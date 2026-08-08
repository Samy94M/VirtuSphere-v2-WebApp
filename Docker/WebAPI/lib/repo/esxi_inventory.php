<?php

declare(strict_types=1);

require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../deploy_constants.php';
require_once __DIR__ . '/../esxi_datastore_health.php';
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
function repo_esxi_inventory_record_success(mysqli $db, int $credentialId, ?array $capabilities = null, ?int $jobId = null): void
{
    $ok = 'ok';
    $jobId = $jobId !== null && $jobId > 0 ? $jobId : null;
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
        'INSERT INTO deploy_esxi_inventory_state (credential_id, last_success_at, last_attempt_at, last_status, last_error_category, last_job_id, failure_streak, paused_until_credential_change, api_type, product_version, license_product, license_free, in_ha_cluster, in_maintenance)
         VALUES (?, NOW(), NOW(), ?, NULL, ?, 0, 0, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE last_success_at = NOW(), last_attempt_at = NOW(), last_status = ?, last_error_category = NULL, last_job_id = ?, failure_streak = 0, paused_until_credential_change = 0,
             api_type = ?, product_version = ?, license_product = ?, license_free = ?, in_ha_cluster = ?, in_maintenance = ?'
    );
    // INSERT: credential, status, job, three text facts and three flags;
    // UPDATE: status, job, the same three text facts and three flags.
    $stmt->bind_param(
        'isisssiiisisssiii',
        $credentialId,
        $ok,
        $jobId,
        $apiType,
        $productVersion,
        $licenseProduct,
        $licenseFree,
        $inHaCluster,
        $inMaintenance,
        $ok,
        $jobId,
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
function repo_esxi_inventory_record_failure(mysqli $db, int $credentialId, string $category, ?int $jobId = null): void
{
    $pause = $category === VIRTUSPHERE_INVENTORY_ERROR_AUTH ? 1 : 0;
    $failed = 'failed';
    $jobId = $jobId !== null && $jobId > 0 ? $jobId : null;
    $stmt = $db->prepare(
        'INSERT INTO deploy_esxi_inventory_state (credential_id, last_attempt_at, last_status, last_error_category, last_job_id, failure_streak, paused_until_credential_change)
         VALUES (?, NOW(), ?, ?, ?, 1, ?)
         ON DUPLICATE KEY UPDATE last_attempt_at = NOW(), last_status = ?, last_error_category = ?, last_job_id = ?, failure_streak = failure_streak + 1, paused_until_credential_change = GREATEST(paused_until_credential_change, ?)'
    );
    $stmt->bind_param('issiissii', $credentialId, $failed, $category, $jobId, $pause, $failed, $category, $jobId, $pause);
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

/** @return array<int, array<string,mixed>> */
function repo_esxi_inventory_states(mysqli $db): array
{
    $stmt = $db->prepare('SELECT * FROM deploy_esxi_inventory_state');
    $stmt->execute();

    $states = [];
    foreach (repo_fetch_all($stmt->get_result()) as $row) {
        $states[(int) $row['credential_id']] = $row;
    }

    return $states;
}

/**
 * Counts all inventory kinds for every credential in one query.
 *
 * @return array<int,array<string,int>>
 */
function repo_esxi_inventory_counts(mysqli $db): array
{
    $stmt = $db->prepare('SELECT credential_id, kind, COUNT(*) AS item_count FROM deploy_esxi_inventory GROUP BY credential_id, kind');
    $stmt->execute();

    $counts = [];
    foreach (repo_fetch_all($stmt->get_result()) as $row) {
        $counts[(int) $row['credential_id']][(string) $row['kind']] = (int) $row['item_count'];
    }

    return $counts;
}

/**
 * Active inventory job per ESXi credential. The unique enqueue transaction
 * guarantees at most one queued/running row per credential.
 *
 * @return array<int,array<string,mixed>>
 */
function repo_esxi_inventory_pending_jobs(mysqli $db): array
{
    // The active SSoT (queued/running/cancelling, ADR-0033): a cancelling pull
    // still owns its credential and its link, and the enqueue dedupe reads
    // this same predicate through repo_create_system_job.
    $active = VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES;
    $placeholders = implode(', ', array_fill(0, count($active), '?'));
    $stmt = $db->prepare(
        'SELECT id, credential_esxi_id, status, correlation_id, created_at, locked_at
         FROM deploy_jobs
         WHERE mission_id IS NULL
           AND status IN (' . $placeholders . ')
           AND cancelled_at IS NULL
         ORDER BY id DESC'
    );
    $stmt->bind_param(str_repeat('s', count($active)), ...$active);
    $stmt->execute();

    $jobs = [];
    foreach (repo_fetch_all($stmt->get_result()) as $row) {
        $credentialId = (int) $row['credential_esxi_id'];
        if ($credentialId > 0 && !isset($jobs[$credentialId])) {
            $jobs[$credentialId] = $row;
        }
    }

    return $jobs;
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
 * (it is chosen at deploy time), so the picker needs to know which host
 * contributed which name before it can say what a value is worth.
 *
 * `names` stays a plain string list: it is the only thing the exact decision
 * (esxi_inventory_option_flags) may look at. `free_by_key` carries the optional
 * free space of the same rows, keyed by esxi_inventory_name_key, so the datastore
 * picker can decorate its labels without a second query. Only datastore rows have
 * it; for every other kind the map is all-null.
 *
 * `credential_host` rides along because the JOIN is already there and a fleet
 * named "esxi1".."esxi6" is not something an operator can map to a machine; the
 * bucket labels name both.
 *
 * @return array<int, array{credential_id:int, credential_name:string, credential_host:string, names:array<int,string>, free_by_key:array<string, ?int>}>
 */
function repo_esxi_inventory_names_by_credential(mysqli $db, string $kind): array
{
    $stmt = $db->prepare('SELECT i.credential_id, c.name AS credential_name, c.host AS credential_host, i.name, i.free_bytes, i.meta_json FROM deploy_esxi_inventory i INNER JOIN deploy_credentials c ON c.id = i.credential_id WHERE i.kind = ? ORDER BY c.name, i.name');
    $stmt->bind_param('s', $kind);
    $stmt->execute();

    $groups = [];
    foreach (repo_fetch_all($stmt->get_result()) as $row) {
        $credentialId = (int) $row['credential_id'];
        if (!isset($groups[$credentialId])) {
            $groups[$credentialId] = [
                'credential_id' => $credentialId,
                'credential_name' => (string) $row['credential_name'],
                'credential_host' => (string) ($row['credential_host'] ?? ''),
                'names' => [],
                'free_by_key' => [],
                'unusable_keys' => [],
            ];
        }
        $name = (string) $row['name'];
        $key = esxi_inventory_name_key($name);
        $groups[$credentialId]['names'][] = $name;
        // The free number of a datastore in maintenance is a size, not space
        // anybody can deploy onto, so it never reaches the picker label as one.
        $groups[$credentialId]['free_by_key'][$key] = esxi_datastore_usable_free_bytes(
            $row['free_bytes'] !== null ? (int) $row['free_bytes'] : null,
            $row['meta_json'] ?? null
        );
        if (esxi_datastore_is_unusable($row['meta_json'] ?? null)) {
            $groups[$credentialId]['unusable_keys'][$key] = true;
        }
    }

    return array_values($groups);
}

/**
 * Lower-cased name sets of several kinds for every credential, in one query.
 *
 * The deploy page needs "which of the mission's values is missing from THIS
 * host" for every credential in the picker, and used to answer it with a full
 * per-credential inventory read (all four kinds) inside a loop, plus one union
 * query per kind. At six credentials that was roughly seventeen round trips for
 * two warning boxes. Same shape and same reason as
 * repo_esxi_inventory_datastore_rows(): one `IN (...)` for the whole page.
 *
 * The keys go through esxi_inventory_name_key(), so every comparison downstream
 * is the project's one definition of "same name".
 *
 * @param array<int, string> $kinds
 * @return array<int, array<string, array<string, true>>> credential_id => kind => name key set
 */
function repo_esxi_inventory_name_sets_by_credential(mysqli $db, array $kinds): array
{
    $kinds = array_values(array_unique(array_filter($kinds, static fn (string $kind): bool => in_array($kind, VIRTUSPHERE_INVENTORY_KINDS, true))));
    if ($kinds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($kinds), '?'));
    $stmt = $db->prepare('SELECT credential_id, kind, name FROM deploy_esxi_inventory WHERE kind IN (' . $placeholders . ')');
    $stmt->bind_param(str_repeat('s', count($kinds)), ...$kinds);
    $stmt->execute();

    $sets = [];
    foreach (repo_fetch_all($stmt->get_result()) as $row) {
        $sets[(int) $row['credential_id']][(string) $row['kind']][esxi_inventory_name_key((string) $row['name'])] = true;
    }

    return $sets;
}

/**
 * Credentials that have ever completed a pull. The proof denominator for "on all
 * credentials": a credential that was never pulled cannot prove what it holds, so
 * it may neither confirm nor deny a name (ADR-0023). Same predicate as
 * repo_esxi_vlan_presence_report()'s eligible list.
 *
 * Deliberately not "has rows of this kind": a host that pulled successfully and
 * genuinely reports no datastore still belongs in the denominator, or every
 * datastore of the other hosts would be promoted to "present everywhere" while a
 * whole host is missing from the count.
 *
 * @return array<int, int> credential ids
 */
function repo_esxi_inventory_pulled_credential_ids(mysqli $db): array
{
    return array_map(
        static fn (array $row): int => (int) $row['credential_id'],
        repo_fetch_all($db->query('SELECT credential_id FROM deploy_esxi_inventory_state WHERE last_success_at IS NOT NULL ORDER BY credential_id'))
    );
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
