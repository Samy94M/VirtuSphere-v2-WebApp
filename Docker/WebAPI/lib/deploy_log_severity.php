<?php

declare(strict_types=1);

// Which job-log lines are error lines (E1, 29.10.2026).
//
// One owner for three readers: the server-rendered table, the JSON entries the
// browser appends (it reads `severity` and decides nothing itself) and the
// "errors only" filter, whose SQL takes the same markers as a case-sensitive
// LIKE. A second list in JavaScript or in the query would let the highlight and
// the filter disagree about the same line.
//
// The markers are fixed text, never a parse of the message: a create progress
// line (`[n/total] FAIL create <vm> ...`, `[n/total] HOLD create <vm> ...`,
// lib/deploy_worker_create.php) and the head of an Ansible failure
// (`fatal: [host]: FAILED!`). The first match in this order wins.

const VIRTUSPHERE_DEPLOY_LOG_SEVERITY_FAIL = 'fail';
const VIRTUSPHERE_DEPLOY_LOG_SEVERITY_HOLD = 'hold';
const VIRTUSPHERE_DEPLOY_LOG_SEVERITY_FATAL = 'fatal';

/** Severity => the fixed text a line of that severity contains. */
const VIRTUSPHERE_DEPLOY_LOG_SEVERITY_MARKERS = [
    VIRTUSPHERE_DEPLOY_LOG_SEVERITY_FAIL => '] FAIL create ',
    VIRTUSPHERE_DEPLOY_LOG_SEVERITY_HOLD => '] HOLD create ',
    VIRTUSPHERE_DEPLOY_LOG_SEVERITY_FATAL => 'fatal: [',
];

/** The severity of one stored log line, or null for an ordinary line. */
function deploy_log_line_severity(string $line): ?string
{
    foreach (VIRTUSPHERE_DEPLOY_LOG_SEVERITY_MARKERS as $severity => $marker) {
        if (str_contains($line, $marker)) {
            return $severity;
        }
    }

    return null;
}

/**
 * The search that finds every FAIL (or HOLD) line of one create unit, all
 * attempts included. The position prefix is unique within a job, so a VM whose
 * name begins with another VM's name is never matched by mistake.
 */
function deploy_log_create_unit_needle(int $position, int $total, string $severity): string
{
    $verb = $severity === VIRTUSPHERE_DEPLOY_LOG_SEVERITY_HOLD ? 'HOLD' : 'FAIL';

    return '[' . $position . '/' . $total . '] ' . $verb . ' create';
}
