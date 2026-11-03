<?php

declare(strict_types=1);

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
        $baselineComplete = repo_esxi_network_semantics_baseline_complete($db);

        foreach ($present as $name) {
            $before = repo_fetch_one($db, 'SELECT id, retired_at FROM deploy_vlan WHERE vlan_name = ? LIMIT 1', 's', [$name]);
            // Row-alias syntax (VALUES() in ODKU is deprecated on MySQL 8.4).
            // Exact names are independent rows. A case variant is inserted as a
            // separate ESXi-owned portgroup and never rewrites another spelling.
            $stmt = $db->prepare('INSERT INTO deploy_vlan (vlan_name, retired_at) VALUES (?, NULL) AS new ON DUPLICATE KEY UPDATE vlan_name = new.vlan_name, retired_at = NULL');
            $stmt->bind_param('s', $name);
            $stmt->execute();
            if ($before === null) {
                $result['upserted']++;
            } elseif ($before['retired_at'] !== null) {
                $result['unretired']++;
            }
        }

        if ($baselineComplete) {
            $activeVlans = repo_fetch_all($db->query('SELECT id, vlan_name FROM deploy_vlan WHERE retired_at IS NULL'));
            foreach ($activeVlans as $row) {
                if (!isset($present[(string) $row['vlan_name']])) {
                    repo_execute($db, 'UPDATE deploy_vlan SET retired_at = NOW() WHERE id = ?', 'i', [(int) $row['id']]);
                    $result['retired']++;
                }
            }
        }

        return $result;
    });
}
/**
 * Union of every cached supported portgroup name, keyed exactly.
 *
 * No DISTINCT here: operative identity is the exact raw name. Ordering and the
 * PHP key keep duplicates of that exact name deterministic across credentials.
 *
 * @return array<string, string> name key => the spelling the catalog carries
 */
function repo_esxi_vlan_present_names(mysqli $db): array
{
    $present = [];
    $stmt = $db->prepare('SELECT i.credential_id, i.name, i.meta_json, s.kind_name_semantics_json FROM deploy_esxi_inventory i INNER JOIN deploy_esxi_inventory_state s ON s.credential_id = i.credential_id WHERE i.kind = ? ORDER BY i.credential_id, i.name');
    $network = VIRTUSPHERE_INVENTORY_KIND_NETWORK;
    $stmt->bind_param('s', $network);
    $stmt->execute();
    foreach (repo_fetch_all($stmt->get_result()) as $row) {
        $semantics = json_decode((string) ($row['kind_name_semantics_json'] ?? ''), true);
        if (!is_array($semantics) || (int) ($semantics[VIRTUSPHERE_INVENTORY_KIND_NETWORK] ?? 1) !== 2) {
            continue;
        }
        $name = (string) $row['name'];
        $classification = esxi_object_name_classify_raw($name);
        $key = $name;
        if ($classification['supported'] && !isset($present[$key])) {
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

/** Retirement stays frozen until every configured ESXi credential has a V2 network baseline. */
function repo_esxi_network_semantics_baseline_complete(mysqli $db): bool
{
    $stmt = $db->prepare('SELECT c.id, s.kind_freshness_json, s.kind_name_semantics_json FROM deploy_credentials c LEFT JOIN deploy_esxi_inventory_state s ON s.credential_id = c.id WHERE c.type = ? ORDER BY c.id');
    $type = VIRTUSPHERE_CREDENTIAL_TYPE_ESXI;
    $stmt->bind_param('s', $type);
    $stmt->execute();
    $rows = repo_fetch_all($stmt->get_result());
    if ($rows === []) {
        return false;
    }
    foreach ($rows as $row) {
        $freshness = json_decode((string) ($row['kind_freshness_json'] ?? ''), true);
        $semantics = json_decode((string) ($row['kind_name_semantics_json'] ?? ''), true);
        if (!is_array($freshness) || !isset($freshness[VIRTUSPHERE_INVENTORY_KIND_NETWORK])
            || !is_array($semantics) || (int) ($semantics[VIRTUSPHERE_INVENTORY_KIND_NETWORK] ?? 1) !== 2) {
            return false;
        }
    }

    return true;
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
        $key = (string) ($row['name'] ?? '');
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
        $byName[(string) $row['name']][] = (string) $row['credential_name'];
    }

    return ['eligible' => $eligible, 'by_name' => $byName] + repo_esxi_vlan_id_aggregate($rows);
}
