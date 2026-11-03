<?php

declare(strict_types=1);

/**
 * Portal wording for the rollout hostname (Etappe 14D, ADR-0043).
 *
 * The rule this file exists for: a technical value stays raw everywhere it is
 * stored or transported, and only what a person reads passes through here. The
 * repository throws a reason CODE, the machine API carries a snapshot, and this
 * is the one place either becomes a sentence.
 */

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/mecm_hostname.php';

/**
 * The localized reason a MECM-ID reset was refused.
 *
 * An exhaustive `match` with no default, over VIRTUSPHERE_MECM_RESET_BLOCKERS.
 * A default arm would let a new blocker reach an operator as a raw token or,
 * worse, as somebody else's sentence; without one, adding a code to the closed
 * vocabulary fails the build until it has been given words in both locales.
 *
 * The `@param` union is a second copy of that constant and is deliberate: it is
 * what lets PHPStan see the match as exhaustive instead of demanding a default
 * arm, which is the one thing this function must not have. MecmRolloutContract
 * Test reads this docblock and compares it against the constant, so a seventh
 * code fails there and at every call site that passes it (the same shape
 * disk_type_label() uses).
 *
 * @param 'template'|'active_job'|'no_mac'|'invalid_hostname'|'already_pending'|'error' $reasonCode one of VIRTUSPHERE_MECM_RESET_BLOCKERS
 */
function mecm_reset_blocker_message(string $reasonCode): string
{
    return match ($reasonCode) {
        'template' => __t('portal.vm_mecm_reset_template_blocked'),
        'active_job' => __t('portal.vm_mecm_reset_active_job'),
        'no_mac' => __t('portal.vm_mecm_reset_no_mac'),
        'invalid_hostname' => __t('portal.vm_mecm_reset_invalid_hostname', ['max' => VIRTUSPHERE_MECM_ROLLOUT_HOSTNAME_MAX_LENGTH]),
        'already_pending' => __t('portal.vm_mecm_reset_already_pending_reason'),
        'error' => __t('portal.vm_mecm_reset_error'),
    };
}

/**
 * Whether the previous rollout's MECM device is still waiting to be deleted.
 *
 * This is a STATE, not a refusal (decision of 2026-09-03). VirtuSphere deletes
 * nothing in MECM, so after a reset exactly one step is left and it belongs to
 * a person; saying so on the VM is more use than a message that only appears if
 * somebody clicks reset a second time. The device sync stays fail-closed on the
 * same fact, which is where the protection actually has to be.
 *
 * @param array<string, mixed> $vm a deploy_vms row
 */
function mecm_rollout_awaits_device_deletion(array $vm): bool
{
    return (string) ($vm['mecm_previous_id'] ?? '') !== '';
}

/**
 * Whether the editor has to show the "current vs next rollout name" pair at all.
 *
 * Deliberately narrow: while the desired value and the snapshot are the same
 * machine, the compact display everyone already knows is the truth and a second
 * read-only line would only add noise. The pair appears exactly when a
 * correction has been typed that this rollout will NOT pick up, because that is
 * the state an operator cannot otherwise tell from the page.
 *
 * @param array<string, mixed> $vm a deploy_vms row
 */
function mecm_rollout_shows_divergence(array $vm): bool
{
    $snapshot = (string) ($vm['mecm_rollout_hostname'] ?? '');
    if ($snapshot === '') {
        // No rollout yet (a template, or a row from before its first save):
        // there is no "current" name to contrast the desired one with.
        return false;
    }

    return !mecm_hostname_same($snapshot, (string) ($vm['vm_hostname'] ?? ''));
}
