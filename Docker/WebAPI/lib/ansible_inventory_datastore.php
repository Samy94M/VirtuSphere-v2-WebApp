<?php

declare(strict_types=1);

/**
 * Datastore health and per-query outcome of one inventory pull (ADR-0023).
 * Split out of lib/ansible_inventory.php (Etappe 7, ADR-0006): both read a
 * raw playbook answer and turn a bare 0/absence into a fact with a reason,
 * unchanged.
 */

/**
 * Health of one raw datastore object, kept as its cache meta. `capacity` and
 * `freeSpace` say how big it is; these two say whether that size means anything
 * right now, and the parser used to throw them away.
 *
 * Field paths follow the documented vmware_datastore_info output and carry
 * fallbacks like the size fields do. Both are tri-state: a field the module did
 * not report stays absent, so the reader says "not known" instead of guessing
 * "healthy" for a datastore that may be in maintenance (ADR-0023 tri-state
 * contract). Null when neither field arrived, which keeps meta_json NULL rather
 * than writing an object full of nulls.
 *
 * @param array<string, mixed> $raw
 * @return array<string, mixed>|null
 */
function ansible_inventory_datastore_health(array $raw): ?array
{
    $meta = [];
    foreach (['accessible', 'is_accessible'] as $key) {
        if (array_key_exists($key, $raw)) {
            $meta['accessible'] = $raw[$key];
            break;
        }
    }
    foreach (['maintenanceMode', 'maintenance_mode'] as $key) {
        if (array_key_exists($key, $raw)) {
            $meta['maintenance'] = $raw[$key];
            break;
        }
    }

    return $meta !== [] ? $meta : null;
}

/**
 * The job-log line for the datastore health of one pull, in the shape of
 * `Inventory queries:` and `ESXi capabilities:` above.
 *
 * Written on every successful pull including the all-good case, and for the
 * same reason those two are: a field path that silently stopped matching looks
 * exactly like a fleet where nothing is in maintenance, and only a line that
 * also speaks when everything is fine lets an operator tell the two apart. That
 * is the lesson of the portgroup query that reported 0 for months.
 *
 * English, like every other job-log line (operator diagnostics, not portal
 * prose). Null when the pull carried no datastores at all: there is nothing to
 * report on, and the item counts above already say so.
 *
 * @param array<int, array<string, mixed>> $datastores parsed cache items
 */
function ansible_inventory_datastore_health_log_line(array $datastores): ?string
{
    $total = count($datastores);
    if ($total === 0) {
        return null;
    }

    $withAccessible = 0;
    $withMaintenance = 0;
    $inMaintenance = [];
    $inaccessible = [];
    foreach ($datastores as $datastore) {
        $health = esxi_datastore_health($datastore['meta_json'] ?? null);
        if ($health['accessible'] !== null) {
            $withAccessible++;
        }
        if ($health['maintenance'] !== null) {
            $withMaintenance++;
        }
        if ($health['maintenance'] === true) {
            $inMaintenance[] = (string) ($datastore['name'] ?? '?');
        }
        if ($health['accessible'] === false) {
            $inaccessible[] = (string) ($datastore['name'] ?? '?');
        }
    }

    $line = sprintf(
        'Datastore health: %d datastore(s), accessibility reported for %d, maintenance mode reported for %d.',
        $total,
        $withAccessible,
        $withMaintenance
    );
    if ($inMaintenance !== []) {
        $line .= ' In maintenance: ' . implode(', ', $inMaintenance) . '.';
    }
    if ($inaccessible !== []) {
        $line .= ' Inaccessible: ' . implode(', ', $inaccessible) . '.';
    }

    return $line;
}

/**
 * What every single query of one pull did. A pull is several separate queries
 * and only the first is the connection canary, so it can succeed while one
 * query answered nothing at all. An empty list cannot say which of the three
 * happened (the host has none / the account may not look / the call was
 * rejected before reaching ESXi), and that ambiguity is what let a rejected
 * portgroup query read as "this host has no portgroups" for months.
 *
 * Absent for a pull whose playbook predates the report; the caller then says
 * nothing rather than claiming every query answered.
 *
 * @return array<string, array{state:string, message:string}>
 */
function ansible_parse_inventory_queries(mixed $raw): array
{
    if (!is_array($raw)) {
        return [];
    }

    $queries = [];
    foreach ($raw as $name => $entry) {
        $name = trim((string) $name);
        if ($name === '' || !is_array($entry)) {
            continue;
        }
        $state = VIRTUSPHERE_INVENTORY_QUERY_ANSWERED;
        if (!empty($entry['failed'])) {
            $state = VIRTUSPHERE_INVENTORY_QUERY_REJECTED;
        } elseif (!empty($entry['skipped'])) {
            $state = VIRTUSPHERE_INVENTORY_QUERY_SKIPPED;
        }
        // Collapsed to one line before truncation: a module message may carry a
        // traceback, and a summary line that silently becomes twelve lines is
        // no longer the one line an operator can scan. The full text stays in
        // the playbook output above it either way.
        // Bytewise on purpose: `\s` without /u cannot match inside a UTF-8
        // sequence, so it collapses newlines without the /u failure mode of
        // returning null on malformed input and losing the message entirely.
        $message = trim((string) preg_replace('/\s+/', ' ', (string) ($entry['msg'] ?? '')));
        $queries[$name] = [
            'state' => $state,
            'message' => mb_substr($message, 0, VIRTUSPHERE_INVENTORY_QUERY_MESSAGE_MAX_LENGTH),
        ];
    }

    return $queries;
}

/**
 * The job-log line that turns a 0 from a verdict into a fact with a reason.
 * Written on every successful pull, including the all-good case: a reader who
 * only ever sees the line when something is wrong does not learn that a pull
 * has parts, and this line is where an operator finds out which part was quiet.
 *
 * Queries that failed the same way are named together. Rendered in the actual
 * job log, the ungrouped version turned a systematic failure into fourteen
 * lines of the same sentence, which hides the one thing the line is for: which
 * queries were silent.
 *
 * English like every other job-log line (operator diagnostics, not portal
 * prose). Null for a pull without the report, so an old playbook stays silent
 * instead of claiming completeness it never measured.
 */
function ansible_inventory_queries_log_line(array $queries): ?string
{
    if ($queries === []) {
        return null;
    }

    // Grouped by state and message, because the common failure is systematic:
    // a wrong credential, a module version or a bad argument list hits several
    // queries with the identical sentence, and repeating it once per query
    // buried the six names that matter under six copies of the same text.
    $groups = [];
    $quiet = 0;
    foreach ($queries as $name => $query) {
        $state = (string) ($query['state'] ?? '?');
        if ($state === VIRTUSPHERE_INVENTORY_QUERY_ANSWERED) {
            continue;
        }
        $quiet++;
        $message = trim((string) ($query['message'] ?? ''));
        $groups[$state . "\0" . $message][] = (string) $name;
    }

    $total = count($queries);
    if ($quiet === 0) {
        return sprintf('Inventory queries: all %d answered.', $total);
    }

    $parts = [];
    foreach ($groups as $key => $names) {
        [$state, $message] = explode("\0", $key, 2);
        $parts[] = implode(', ', $names) . ' ' . $state . ($message !== '' ? ' (' . $message . ')' : '');
    }

    return sprintf(
        'Inventory queries: %d of %d answered, %d without an answer - %s',
        $total - $quiet,
        $total,
        $quiet,
        implode('; ', $parts)
    );
}
