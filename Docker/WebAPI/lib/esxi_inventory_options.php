<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/repo/credentials.php';
require_once __DIR__ . '/repo/esxi_inventory.php';

/**
 * What the datacenter / datastore pickers offer, and how the offer is grouped.
 *
 * Split out of lib/esxi_inventory.php (ADR-0006): that module schedules pulls,
 * scans deviations and tracks fetch health, and the picker's option logic is a
 * fourth job with its own edge cases. Everything here is either pure or one
 * repository read away from pure, so the 0/1/many-credential cases are testable
 * without an ESXi host.
 *
 * The shape of the problem (ADR-0023): a mission stores no ESXi credential. The
 * target host is chosen at deploy time, so the option list is a union over all
 * credentials and the operator's real question about any entry is not "who
 * reported this" but **"does this value survive the host choice I make later?"**
 * The buckets below answer that question; grouping by credential answered the
 * other one.
 */

/**
 * Option source for the two location pickers.
 *
 * `exact` requires that *every* configured credential contributed and that they
 * all agree. Only then does the union describe every possible deploy target, and
 * only then may the caller preselect a lone value or hide a per-VM override that
 * could not change anything. A credential that was never pulled cannot prove what
 * it has. It stays the single source for preselecting and hiding; the buckets
 * decide presentation only.
 *
 * `name_set` is the same lower-cased map esxi_inventory_name_set() builds, so a
 * caller can ask esxi_inventory_value_unknown() instead of rolling its own
 * comparison. The picker label and the deviation report must never disagree.
 *
 * `free_by_key` decorates the datastore options with the free space of the last
 * pull. It is a label, never a value: the option still submits the plain name.
 *
 * @return array{credential_count:int, eligible_count:int, exact:bool, buckets:array<int,array<string,mixed>>, groups:array<int,array<string,mixed>>, names:array<int,string>, name_set:array<string,true>, free_by_key:array<string,?int>, unusable_by_key:array<string,bool>}
 */
function esxi_inventory_options(mysqli $db, string $kind): array
{
    $credentialCount = count(repo_credentials_by_type($db, VIRTUSPHERE_CREDENTIAL_TYPE_ESXI));
    $groups = repo_esxi_inventory_names_by_credential($db, $kind);
    // The proof denominator, not the configured count: only a credential that
    // pulled successfully has shown what it holds. Same rule as
    // repo_esxi_vlan_presence_report()'s "on X of Y hosts".
    $eligible = repo_esxi_inventory_pulled_credential_ids($db);

    $union = esxi_inventory_name_union($groups);

    return esxi_inventory_option_flags($groups, $union['names'], $credentialCount) + [
        'credential_count' => $credentialCount,
        'eligible_count' => count($eligible),
        'buckets' => esxi_inventory_presence_buckets($groups, count($eligible)),
        'groups' => $groups,
        'names' => $union['names'],
        'name_set' => $union['name_set'],
        'free_by_key' => esxi_inventory_free_union($groups),
        'unusable_by_key' => esxi_inventory_unusable_union($groups),
    ];
}

/**
 * Case-insensitive union of the grouped names, plus the lower-cased set the
 * unknown-value predicate reads. Pure, so the de-duplication rule is testable
 * without a database.
 *
 * First spelling wins, like every other dedupe in this project
 * (esxi_inventory_missing_values, repo_esxi_vlan_present_names): overwriting let
 * the LAST group decide whether the picker says "DataStore1" or "datastore1",
 * which pinned a display detail to the credential name the groups are sorted by,
 * and an operator may rename a credential at any time.
 *
 * @param array<int, array{names:array<int,string>}> $groups
 * @return array{names:array<int,string>, name_set:array<string,true>}
 */
function esxi_inventory_name_union(array $groups): array
{
    $names = [];
    foreach ($groups as $group) {
        foreach ($group['names'] as $name) {
            $key = esxi_inventory_name_key((string) $name);
            if ($key !== '' && !isset($names[$key])) {
                $names[$key] = (string) $name;
            }
        }
    }
    $nameSet = array_fill_keys(array_keys($names), true);
    $names = array_values($names);
    sort($names, SORT_NATURAL | SORT_FLAG_CASE);

    return ['names' => $names, 'name_set' => $nameSet];
}

/**
 * Groups the union by **how many credentials report each name**, which is the
 * question the operator actually has at this field.
 *
 * Grouping by credential answered "who reported this" and did it badly: a
 * datastore that lives on four hosts appeared four times, identically, and
 * carried `selected` in each of them (the browser then keeps the last). One name
 * belongs to exactly one bucket here, so the duplicates and the ambiguous
 * `selected` are gone by construction, not by a de-duplication pass.
 *
 * Three buckets:
 *  - `all`  reported by every credential that ever pulled successfully. This
 *           value survives whatever host the deploy picks.
 *  - `some` reported by several but not all of them.
 *  - `only` reported by exactly one. Choosing it commits the later host choice.
 *
 * $eligibleCount is the number of credentials with a recorded successful pull,
 * NOT the number of groups: a credential that pulled and genuinely holds no row
 * of this kind produces no group, and counting groups would have promoted every
 * name to "on all credentials" while a whole host was missing from the
 * denominator. With no successful pull at all there is nothing to prove, so
 * everything falls back to `some`/`only` and nothing claims completeness.
 *
 * Free space is the minimum across the credentials that report the name, one
 * rule for all three buckets: the target host is still open, so a shared
 * datastore may end up on the tightest of them; in an `only` bucket the minimum
 * is that host's own number.
 *
 * The same "worst reporting host wins" rule decides usability: a datastore that
 * one reporting credential holds in maintenance is marked and its free space
 * reads unknown, because the deploy may well pick exactly that host. Showing the
 * other hosts' number there would promise space the chosen host does not have.
 *
 * Pure, so every 0/1/many case is testable without a database.
 *
 * @param array<int, array{credential_id?:int, credential_name?:string, credential_host?:string, names:array<int,string>, free_by_key?:array<string,?int>, unusable_keys?:array<string,bool>}> $groups
 * @return array<int, array{scope:'all'|'some'|'only', credentials:array<int,array{id:int,name:string,host:string}>, names:array<int,string>, free_by_key:array<string,?int>, unusable_by_key:array<string,bool>}>
 */
function esxi_inventory_presence_buckets(array $groups, int $eligibleCount): array
{
    // name key => [spelling, reporting group indexes]
    $presence = [];
    foreach ($groups as $index => $group) {
        foreach ($group['names'] as $name) {
            $key = esxi_inventory_name_key((string) $name);
            if ($key === '') {
                continue;
            }
            if (!isset($presence[$key])) {
                $presence[$key] = ['name' => (string) $name, 'groups' => []];
            }
            $presence[$key]['groups'][$index] = true;
        }
    }

    $buckets = [];
    foreach ($presence as $key => $entry) {
        $indexes = array_keys($entry['groups']);
        $scope = match (true) {
            $eligibleCount > 0 && count($indexes) >= $eligibleCount => 'all',
            count($indexes) === 1 => 'only',
            default => 'some',
        };
        // One bucket per distinct host set, so "only on esxi-02" and "only on
        // esxi-05" stay apart while two names on the same pair share a group.
        $bucketKey = $scope === 'all' ? 'all' : $scope . ':' . implode(',', $indexes);
        if (!isset($buckets[$bucketKey])) {
            $buckets[$bucketKey] = [
                'scope' => $scope,
                'credentials' => $scope === 'all' ? [] : esxi_inventory_bucket_credentials($groups, $indexes),
                'names' => [],
                'free_by_key' => [],
                'unusable_by_key' => [],
            ];
        }
        $unusable = esxi_inventory_any_unusable($groups, $indexes, $key);
        $buckets[$bucketKey]['names'][] = $entry['name'];
        $buckets[$bucketKey]['unusable_by_key'][$key] = $unusable;
        $buckets[$bucketKey]['free_by_key'][$key] = $unusable ? null : esxi_inventory_free_min($groups, $indexes, $key);
    }

    foreach ($buckets as &$bucket) {
        sort($bucket['names'], SORT_NATURAL | SORT_FLAG_CASE);
    }
    unset($bucket);

    return esxi_inventory_sort_buckets($buckets);
}

/**
 * The credentials of one bucket, in the order the groups arrived (the repository
 * sorts them by credential name). Name and host together, because a fleet named
 * "esxi1".."esxi6" is not something an operator can map to a machine, and the
 * address is what the credentials page shows next to it.
 *
 * @param array<int, array<string, mixed>> $groups
 * @param array<int, int> $indexes
 * @return array<int, array{id:int, name:string, host:string}>
 */
function esxi_inventory_bucket_credentials(array $groups, array $indexes): array
{
    $out = [];
    foreach ($indexes as $index) {
        $group = $groups[$index] ?? [];
        $out[] = [
            'id' => (int) ($group['credential_id'] ?? 0),
            'name' => (string) ($group['credential_name'] ?? ''),
            'host' => (string) ($group['credential_host'] ?? ''),
        ];
    }

    return $out;
}

/**
 * Smallest free value among the credentials that report one name; null when none
 * of them carries a number (a kind without bytes, or a row that predates the
 * column). A null never wins the minimum: it is a hole in the cache, not a zero.
 *
 * @param array<int, array<string, mixed>> $groups
 * @param array<int, int> $indexes
 */
function esxi_inventory_free_min(array $groups, array $indexes, string $key): ?int
{
    $min = null;
    foreach ($indexes as $index) {
        $bytes = $groups[$index]['free_by_key'][$key] ?? null;
        if ($bytes === null) {
            continue;
        }
        $min = $min === null ? (int) $bytes : min($min, (int) $bytes);
    }

    return $min;
}

/**
 * True when at least one of the credentials that report this name holds it in a
 * state nobody can deploy onto (maintenance, inaccessible). One host is enough:
 * the target host is still open, so the answer has to hold for the worst pick.
 *
 * @param array<int, array<string, mixed>> $groups
 * @param array<int, int> $indexes
 */
function esxi_inventory_any_unusable(array $groups, array $indexes, string $key): bool
{
    foreach ($indexes as $index) {
        if (!empty($groups[$index]['unusable_keys'][$key])) {
            return true;
        }
    }

    return false;
}

/**
 * Reading order: what survives any host choice first, then partial presence,
 * then the single-host entries; within a scope by the credential labels, so the
 * list is stable across renders.
 *
 * @param array<string, array<string, mixed>> $buckets
 * @return array<int, array<string, mixed>>
 */
function esxi_inventory_sort_buckets(array $buckets): array
{
    $rank = ['all' => 0, 'some' => 1, 'only' => 2];
    $buckets = array_values($buckets);
    usort($buckets, static function (array $a, array $b) use ($rank): int {
        $label = static fn (array $bucket): string => implode(',', array_map(
            static fn (array $credential): string => $credential['name'],
            $bucket['credentials']
        ));

        return [$rank[$a['scope']], $label($a)] <=> [$rank[$b['scope']], $label($b)];
    });

    return $buckets;
}

/**
 * Free space per name across all credentials, for the flat picker. Same
 * min-across-hosts rule as esxi_inventory_free_min(); kept as its own function
 * because the flat list has no bucket to ask.
 *
 * @param array<int, array{free_by_key?: array<string, ?int>, unusable_keys?: array<string, bool>}> $groups
 * @return array<string, ?int> name key => free bytes
 */
function esxi_inventory_free_union(array $groups): array
{
    $free = [];
    foreach ($groups as $group) {
        foreach ($group['free_by_key'] ?? [] as $key => $bytes) {
            if (!array_key_exists($key, $free)) {
                $free[$key] = $bytes;
                continue;
            }
            if ($bytes === null) {
                continue;
            }
            $free[$key] = $free[$key] === null ? $bytes : min($free[$key], $bytes);
        }
    }
    // A name one host holds in maintenance carries no usable number, whatever
    // the others report: the deploy may pick that host (same rule as the
    // buckets).
    foreach (esxi_inventory_unusable_union($groups) as $key => $unusable) {
        if ($unusable) {
            $free[$key] = null;
        }
    }

    return $free;
}

/**
 * Names that at least one credential holds in an unusable state, for the flat
 * picker's labels.
 *
 * @param array<int, array{unusable_keys?: array<string, bool>}> $groups
 * @return array<string, bool> name key => unusable
 */
function esxi_inventory_unusable_union(array $groups): array
{
    $unusable = [];
    foreach ($groups as $group) {
        foreach ($group['unusable_keys'] ?? [] as $key => $flag) {
            if ($flag) {
                $unusable[$key] = true;
            }
        }
    }

    return $unusable;
}

/**
 * The preselect/hide decision. Pure, so the "three standalone hosts all report
 * ha-datacenter" case is testable without a database.
 *
 * @param array<int, array{names:array<int,string>}> $groups
 * @param array<int, string> $names union of all group names, de-duplicated
 * @return array{exact:bool}
 */
function esxi_inventory_option_flags(array $groups, array $names, int $credentialCount): array
{
    return [
        'exact' => esxi_inventory_groups_agree($groups, count($names))
            && $names !== []
            && count($groups) === $credentialCount,
    ];
}

/**
 * True when every credential reports the same set of names. Comparing each
 * group's de-duplicated size against the union's size is enough: a group can
 * never hold a name outside the union, so equal sizes mean equal sets.
 *
 * @param array<int, array{names:array<int,string>}> $groups
 */
function esxi_inventory_groups_agree(array $groups, int $unionSize): bool
{
    if ($groups === []) {
        return false;
    }

    foreach ($groups as $group) {
        $distinct = [];
        foreach ($group['names'] as $name) {
            $distinct[esxi_inventory_name_key((string) $name)] = true;
        }
        if (count($distinct) !== $unionSize) {
            return false;
        }
    }

    return true;
}

/**
 * True when the picker lists exactly what the deploy could use, whichever ESXi
 * credential is chosen at deploy time.
 */
function esxi_inventory_options_are_exact(array $options): bool
{
    return $options['exact'];
}

/** True when the field renders its options in presence buckets rather than flat. */
function esxi_inventory_options_are_bucketed(array $options): bool
{
    return count($options['buckets'] ?? []) > 1;
}

/**
 * Which notes a location field has to carry, derived from what it actually
 * renders. Never from the credential count alone: with six credentials of which
 * one was pulled the list is flat, and the old note claimed it was "grouped per
 * credential" over a list that had no groups at all.
 *
 * `host_choice`   the list spans several hosts or cannot prove it is complete,
 *                 so the value may be missing on the host chosen at deploy time.
 * `buckets`       the field renders presence buckets and they need one sentence
 *                 to be readable.
 * `never_pulled`  a configured credential has never completed a pull. This is
 *                 the dead end ADR-0023 accepted knowingly: a value that exists
 *                 only on that host is not offerable and therefore not
 *                 choosable, and until now the operator met the consequence at
 *                 the field without ever being told the cause.
 *
 * An empty option set is skipped for the first two (the field falls back to free
 * text there and claims nothing) but not for the last: an empty picker next to
 * an unpulled credential is exactly the case that needs explaining.
 *
 * @param array<int, array<string, mixed>> $optionSets the option arrays actually rendered
 * The token set is closed and spelled out in the return type, not merely in the
 * three appends below: the two renderers pick their sentence with a
 * default-free `match`, so a fourth token has to break the build at both of
 * them rather than reach a page that has nothing to say about it.
 *
 * @return list<'host_choice'|'buckets'|'never_pulled'> note tokens in reading order
 */
function esxi_inventory_location_notes(array $optionSets): array
{
    $hostChoice = false;
    $buckets = false;
    $neverPulled = false;
    foreach ($optionSets as $options) {
        if ((int) ($options['credential_count'] ?? 0) > (int) ($options['eligible_count'] ?? 0)) {
            $neverPulled = true;
        }
        if (($options['names'] ?? []) === []) {
            continue;
        }
        if (esxi_inventory_options_are_bucketed($options)) {
            $buckets = true;
            $hostChoice = true;
            continue;
        }
        if (!esxi_inventory_options_are_exact($options)) {
            $hostChoice = true;
        }
    }

    $notes = [];
    if ($hostChoice) {
        $notes[] = 'host_choice';
    }
    if ($buckets) {
        $notes[] = 'buckets';
    }
    if ($neverPulled) {
        $notes[] = 'never_pulled';
    }

    return $notes;
}
