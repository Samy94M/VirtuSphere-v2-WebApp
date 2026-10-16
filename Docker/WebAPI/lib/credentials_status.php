<?php

declare(strict_types=1);

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/ansible_test_config.php';
require_once __DIR__ . '/credentials.php';
require_once __DIR__ . '/esxi_automation.php';
require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/deploy_urls.php';

/**
 * Credentials page: the cadence note that sits under a status badge.
 *
 * The status column renders a badge over a timestamp, which everywhere else in
 * the portal means "the last time we polled". ESXi inventory and Ansible full
 * tests have independently configurable schedules. One short line says whether
 * the value renews itself, which is what an operator reads the timestamp for
 * in the first place.
 *
 * Split per credential type rather than dispatched on one: the ESXi answer
 * needs three separate facts to be honest, and a single function would have to
 * default them for the Ansible call. A default that assumes the healthy case is
 * the exact failure this line exists to prevent.
 */

/**
 * Whether an ESXi credential's inventory pull repeats on its own, and if not,
 * which blocker is stopping it.
 *
 * This function does not decide anything: esxi_inventory_automation_blocker()
 * is the same predicate the scheduler skips on, and this only gives its answer
 * a sentence. Restating the conditions here is what the cadence line exists to
 * prevent, one level up.
 *
 * The match is deliberately exhaustive: a blocker added to the SSoT without a
 * sentence throws instead of falling back to a silent half-truth.
 * `CredentialStatusCadenceTest` walks VIRTUSPHERE_ESXI_AUTOMATION_BLOCKERS so
 * a missing sentence breaks in CI, not first on the live page. The explicit
 * default keeps static analysis honest about the function's string input.
 *
 * @param array<string, mixed>|null $esxiState Inventory state row, or null when
 *        the credential was never pulled (which is not the same as paused).
 * @param bool $ansibleHostSelected Result of esxi_inventory_ansible_resolution().
 * @param bool $deployWorkerAlive The deploy worker's status row is not stale. The
 *        pull is a deploy job, so without a worker it is enqueued and never runs.
 */
function credential_cadence_esxi(int $intervalHours, ?array $esxiState, bool $ansibleHostSelected, bool $deployWorkerAlive = true): string
{
    $blocker = esxi_inventory_automation_blocker($intervalHours, $esxiState, $ansibleHostSelected, $deployWorkerAlive);
    if ($blocker !== null) {
        return match ($blocker) {
            VIRTUSPHERE_ESXI_AUTOMATION_INTERVAL_OFF => __t('credentials.cadence_esxi_off'),
            VIRTUSPHERE_ESXI_AUTOMATION_NO_ANSIBLE_HOST => __t('credentials.cadence_esxi_no_ansible'),
            VIRTUSPHERE_ESXI_AUTOMATION_NO_WORKER => __t('credentials.cadence_esxi_no_worker'),
            VIRTUSPHERE_ESXI_AUTOMATION_PAUSED => __t('credentials.cadence_esxi_paused'),
            default => throw new LogicException('Unknown ESXi inventory automation blocker: ' . $blocker),
        };
    }

    // __t() substitutes but does not pluralize, so the sentence is picked by count.
    return $intervalHours === 1
        ? __t('credentials.cadence_esxi_one')
        : __t('credentials.cadence_esxi', ['hours' => $intervalHours]);
}

/**
 * Ansible scheduling and evidence expiry remain independent. A stopped service
 * or disabled schedule does not prolong the validity of old evidence.
 */
function credential_cadence_ansible(int $intervalHours = VIRTUSPHERE_ANSIBLE_TEST_INTERVAL_HOURS_DEFAULT): string
{
    return __t($intervalHours === 0 ? 'ansible_test.cadence_off' : 'ansible_test.cadence', [
        'hours' => $intervalHours, 'days' => VIRTUSPHERE_ANSIBLE_PREFLIGHT_STALE_AFTER_DAYS,
    ]);
}

/**
 * The open inventory job of one ESXi row, as its button and its status line show
 * it. Null when no job is open, and the caller then renders the plain button.
 *
 * The job comes from repo_esxi_inventory_pending_jobs(), which reads the active
 * SSoT (queued, running, cancelling) once for the whole table. A cancelling pull
 * still occupies the credential, so it counts as running here.
 *
 * @param array<string, mixed>|null $job The open job of this credential, or null.
 * @return array{job_id: int, running: bool, title: string, line: string, url: string}|null
 */
function credential_inventory_pending_notice(?array $job): ?array
{
    if ($job === null) {
        return null;
    }
    $jobId = (int) ($job['id'] ?? 0);
    $running = in_array((string) ($job['status'] ?? ''), [
        VIRTUSPHERE_DEPLOY_STATUS_RUNNING,
        VIRTUSPHERE_DEPLOY_STATUS_CANCELLING,
    ], true);

    return [
        'job_id' => $jobId,
        'running' => $running,
        'title' => __t($running ? 'credentials.inventory_running_title' : 'credentials.inventory_queued_title', ['job' => (string) $jobId]),
        'line' => __t($running ? 'credentials.inventory_running_line' : 'credentials.inventory_queued_line'),
        'url' => deploy_job_log_url($jobId),
    ];
}
