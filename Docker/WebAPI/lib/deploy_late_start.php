<?php

declare(strict_types=1);

/**
 * K18 (FM-04, FM-E3): a scheduled job that started late is named, never
 * expired. There is no deadline after which a due job is dropped; the worker
 * still runs it, and list and log say how late it started.
 *
 * The threshold is far above a normal pickup (the idle wait of the worker is
 * seconds) and below the delay an operator would plan around: a job held by a
 * worker outage or by a long job of another mission reaches it, a job that
 * waited a few seconds for the poll does not. Exactly at the threshold counts.
 */
const VIRTUSPHERE_DEPLOY_LATE_START_THRESHOLD_MINUTES = 15;

/**
 * Whole minutes between the planned and the actual start, or null when the job
 * was not scheduled, has not started, or started within the threshold. Both
 * values are UTC DATETIME strings from deploy_jobs.
 *
 * @param array<string,mixed> $job
 */
function deploy_job_late_start_minutes(array $job): ?int
{
    $scheduled = trim((string) ($job['scheduled_at'] ?? ''));
    $started = trim((string) ($job['started_at'] ?? ''));
    if ($scheduled === '' || $started === '') {
        return null;
    }
    $utc = new DateTimeZone('UTC');
    $plannedAt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $scheduled, $utc);
    $startedAt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $started, $utc);
    if ($plannedAt === false || $startedAt === false) {
        return null;
    }
    $minutes = intdiv($startedAt->getTimestamp() - $plannedAt->getTimestamp(), 60);

    return $minutes >= VIRTUSPHERE_DEPLOY_LATE_START_THRESHOLD_MINUTES ? $minutes : null;
}
