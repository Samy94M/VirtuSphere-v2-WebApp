<?php

declare(strict_types=1);

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/credentials.php';
require_once __DIR__ . '/esxi_automation.php';

/**
 * Credentials page: the cadence note that sits under a status badge.
 *
 * The status column renders a badge over a timestamp, which everywhere else in
 * the portal means "the last time we polled". Only ESXi rows are polled; the
 * Ansible preflight runs on click and never repeats, so both shapes looked the
 * same while promising different things. One short line per row says whether
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
 * The Ansible preflight has no scheduler at all, so this says so and names the
 * window after which the recorded result stops counting as evidence. Nothing
 * about the ESXi automation changes this line.
 */
function credential_cadence_ansible(): string
{
    return __t('credentials.cadence_manual', ['days' => VIRTUSPHERE_ANSIBLE_PREFLIGHT_STALE_AFTER_DAYS]);
}
