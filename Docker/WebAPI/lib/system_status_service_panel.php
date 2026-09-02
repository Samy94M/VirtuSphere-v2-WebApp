<?php

declare(strict_types=1);

// The "Bereitstellungsdienst" card on the System status page (Etappe 13R).
//
// It renders all three axes, never a single word for them. `busy` together with
// `pause_after_current` and `offline` together with `manual_review` are both
// real and both matter, and a card that folds them into one badge would drop
// whichever half its precedence happened to lose.
require_once __DIR__ . '/deploy_service_health.php';
require_once __DIR__ . '/deploy_urls.php';
require_once __DIR__ . '/system_status_service_actions.php';
require_once __DIR__ . '/system_status_shared_panels.php';

/** The localized name of one availability state. */
function deploy_service_availability_label(string $state): string
{
    return match ($state) {
        VIRTUSPHERE_DEPLOY_AVAILABILITY_READY => __t('system_status.service_ready'),
        VIRTUSPHERE_DEPLOY_AVAILABILITY_BUSY => __t('system_status.service_busy'),
        VIRTUSPHERE_DEPLOY_AVAILABILITY_DEGRADED => __t('system_status.service_degraded'),
        VIRTUSPHERE_DEPLOY_AVAILABILITY_COOLDOWN => __t('system_status.service_cooldown'),
        VIRTUSPHERE_DEPLOY_AVAILABILITY_OFFLINE => __t('system_status.service_offline'),
        default => __t('system_status.service_unknown'),
    };
}

/** The localized name of one claim state. */
function deploy_service_claim_label(string $state): string
{
    return match ($state) {
        VIRTUSPHERE_DEPLOY_CLAIM_ACCEPTING => __t('system_status.service_claim_accepting'),
        VIRTUSPHERE_DEPLOY_CLAIM_PAUSE_AFTER_CURRENT => __t('system_status.service_claim_pause_after_current'),
        VIRTUSPHERE_DEPLOY_CLAIM_PAUSED => __t('system_status.service_claim_paused'),
        default => __t('system_status.service_unknown'),
    };
}

/**
 * The localized name of one process contract.
 *
 * It exists because the card used to print `source_contract` raw, so an
 * operator read the token "worker_v1" on a status page. The technical value
 * stays raw everywhere it is stored or transported; only what a person reads
 * goes through a label helper, and an unknown value gets a neutral sentence
 * rather than its token.
 */
function deploy_service_contract_label(string $contract): string
{
    return match ($contract) {
        VIRTUSPHERE_SUPERVISOR_CONTRACT_WORKER => __t('system_status.service_contract_worker'),
        VIRTUSPHERE_SUPERVISOR_CONTRACT_SUPERVISOR => __t('system_status.service_contract_supervisor'),
        default => __t('system_status.service_contract_unknown'),
    };
}

/** The localized name of one supervisor phase, or a dash when none is published. */
function deploy_service_supervisor_phase_label(?string $phase): string
{
    return match ($phase) {
        VIRTUSPHERE_SUPERVISOR_PHASE_IDLE, VIRTUSPHERE_SUPERVISOR_PHASE_RUNNING => __t('system_status.service_supervisor_watching'),
        VIRTUSPHERE_SUPERVISOR_PHASE_STOPPING => __t('system_status.service_supervisor_stopping'),
        VIRTUSPHERE_SUPERVISOR_PHASE_COOLDOWN, VIRTUSPHERE_SUPERVISOR_PHASE_WAIT_RETRY => __t('system_status.service_supervisor_cooldown'),
        VIRTUSPHERE_SUPERVISOR_PHASE_MANUAL => __t('system_status.service_supervisor_manual'),
        VIRTUSPHERE_SUPERVISOR_PHASE_STOPPED => __t('system_status.service_supervisor_stopped'),
        default => __t('system_status.service_supervisor_none'),
    };
}

/** The localized name of one attention state. */
function deploy_service_attention_label(string $state): string
{
    return match ($state) {
        VIRTUSPHERE_DEPLOY_ATTENTION_NONE => __t('system_status.service_attention_none'),
        VIRTUSPHERE_DEPLOY_ATTENTION_RECOVERING => __t('system_status.service_attention_recovering'),
        VIRTUSPHERE_DEPLOY_ATTENTION_MANUAL_REVIEW => __t('system_status.service_attention_manual'),
        default => __t('system_status.service_unknown'),
    };
}

/**
 * The supervisor and its child, as two separate facts.
 *
 * Only under `supervisor_v1`, and deliberately so: under `worker_v1` there is
 * no supervisor and no child, and two rows saying "none" would be two rows an
 * operator has to learn to ignore. Both are shown whenever they exist, because
 * a compact badge is a summary and a summary must not be the only place a fact
 * appears: `cooldown` with a healthy supervisor and `degraded` with a hung
 * child look the same in one word and are two different next steps.
 *
 * @param array<string,mixed> $snapshot
 * @return list<array{label:string,html:string}>
 */
function system_status_supervisor_facts(array $snapshot): array
{
    if ((string) $snapshot['source_contract'] !== VIRTUSPHERE_SUPERVISOR_CONTRACT_SUPERVISOR) {
        return [];
    }
    $supervisor = $snapshot['supervisor'] ?? [];
    $facts = [
        [
            'label' => __t('system_status.service_fact_supervisor'),
            'html' => h(deploy_service_supervisor_phase_label($supervisor['phase'] ?? null)),
        ],
        [
            'label' => __t('system_status.service_fact_child'),
            'html' => h(($supervisor['child_alive'] ?? false)
                ? __t('system_status.service_child_answering')
                : __t('system_status.service_child_silent')),
        ],
    ];
    if ((int) ($supervisor['restart_count'] ?? 0) > 0) {
        $facts[] = [
            'label' => __t('system_status.service_fact_restarts'),
            'html' => h((string) (int) $supervisor['restart_count']),
        ];
    }
    if (($supervisor['next_retry_at'] ?? null) !== null) {
        $facts[] = [
            'label' => __t('system_status.service_fact_next_retry'),
            'html' => system_status_fact_time($supervisor['next_retry_at']),
        ];
    }

    return $facts;
}

/**
 * @param array<string,mixed> $snapshot the deploy_service_health_snapshot()
 * @param array<string,mixed> $user
 */
function system_status_render_deploy_service(array $snapshot, array $user): void
{
    $canManage = can('system.config', $user);
    $claimState = (string) $snapshot['claim_state'];
    $active = $snapshot['active'];
    $queue = $snapshot['queue'];
    ?>
    <section class="panel status-section" id="<?php echo h(VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_DEPLOY_SERVICE); ?>">
        <div class="section-heading-actions">
            <div>
                <h2><?php echo h(__t('system_status.service_heading')); ?></h2>
                <p class="muted"><?php echo h(__t('system_status.service_hint')); ?></p>
            </div>
        </div>
        <article class="status-row">
            <div class="status-row-head">
                <strong><?php echo h(deploy_service_availability_label((string) $snapshot['availability'])); ?></strong>
                <?php // All three, always. The compact badge exists for a table
                      // cell; a detail view that shows only it would hide the
                      // half its precedence dropped. ?>
                <?php echo portal_badge((string) $snapshot['badge'], deploy_service_availability_label((string) $snapshot['availability'])); ?>
                <?php echo portal_badge(
                    $claimState === VIRTUSPHERE_DEPLOY_CLAIM_ACCEPTING ? 'neutral' : 'warning',
                    deploy_service_claim_label($claimState)
                ); ?>
                <?php echo portal_badge(
                    match ((string) $snapshot['recovery_attention']) {
                        VIRTUSPHERE_DEPLOY_ATTENTION_MANUAL_REVIEW => 'danger',
                        VIRTUSPHERE_DEPLOY_ATTENTION_RECOVERING => 'warning',
                        default => 'neutral',
                    },
                    deploy_service_attention_label((string) $snapshot['recovery_attention'])
                ); ?>
            </div>
            <?php
            echo system_status_fact_list([
                ['label' => __t('system_status.service_fact_contract'), 'html' => h(deploy_service_contract_label((string) $snapshot['source_contract']))],
                ...system_status_supervisor_facts($snapshot),
                ['label' => __t('system_status.service_fact_queue_due'), 'html' => h((string) $queue['due'])],
                ['label' => __t('system_status.service_fact_queue_scheduled'), 'html' => h((string) $queue['scheduled'])],
                ['label' => __t('system_status.service_fact_oldest_due'), 'html' => system_status_fact_time($queue['oldest_due_at'])],
                [
                    'label' => __t('system_status.service_fact_active'),
                    // A job id alone is not a link an operator can use; the log
                    // is where the answer to "what is it doing" lives.
                    'html' => $active['job_id'] === null
                        ? '&mdash;'
                        : '<a href="' . h(deploy_job_log_url((int) $active['job_id'])) . '">'
                            . h(__t('system_status.service_fact_active_job', ['id' => (int) $active['job_id']])) . '</a>',
                ],
                ['label' => __t('system_status.service_fact_heartbeat'), 'html' => system_status_fact_time($active['heartbeat_at'])],
            ]);
            ?>
            <?php if ($claimState !== VIRTUSPHERE_DEPLOY_CLAIM_ACCEPTING && $snapshot['claim']['changed_at'] !== null) { ?>
                <?php // Who and when, with the same deleted-user fallback the
                      // terminal presenter uses: an account that is gone must
                      // not erase the fact that somebody decided this. ?>
                <p class="muted"><?php echo h(__t('system_status.service_claim_by', [
                    'actor' => (string) ($snapshot['claim']['changed_by_name'] ?? __t('system_status.service_claim_actor_unknown')),
                    'time' => portal_format_timestamp((string) $snapshot['claim']['changed_at']),
                ])); ?></p>
            <?php } ?>
            <?php if ($snapshot['availability'] === VIRTUSPHERE_DEPLOY_AVAILABILITY_DEGRADED) { ?>
                <p class="status-action"><?php echo h(__t('system_status.service_degraded_hint')); ?></p>
            <?php } ?>
            <?php // The explanation stays visible without the permission; only
                  // the buttons are gated, exactly like every other action on
                  // this page. ?>
            <?php if ($canManage) { ?>
                <div class="actions">
                    <?php // Read-only in effect: it re-runs the policy and sets the
                          // flag the worker reads. Nothing is lost, so it does not
                          // ask; the flash reports what it found. ?>
                    <form class="inline-form" method="post" action="system_status.php">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="deploy_recovery_review">
                        <button class="button button-secondary" type="submit"><?php echo h(__t('system_status.service_review')); ?></button>
                    </form>
                    <?php if ($claimState === VIRTUSPHERE_DEPLOY_CLAIM_ACCEPTING) { ?>
                        <form class="inline-form" method="post" action="system_status.php">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="deploy_claim_pause">
                            <button class="button button-secondary" type="submit" data-confirm="<?php echo h(__t('system_status.service_confirm_pause')); ?>"><?php echo h(__t('system_status.service_pause')); ?></button>
                        </form>
                    <?php } else { ?>
                        <form class="inline-form" method="post" action="system_status.php">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="deploy_claim_resume">
                            <?php // Resuming loses nothing, so it does not ask. ?>
                            <button class="button" type="submit"><?php echo h(__t('system_status.service_resume')); ?></button>
                        </form>
                    <?php } ?>
                </div>
            <?php } ?>
        </article>
    </section>
    <?php
}
