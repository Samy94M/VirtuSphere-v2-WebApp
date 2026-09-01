<?php

declare(strict_types=1);

require_once __DIR__ . '/network_mac_constants.php';
require_once __DIR__ . '/vm_network_contract.php';

/**
 * How many preflight findings a payload may carry, and how many bytes it may
 * take (correction plan 16.4). One owner, because a list bounded twice by two
 * rules is a list whose counts disagree with its own contents.
 *
 * Three rules hold everywhere below, and each exists because its opposite fails
 * silently:
 *
 *  - `total` and the omitted count describe the COMPLETE set, never the shown
 *    one. A count derived from what fits answers "how much did we print", which
 *    is not a question an operator ever asks.
 *  - Selection happens on the canonical order, so "the first N" and "drop from
 *    the end" name the same rows in the server render, in the live JSON and in
 *    the stored worker result. This module does NOT sort: the order is applied
 *    where the two producers are merged (`repo_vm_network_preflight_blockers()`
 *    and `_warnings()`), so there is one sorting site rather than one per
 *    caller who remembered.
 *  - No bound may turn a blocker into a release. Every caller here is a
 *    PRESENTATION or a STORAGE path; the queue decision keeps reading the
 *    complete `deploy_queue_blockers()` result for the full scope.
 */

/**
 * @param list<array<string,mixed>> $findings
 * @return array{items:list<array<string,mixed>>,total:int,omitted_count:int}
 */
function deploy_preflight_bounded_findings(array $findings, int $limit = VIRTUSPHERE_DEPLOY_PREFLIGHT_DETAIL_LIMIT): array
{
    $total = count($findings);
    $items = array_slice($findings, 0, max(0, $limit));

    return ['items' => $items, 'total' => $total, 'omitted_count' => $total - count($items)];
}

/**
 * @param list<string> $candidates
 * @return array{items:list<string>,candidate_total:int,candidate_omitted_count:int}
 */
function deploy_preflight_bounded_candidates(array $candidates, int $limit = VIRTUSPHERE_ESXI_SIMILAR_NAME_CANDIDATE_LIMIT): array
{
    $unique = array_values(array_unique(array_map('strval', $candidates)));
    sort($unique, SORT_STRING);
    $items = array_slice($unique, 0, max(0, $limit));

    return [
        'items' => $items,
        'candidate_total' => count($unique),
        'candidate_omitted_count' => count($unique) - count($items),
    ];
}

/**
 * Encodes an envelope whose `$listKey` holds already selected findings and keeps
 * the encoded form inside `$maxBytes` by dropping entries from the END of that
 * list until it fits.
 *
 * From the end, because the canonical order puts the most useful findings
 * first: a byte cap then always removes the least actionable part, and removes
 * the same part on every render. The omitted count is recomputed against
 * `$total` INSIDE the loop, so the encoded bytes and the numbers describing
 * them can never disagree; `$total` itself never moves, because how many
 * findings exist does not depend on how large a response may be.
 *
 * An envelope whose fixed part alone exceeds the bound ends with an empty list
 * and `truncated_by_bytes` set, rather than with an exception: this runs on
 * read paths that must still be able to say "there are findings, and this
 * response could not carry them".
 *
 * `$stamp` receives the envelope and the omitted count of the current attempt
 * and returns it with any derived field filled in (the localized "N more"
 * sentence, for example). It runs INSIDE the loop, because a derived value
 * computed once before capping would describe a list that the cap then made
 * shorter, and a sentence naming the wrong number is worse than no sentence.
 *
 * @param array<string,mixed> $envelope
 * @param null|callable(array<string,mixed>,int):array<string,mixed> $stamp
 * @return array{payload:array<string,mixed>,json:string,truncated_by_bytes:bool}
 */
function deploy_preflight_bounded_json(
    array $envelope,
    string $listKey,
    int $total,
    int $maxBytes = VIRTUSPHERE_DEPLOY_PREFLIGHT_JSON_MAX_BYTES,
    string $totalKey = 'total',
    string $omittedKey = 'omitted_count',
    ?callable $stamp = null
): array {
    $items = array_values((array) ($envelope[$listKey] ?? []));
    $truncated = false;
    while (true) {
        $omitted = max(0, $total - count($items));
        $candidate = $envelope;
        $candidate[$listKey] = $items;
        $candidate[$totalKey] = $total;
        $candidate[$omittedKey] = $omitted;
        $candidate['truncated_by_bytes'] = $truncated;
        if ($stamp !== null) {
            $candidate = $stamp($candidate, $omitted);
        }
        $json = json_encode($candidate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) <= $maxBytes || $items === []) {
            return ['payload' => $candidate, 'json' => $json, 'truncated_by_bytes' => $truncated];
        }
        array_pop($items);
        $truncated = true;
    }
}

/**
 * The bounded form of the stored worker preflight result.
 *
 * A job blocked before its first remote step must end as
 * `configuration_blocked` with a readable reason. Letting the size guard throw
 * there would convert a precise, repairable verdict into `execution_failed`,
 * which claims a playbook ran and failed; none did. So this never throws: it
 * caps `vm_results` at the detail limit, then drops rows from the end until the
 * encoded result fits. `counts` keeps describing the complete finding set,
 * which is what makes the shortened list safe to read.
 *
 * Idempotent on purpose: an already bounded document carries its own
 * `vm_results_total`, and that number is what a second pass measures against.
 * Recomputing the total from the shortened list would let each re-encode
 * declare the truncation away, one call at a time.
 *
 * @param array<string,mixed> $result
 * @return array{result:array<string,mixed>,json:string}
 */
function deploy_preflight_bounded_result(
    array $result,
    int $maxBytes = VIRTUSPHERE_DEPLOY_PREFLIGHT_JSON_MAX_BYTES
): array {
    $rows = array_values((array) ($result['vm_results'] ?? []));
    $total = max(count($rows), (int) ($result['vm_results_total'] ?? 0));
    $result['vm_results'] = array_slice($rows, 0, VIRTUSPHERE_DEPLOY_PREFLIGHT_DETAIL_LIMIT);
    $encoded = deploy_preflight_bounded_json(
        $result,
        'vm_results',
        $total,
        $maxBytes,
        'vm_results_total',
        'vm_results_omitted_count'
    );
    $payload = $encoded['payload'];
    $payload['truncated_by_bytes'] = ($encoded['truncated_by_bytes'] || !empty($result['truncated_by_bytes']));

    return [
        'result' => $payload,
        'json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    ];
}
