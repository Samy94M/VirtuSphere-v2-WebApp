<?php

declare(strict_types=1);

// Etappe 14A bounds: one SSoT for every preflight and callback producer.
const VIRTUSPHERE_ESXI_DATACENTER_DERIVATION_MAX_AGE_SECONDS = 172800;
const VIRTUSPHERE_DEPLOY_PREFLIGHT_INITIAL_LIMIT = 10;
const VIRTUSPHERE_DEPLOY_PREFLIGHT_DETAIL_LIMIT = 50;
const VIRTUSPHERE_ESXI_SIMILAR_NAME_CANDIDATE_LIMIT = 5;
const VIRTUSPHERE_DEPLOY_PREFLIGHT_JSON_MAX_BYTES = 65536;
const VIRTUSPHERE_MAC_IMPORT_REQUEST_MAX_BYTES = 16777216;
const VIRTUSPHERE_MAC_IMPORT_RESULT_MAX_BYTES = 1048576;
const VIRTUSPHERE_MAC_IMPORT_RESPONSE_MAX_BYTES = 1048576;

/**
 * The regular scope of one deploy job, enforced before any remote work.
 *
 * Both numbers exist because the callback bounds above are absolute: a V2
 * result that does not fit answers 409 and the job keeps a failure whose cause
 * the operator cannot repair, AFTER the playbook already changed ESXi. So the
 * scope is capped where it is still a queue decision. The interface cap is the
 * vSphere per-VM NIC limit and therefore not ours to choose; the VM cap is
 * derived from the proven worst case (every VM failing, every identifier at its
 * maximum stored byte length, two errors per interface), which
 * MacImportBoundsTest re-measures: 40x10 produces a 0.50 MiB result and a
 * 0.83 MiB response, both inside the 1 MiB bound with margin.
 */
const VIRTUSPHERE_DEPLOY_JOB_SCOPE_MAX_VMS = 40;
const VIRTUSPHERE_DEPLOY_JOB_SCOPE_MAX_INTERFACES_PER_VM = 10;

/**
 * The one UTF-8 safe byte limiter. A byte-wise substr() on a freely assignable
 * name ends inside a codepoint, and the invalid tail then makes
 * json_encode(JSON_THROW_ON_ERROR) throw at the last moment before a write,
 * which is the one place a size guard must not fail. Input that is already
 * invalid is repaired first, because a caller cannot prove that a value read
 * from a foreign system is well-formed.
 */
function virtusphere_bounded_utf8_bytes(string $value, int $maxBytes): string
{
    if ($maxBytes <= 0) {
        return '';
    }
    if (!mb_check_encoding($value, 'UTF-8')) {
        $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }
    if (strlen($value) <= $maxBytes) {
        return $value;
    }

    return mb_strcut($value, 0, $maxBytes, 'UTF-8');
}

/** One wall-clock sample for every datacenter decision in this request/job. */
function virtusphere_request_now(): int
{
    if (!isset($GLOBALS['virtusphere_request_clock_sample'])) {
        $GLOBALS['virtusphere_request_clock_sample'] = time();
    }
    return (int) $GLOBALS['virtusphere_request_clock_sample'];
}

/** A long-running CLI worker starts a fresh request context for every job. */
function virtusphere_request_now_reset(): int
{
    $GLOBALS['virtusphere_request_clock_sample'] = time();
    return (int) $GLOBALS['virtusphere_request_clock_sample'];
}
