<?php

declare(strict_types=1);

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
