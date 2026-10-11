<?php

declare(strict_types=1);

/**
 * K18 (FM-04, FM-E3): the first claim of a job stores its UTC start once, so
 * the job list and the job log can say when a scheduled job actually started
 * and how late. `locked_at` cannot carry that: every terminal transition clears
 * it. Historical jobs stay NULL; their start is not reconstructed.
 */
function migrate_0062_deploy_job_started_at(mysqli $db): void
{
    migrator_add_column($db, 'deploy_jobs', 'started_at', 'DATETIME NULL AFTER scheduled_at');

    migrator_out('0062: deploy jobs keep the UTC time of their first claim');
}
