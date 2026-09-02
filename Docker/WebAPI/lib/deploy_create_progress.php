<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/deploy_create_result.php';
require_once __DIR__ . '/deploy_urls.php';
require_once __DIR__ . '/help_page.php';
require_once __DIR__ . '/repo/deploy_create_results.php';
require_once __DIR__ . '/system_status.php';

/**
 * The per-VM create progress a person sees (Etappe 14B, Teiletappe G; plan
 * section 12.3).
 *
 * Every number here is counted from deploy_create_vm_results, never from the
 * log text above it. That is the whole point of the stage: the incident's
 * question - which of the fifteen VMs exist - had no answer because the only
 * record was prose, and a browser that re-derived counters from prose would put
 * the same fragility back one layer up.
 *
 * What this deliberately does NOT show is a percentage inside the current VM.
 * Nothing reports how far along one vmware_guest call is; a bar that filled
 * anyway would be the same invention as the idle timeout that started all of
 * this. The card says which VM is being worked on and since when, and that is
 * everything that is actually known.
 */

/** The counters shown, in the order the card renders them. */
const VIRTUSPHERE_CREATE_PROGRESS_COUNTERS = [
    'created',
    'updated',
    'unchanged',
    'skipped',
    'failed',
    'uncertain',
    'not_started',
];

/**
 * The view model of one job's create section, or null when the job has none.
 *
 * Null is not "zero of zero": a job that creates nothing (an export follow-up,
 * an inventory pull) has no create section, and rendering an empty card for it
 * would teach readers that the card means nothing.
 *
 * @param array<string,mixed> $job
 * @return array<string,mixed>|null
 */
function deploy_create_progress_view(mysqli $db, array $job): ?array
{
    $jobId = (int) ($job['id'] ?? 0);
    if ($jobId <= 0) {
        return null;
    }

    return deploy_create_progress_from_rows(repo_deploy_create_results($db, $jobId));
}

/**
 * The same view model from rows that are already in hand.
 *
 * Split out so the shape can be proved without a database: the cases that
 * matter here are the fifteenth unit sitting uncertain while fourteen are done,
 * and a job with no create section at all, and a real MySQL server cannot be
 * asked for either on demand. The renderer and the payload both go through this
 * one function, so a card and its live update cannot become two opinions.
 *
 * @param list<array<string,mixed>> $rows
 * @return array<string,mixed>|null
 */
function deploy_create_progress_from_rows(array $rows): ?array
{
    if ($rows === []) {
        return null;
    }
    $summary = deploy_create_summary($rows);
    $unresolved = null;
    foreach ($rows as $row) {
        if ((string) $row['status'] === VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN) {
            $unresolved = [
                'position' => (int) $row['position'],
                'vm_name' => (string) $row['vm_name'],
                'error_code' => (string) ($row['error_code'] ?? ''),
            ];
            break;
        }
    }

    $counters = [];
    foreach (VIRTUSPHERE_CREATE_PROGRESS_COUNTERS as $counter) {
        $counters[$counter] = (int) $summary[$counter];
    }

    return [
        'total' => (int) $summary['total'],
        'processed' => (int) $summary['processed'],
        // The card's own numerator, and deliberately NOT $summary['processed'].
        // That counter means "the worker got to this unit" and is pinned at 15
        // for the fourteen-plus-one case by the worker contract, which is the
        // right meaning there. It is the wrong one to put in front of a person:
        // "15 of 15 done" above a line reading "unresolved 1" tells them the
        // job finished and that something is open, in the same breath. An
        // unresolved unit is exactly the one whose outcome was never
        // established, so it does not count as concluded.
        'concluded' => (int) $summary['processed'] - (int) $summary['uncertain'],
        'counters' => $counters,
        'current' => $summary['current'],
        'unresolved' => $unresolved,
    ];
}

/**
 * The same view model for the polling JSON.
 *
 * It is the SAME function underneath, so the card the browser updates and the
 * card the server rendered cannot drift into two opinions about one job.
 *
 * @param array<string,mixed> $job
 * @return array<string,mixed>|null
 */
function deploy_create_progress_payload(mysqli $db, array $job): ?array
{
    return deploy_create_progress_payload_from_view(deploy_create_progress_view($db, $job));
}

/**
 * The wire shape of a view model that is already in hand.
 *
 * @param array<string,mixed>|null $view
 * @return array<string,mixed>|null
 */
function deploy_create_progress_payload_from_view(?array $view): ?array
{
    if ($view === null) {
        return null;
    }
    // The labels travel with the numbers rather than being assembled in JS: a
    // translated string is never built in the browser (ADR-0014), and the
    // status of the current unit is a token the reader must not see raw.
    $current = $view['current'];

    return [
        'total' => $view['total'],
        'concluded' => $view['concluded'],
        'counters' => $view['counters'],
        'position_label' => __t('deploy.create_progress_position', [
            'concluded' => $view['concluded'],
            'total' => $view['total'],
        ]),
        'current' => $current === null ? null : [
            'position' => (int) $current['position'],
            'vm_name' => (string) $current['vm_name'],
            'label' => deploy_create_progress_current_label($current),
            // Null, not an empty string: the browser hides the fragment rather
            // than printing a lone separator with nothing behind it.
            'since_label' => ($current['started_at'] ?? null) === null
                ? null
                : deploy_create_progress_since_label($current),
        ],
    ];
}

/**
 * What the current unit is doing, as a sentence.
 *
 * An unresolved unit is NOT called "running": it is where the job stopped, and
 * the word for that is that nobody has established what happened.
 *
 * @param array<string,mixed> $current
 */
function deploy_create_progress_current_label(array $current): string
{
    $key = match ((string) $current['status']) {
        VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN => 'deploy.create_progress_current_uncertain',
        VIRTUSPHERE_CREATE_RESULT_STATUS_RUNNING => 'deploy.create_progress_current_running',
        default => 'deploy.create_progress_current_preparing',
    };

    return __t($key, [
        'name' => (string) $current['vm_name'],
        'position' => (string) (int) $current['position'],
    ]);
}

/**
 * Since when the current unit has been running, as a sentence.
 *
 * Separate from the label because the two are separate elements in the card and
 * separate fields in the payload: the poller has to be able to replace one
 * without destroying the other.
 *
 * @param array<string,mixed> $current
 */
function deploy_create_progress_since_label(array $current): string
{
    return __t('deploy.create_progress_since', [
        'time' => portal_format_timestamp((string) $current['started_at']),
    ]);
}

/**
 * Renders the card above the raw log.
 *
 * Server-rendered, and the browser only refreshes its numbers: without
 * JavaScript the page still answers the question it exists for.
 *
 * @param array<string,mixed> $job
 * @param array<string,mixed> $user
 */
function deploy_log_render_create_progress(mysqli $db, array $job, array $user): void
{
    $view = deploy_create_progress_view($db, $job);
    if ($view === null) {
        return;
    }
    $current = $view['current'];
    ?>
    <section class="panel" data-deploy-create-progress>
        <h2><?php echo h(__t('deploy.create_progress_heading')); ?></h2>
        <p>
            <strong data-create-position><?php echo h(__t('deploy.create_progress_position', [
                'concluded' => $view['concluded'],
                'total' => $view['total'],
            ])); ?></strong>
        </p>
        <?php // Rendered even when nothing is in flight, and the two facts sit
              // in their own elements. Both are load-bearing for the poller: a
              // paragraph that only exists while a unit is running could never
              // be FILLED when the next one starts, and writing the whole line
              // as one text node would drop the "since" the moment the first
              // batch arrived. The separator is the middle dot the status
              // renderers use for two adjacent facts, and it is hidden with the
              // fragment it separates. ?>
        <p data-create-current>
            <span data-create-current-label><?php echo $current === null ? '' : h(deploy_create_progress_current_label($current)); ?></span>
            <span data-create-current-since<?php echo $current === null || ($current['started_at'] ?? null) === null ? ' hidden' : ''; ?>><span aria-hidden="true">&middot;</span> <span data-create-since-text><?php
                echo $current === null || ($current['started_at'] ?? null) === null ? '' : h(deploy_create_progress_since_label($current));
            ?></span></span>
        </p>
        <?php // The shared labelled-fact shape, but written out rather than
              // taken from system_status_fact_list(): every value carries a
              // data-create-count the poller updates in place, which is the
              // same reason a badge with a live attribute stays literal. The
              // class is status-facts because that is the one this project
              // styles; a card with its own class name would render as a naked
              // definition list and nobody would notice in a source review. ?>
        <dl class="status-facts">
            <?php foreach (VIRTUSPHERE_CREATE_PROGRESS_COUNTERS as $counter) { ?>
                <div>
                    <dt><?php echo h(__t('deploy.create_progress_count_' . $counter)); ?></dt>
                    <dd data-create-count="<?php echo h($counter); ?>"><?php echo h((string) $view['counters'][$counter]); ?></dd>
                </div>
            <?php } ?>
        </dl>
        <?php if ($view['unresolved'] !== null) { ?>
            <div class="alert alert-warning">
                <p><?php echo h(__t('deploy.create_progress_unresolved', [
                    'name' => (string) $view['unresolved']['vm_name'],
                    'position' => (string) (int) $view['unresolved']['position'],
                ])); ?></p>
                <?php // Two links side by side read as one, so they sit in the
                      // shared actions row with the separator the status
                      // renderers use (portal rule). Both are gated on the
                      // permission their destination needs, while the sentence
                      // above stays visible either way. ?>
                <p class="alert-actions">
                    <?php if (can('system.config', $user)) { ?>
                        <a href="<?php echo h(system_status_url(VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_ESXI)); ?>"><?php echo h(__t('deploy.create_progress_link_inventory')); ?></a>
                        <?php if (can('deploy.run', $user)) { ?>
                            <span aria-hidden="true">&middot;</span>
                        <?php } ?>
                    <?php } ?>
                    <?php if (can('deploy.run', $user)) { ?>
                        <a href="<?php echo h(help_url('deploy', 'help-create-progress')); ?>"><?php echo h(__t('deploy.create_progress_link_help')); ?></a>
                    <?php } ?>
                </p>
            </div>
        <?php } ?>
    </section>
    <?php
}
