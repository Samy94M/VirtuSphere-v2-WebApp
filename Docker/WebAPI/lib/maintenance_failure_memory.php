<?php

declare(strict_types=1);

// The failure memory of the maintenance worker (TS1-K4, G2-7): pure rules, split
// from lib/maintenance_tasks.php so a unit test drives them without a database.

/**
 * The failure memory of the maintenance worker after one pass (TS1-K4, G2-7).
 *
 * A remembered failure belongs to the scheduled job it ran under: a retention
 * purge `retention:<name>` to `retention`, every other job to itself. When that
 * scheduled job ran again in this pass, its new result replaces the remembered
 * one: a success drops the name, a renewed failure keeps it. A job without a
 * schedule of its own (`audit-transitions`, it never appears in last_run) runs in
 * every pass and is therefore re-judged every pass.
 *
 * The group, not the exact name, is what decides, because `retention:audit` only
 * runs when something was purged. Keyed by its own name, a failed audit row would
 * stay reported after every later purge run that had nothing to audit, which is
 * the "job that never runs again" case; keyed by `retention`, the next retention
 * run that ends without a failure clears it.
 *
 * @param list<string> $remembered the list after the previous pass
 * @param list<string> $failures the jobs that failed in this pass
 * @param array<string,true> $ran the scheduled jobs that were due in this pass
 * @param list<string> $scheduled every scheduled job this process has seen
 * @return list<string> sorted, without duplicates
 */
function maintenance_worker_remembered_failures(array $remembered, array $failures, array $ran, array $scheduled): array
{
    $kept = [];
    foreach ($remembered as $job) {
        $group = maintenance_worker_failure_group($job);
        if (!isset($ran[$group]) && in_array($group, $scheduled, true)) {
            $kept[] = $job;
        }
    }
    $all = array_values(array_unique([...$kept, ...$failures]));
    sort($all, SORT_STRING);

    return $all;
}

/**
 * Whether this pass writes the self-heartbeat: when it is due, and at once when
 * the remembered list differs from what the row says (null: nothing written by
 * this process yet), in both directions, so a recovery is not held back either.
 *
 * @param list<string>|null $reported
 * @param list<string> $remembered
 */
function maintenance_worker_report_due(bool $heartbeatDue, ?array $reported, array $remembered): bool
{
    return $heartbeatDue || $reported !== $remembered;
}

/** The scheduled job a failure belongs to: the part before the first colon. */
function maintenance_worker_failure_group(string $job): string
{
    $colon = strpos($job, ':');

    return $colon === false ? $job : substr($job, 0, $colon);
}
