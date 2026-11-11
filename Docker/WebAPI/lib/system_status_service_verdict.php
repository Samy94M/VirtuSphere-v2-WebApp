<?php

declare(strict_types=1);

// The verdict of the deploy service card (SY3): which next step belongs to the
// compact state. It follows the precedence of deploy_service_summary_label()
// and deploy_service_badge_tone() (offline, manual review, degraded or
// cooldown, recovering, claim pause), so the line under the heading names the
// same fault as the overview card, and every state that is not in order has a
// step: before SY3 "Nicht erreichbar" and "Wartet vor Neustart" had none.
require_once __DIR__ . '/deploy_service_health.php';
require_once __DIR__ . '/portal_time_display.php';
require_once __DIR__ . '/system_status_verdict.php';

/**
 * The reason the verdict names and its escaped next step; reason '' while the
 * service needs nothing.
 *
 * @param array<string,mixed> $snapshot
 * @return array{reason: string, html: string}
 */
function deploy_service_verdict_action(array $snapshot): array
{
    $availability = (string) $snapshot['availability'];
    $attention = (string) $snapshot['recovery_attention'];
    $claimState = (string) $snapshot['claim_state'];
    $nextRetry = $snapshot['supervisor']['next_retry_at'] ?? null;

    return match (true) {
        // The liveness row under "Interne Dienste" points here; this card owns
        // the repair instruction for a silent service (SY3).
        $availability === VIRTUSPHERE_DEPLOY_AVAILABILITY_OFFLINE => ['reason' => 'offline', 'html' => h(__t('system_status.action_deploy_worker'))],
        $attention === VIRTUSPHERE_DEPLOY_ATTENTION_MANUAL_REVIEW => ['reason' => 'manual_review', 'html' => h(__t('system_status_cards.service_action_manual'))],
        $availability === VIRTUSPHERE_DEPLOY_AVAILABILITY_DEGRADED => ['reason' => 'degraded', 'html' => h(__t('system_status.service_degraded_hint'))],
        $availability === VIRTUSPHERE_DEPLOY_AVAILABILITY_COOLDOWN => ['reason' => 'cooldown', 'html' => h(__t('system_status_cards.service_action_cooldown'))
            . (is_string($nextRetry) && $nextRetry !== '' ? ' ' . h(__t('system_status_cards.service_action_next_retry')) . ' ' . portal_time_html($nextRetry) . '.' : '')],
        $attention === VIRTUSPHERE_DEPLOY_ATTENTION_RECOVERING => ['reason' => 'recovering', 'html' => h(__t('system_status_cards.service_action_recovering'))],
        $claimState !== VIRTUSPHERE_DEPLOY_CLAIM_ACCEPTING => ['reason' => 'claim', 'html' => h(deploy_service_claim_hint($claimState))],
        default => ['reason' => '', 'html' => ''],
    };
}

/**
 * The parts of the deploy service that need the dashboard's attention, each in
 * its axis word, and only those (SY3): a ready service or "no open case" next to
 * a pause is normal and does not belong in a warning. An empty list means no
 * banner. A busy service or a job scheduled for later is normal operation.
 *
 * @param array<string,mixed> $snapshot
 * @return list<string>
 */
function deploy_service_attention_parts(array $snapshot): array
{
    $availability = (string) $snapshot['availability'];
    $attention = (string) $snapshot['recovery_attention'];
    $claimState = (string) $snapshot['claim_state'];
    $parts = [];
    if (in_array($availability, [VIRTUSPHERE_DEPLOY_AVAILABILITY_DEGRADED, VIRTUSPHERE_DEPLOY_AVAILABILITY_OFFLINE], true)) {
        $parts[] = deploy_service_availability_label($availability);
    }
    if ($claimState !== VIRTUSPHERE_DEPLOY_CLAIM_ACCEPTING) {
        $parts[] = deploy_service_claim_label($claimState);
    }
    if ($attention !== VIRTUSPHERE_DEPLOY_ATTENTION_NONE) {
        $parts[] = deploy_service_attention_label($attention);
    }

    return $parts;
}

/**
 * The verdict line of the deploy service card in the tone of its overview card.
 *
 * @param array<string,mixed> $snapshot
 */
function deploy_service_verdict(array $snapshot): string
{
    return system_status_verdict(deploy_service_summary_tone($snapshot), deploy_service_verdict_action($snapshot)['html']);
}
