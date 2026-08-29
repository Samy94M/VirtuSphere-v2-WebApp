<?php

declare(strict_types=1);

// The two read-only panels above a job log: the phase sequence and the filter
// (Etappe 13). Both are GET only; nothing here writes, so neither carries CSRF
// or a confirmation.
require_once __DIR__ . '/deploy_log_filter.php';
require_once __DIR__ . '/deploy_log_phases.php';

/**
 * The steps this job ran, in order, with the one that is still open marked.
 *
 * @param array{phases:list<array{playbook:string,begin_seq:int,end_seq:?int,complete:bool}>,current:?string} $timeline
 */
function deploy_log_render_phases(array $timeline): void
{
    ?>
    <section class="panel">
        <h2><?php echo h(__t('deploy.phases_heading')); ?></h2>
        <p class="muted"><?php echo h(__t('deploy.phases_hint')); ?></p>
        <?php if ($timeline['phases'] === []) { ?>
            <p class="muted"><?php echo h(__t('deploy.phases_empty')); ?></p>
        <?php } else { ?>
            <ol class="phase-list">
                <?php foreach ($timeline['phases'] as $phase) {
                    $isCurrent = !$phase['complete'] && $timeline['current'] === $phase['playbook'];
                    ?>
                    <li>
                        <span class="phase-name"><?php echo h($phase['playbook']); ?></span>
                        <?php
                        // Three distinguishable outcomes, never a colour alone:
                        // an unfinished step is not the same thing as the one
                        // that is running, and reading them apart is the whole
                        // reason the markers are persisted.
                        if ($isCurrent) {
                            echo portal_badge('warning', __t('deploy.phase_current'));
                        } elseif ($phase['complete']) {
                            echo portal_badge('success', __t('deploy.phase_complete'));
                        } else {
                            echo portal_badge('danger', __t('deploy.phase_incomplete'));
                        }
                        ?>
                        <span class="muted"><?php echo h($phase['end_seq'] === null
                            ? __t('deploy.phase_lines_open', ['from' => $phase['begin_seq']])
                            : __t('deploy.phase_lines', ['from' => $phase['begin_seq'], 'to' => $phase['end_seq']])); ?></span>
                    </li>
                <?php } ?>
            </ol>
        <?php } ?>
    </section>
    <?php
}

/**
 * The filter form and, while a filter is active, the sentence that says what
 * the reader is actually looking at.
 *
 * @param array{q:string,source:string,phase:string,active:bool} $filter
 * @param list<string> $phaseNames
 */
function deploy_log_render_filter(int $jobId, array $filter, array $phaseNames, int $matchCount, bool $capped): void
{
    ?>
    <section class="panel">
        <h2><?php echo h(__t('deploy.filter_heading')); ?></h2>
        <form class="inline-form" method="get" action="deploy_log.php">
            <input type="hidden" name="id" value="<?php echo h((string) $jobId); ?>">
            <label class="filter-field" for="deploy-log-q"><?php echo h(__t('deploy.filter_search_label')); ?></label>
            <input id="deploy-log-q" name="q" value="<?php echo h($filter['q']); ?>" maxlength="<?php echo h((string) VIRTUSPHERE_DEPLOY_LOG_SEARCH_MAX_LENGTH); ?>">
            <label class="filter-field" for="deploy-log-source"><?php echo h(__t('deploy.filter_source_label')); ?></label>
            <select id="deploy-log-source" name="source">
                <option value=""><?php echo h(__t('deploy.filter_all')); ?></option>
                <?php foreach (array_keys(deploy_log_filter_sources()) as $source) { ?>
                    <option value="<?php echo h($source); ?>"<?php echo $filter['source'] === $source ? ' selected' : ''; ?>><?php echo h(deploy_job_log_source_label($source)); ?></option>
                <?php } ?>
            </select>
            <?php // A job that never marked a step offers no step filter: an
                  // empty select would ask a question with no answer. ?>
            <?php if ($phaseNames !== []) { ?>
                <label class="filter-field" for="deploy-log-phase"><?php echo h(__t('deploy.filter_phase_label')); ?></label>
                <select id="deploy-log-phase" name="phase">
                    <option value=""><?php echo h(__t('deploy.filter_all')); ?></option>
                    <?php foreach ($phaseNames as $name) { ?>
                        <option value="<?php echo h($name); ?>"<?php echo $filter['phase'] === $name ? ' selected' : ''; ?>><?php echo h($name); ?></option>
                    <?php } ?>
                </select>
            <?php } ?>
            <button class="button button-secondary" type="submit"><?php echo h(__t('deploy.filter_apply')); ?></button>
            <?php if ($filter['active']) { ?>
                <a class="button button-secondary" href="<?php echo h(deploy_job_log_url($jobId)); ?>"><?php echo h(__t('deploy.filter_reset')); ?></a>
            <?php } ?>
        </form>
        <?php if ($filter['active']) { ?>
            <?php // Never silent about what a filtered view is. A reader who
                  // takes a gap between two matches for silence in the run has
                  // been misled by the page, not by the log. ?>
            <div class="alert alert-info">
                <p><?php echo h(__t('deploy.filter_active_notice')); ?></p>
                <?php if ($matchCount === 0) { ?>
                    <p><?php echo h(__t('deploy.filter_result_empty')); ?></p>
                <?php } else { ?>
                    <p><?php echo h(__t($matchCount === 1 ? 'deploy.filter_result_count_one' : 'deploy.filter_result_count_many', ['count' => $matchCount])); ?></p>
                <?php } ?>
                <?php if ($capped) { ?>
                    <p><?php echo h(__t('deploy.filter_result_capped', ['limit' => VIRTUSPHERE_DEPLOY_LOG_SEARCH_LIMIT])); ?></p>
                <?php } ?>
            </div>
        <?php } ?>
    </section>
    <?php
}
