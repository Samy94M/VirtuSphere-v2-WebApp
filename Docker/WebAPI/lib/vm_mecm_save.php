<?php

declare(strict_types=1);

require_once __DIR__ . '/request.php';
require_once __DIR__ . '/mecm_plan.php';
require_once __DIR__ . '/repo/mecm_provenance.php';
require_once __DIR__ . '/repo/vms.php';
require_once __DIR__ . '/repo/log.php';

/**
 * What saving a VM does to MECM (AV-R12, ADR-0020 amendment 2026-10-19).
 *
 * A registered VM whose desired state differs from what MECM holds is queued for
 * the device-sync by any save. The device-sync applies the whole desired state of the VM
 * (OS, packages, mission), so when the wanted operating system deviates from the
 * one MECM holds, a transfer may reinstall the machine. The save then asks
 * first: the editor offers "Speichern und an MECM übertragen" and "Nur im Portal
 * speichern", and only the first sends the change. The server decides alone,
 * from the submitted button and the stored state after the write, under the
 * mission lock that repo_save_vm() holds until the transaction ends.
 *
 * vm_save_service.php runs the queue inside the save transaction; the editor
 * renders its state from vm_mecm_save_view() and follows the OS field in forms.js.
 */

/** POST field of the two save buttons; the single button posts none. */
const VIRTUSPHERE_VM_MECM_SAVE_FIELD = 'mecm_save';
const VIRTUSPHERE_VM_MECM_SAVE_TRANSFER = 'transfer';
const VIRTUSPHERE_VM_MECM_SAVE_PORTAL_ONLY = 'portal_only';

/** Audit actions of a queued transfer; the second one carries an accepted OS deviation. */
const VIRTUSPHERE_VM_MECM_ACTION_ON_SAVE = 'queued_mecm_transfer_on_save';
const VIRTUSPHERE_VM_MECM_ACTION_OS_CONFIRMED = 'queued_mecm_transfer_os_confirmed';

/** The save mode the POST asks for. A missing or unknown value is the plain save. */
function vm_mecm_save_mode(array $post): string
{
    $mode = request_string($post, VIRTUSPHERE_VM_MECM_SAVE_FIELD);

    return in_array($mode, [VIRTUSPHERE_VM_MECM_SAVE_TRANSFER, VIRTUSPHERE_VM_MECM_SAVE_PORTAL_ONLY], true) ? $mode : '';
}

/** The OS MECM holds for a VM: the name of its own `os` rule, '' when none is known. */
function vm_mecm_own_os(array $ownedRules): string
{
    foreach ($ownedRules as $rule) {
        if ((string) ($rule['collection_type'] ?? '') === VIRTUSPHERE_MECM_RULE_TYPE_OS) {
            return trim((string) ($rule['collection_name'] ?? ''));
        }
    }

    return '';
}

/**
 * A wanted OS other than the one MECM holds. An unknown MECM OS deviates too: a
 * transfer would then add an OS collection that nothing in the portal vouches for.
 */
function vm_mecm_os_deviates(string $portalOs, string $mecmOs): bool
{
    return trim($portalOs) !== trim($mecmOs);
}

/**
 * The decision of one save. A plan with nothing pending has nothing to send. Any
 * save sends a pending plan, also one this save did not change: since the
 * separate transfer button is gone, a difference left from an earlier edit would
 * otherwise never reach MECM. Only an OS deviation holds it back, until the
 * operator chooses the transfer button.
 */
function vm_mecm_save_queues(bool $pending, string $mode, bool $deviates): bool
{
    return $pending && ($mode === VIRTUSPHERE_VM_MECM_SAVE_TRANSFER || !$deviates);
}

/**
 * The MECM part of one save. It runs inside the save transaction, so a queued
 * transfer and its audit row commit or roll back with the write.
 *
 * @param array<string, mixed> $saved the stored bundle after the save
 * @return array{queued: bool, held: bool, added: list<string>, removed: list<string>}
 */
function vm_mecm_save_transfer(mysqli $db, int $missionId, int $vmId, array $saved, string $mode, int $userId): array
{
    $result = ['queued' => false, 'held' => false, 'added' => [], 'removed' => []];
    if ((string) ($saved['mecm_sync_state'] ?? '') !== VIRTUSPHERE_MECM_SYNC_REGISTERED) {
        return $result;
    }

    $state = mecm_transfer_state($db, $missionId, $vmId);
    $added = array_column($state['plan']['add'], 'name');
    $removed = array_column($state['plan']['remove'], 'collection_name');
    $pending = $added !== [] || $removed !== [];
    $deviates = vm_mecm_os_deviates((string) ($saved['vm_os'] ?? ''), vm_mecm_own_os($state['owned']));
    $queued = vm_mecm_save_queues($pending, $mode, $deviates);

    if ($queued) {
        repo_mark_vm_for_mecm_resync($db, $missionId, $vmId, $userId);
        audit_event_required($db, VIRTUSPHERE_AUDIT_EVENT_VM_MECM_CHANGED, 'vm', $vmId, VIRTUSPHERE_AUDIT_RESULT_SUCCESS, [
            'action' => $deviates ? VIRTUSPHERE_VM_MECM_ACTION_OS_CONFIRMED : VIRTUSPHERE_VM_MECM_ACTION_ON_SAVE,
            'mission_id' => $missionId,
        ], $userId);
    }

    return [
        'queued' => $queued,
        'held' => !$queued && $pending,
        'added' => $queued ? $added : [],
        'removed' => $queued ? $removed : [],
    ];
}

/**
 * The editor state of one VM: whether it gets the MECM save buttons (a registered
 * VM, never a template), the OS MECM holds, and whether the wanted OS deviates.
 *
 * @param array<string, mixed> $vm the bundle the editor renders (posted values after a refused save)
 * @return array{split: bool, mecm_os: string, deviates: bool}
 */
function vm_mecm_save_view(mysqli $db, int $vmId, bool $isTemplate, array $vm): array
{
    $split = $vmId > 0 && !$isTemplate && (string) ($vm['mecm_sync_state'] ?? '') === VIRTUSPHERE_MECM_SYNC_REGISTERED;
    if (!$split) {
        return ['split' => false, 'mecm_os' => '', 'deviates' => false];
    }

    $mecmOs = vm_mecm_own_os(repo_mecm_rules_for_vm($db, $vmId));

    return ['split' => true, 'mecm_os' => $mecmOs, 'deviates' => vm_mecm_os_deviates((string) ($vm['vm_os'] ?? ''), $mecmOs)];
}

/** The attribute that lets forms.js follow the OS field of a registered VM. */
function vm_mecm_save_form_attrs(array $view): string
{
    return $view['split'] ? ' data-mecm-os="' . h($view['mecm_os']) . '"' : '';
}

/**
 * The hint sentence. Only the portal value is live: forms.js rewrites its span
 * while the operator changes the OS field, so the sentence itself stays whole in
 * the catalog.
 */
function vm_mecm_os_hint_html(string $mecmOs, string $portalOs): string
{
    $mecmLabel = $mecmOs !== '' ? $mecmOs : __t('vm_edit.mecm_os_unknown');
    $sentence = __t('vm_edit.mecm_os_hint', ['mecm' => '{mecm}', 'portal' => '{portal}']);

    return str_replace(
        ['{mecm}', '{portal}'],
        [h($mecmLabel), '<span data-mecm-os-portal>' . h($portalOs) . '</span>'],
        h($sentence)
    );
}

/**
 * The success sentence of a save. A queued transfer names what it sends and says
 * that removals do not uninstall; a plain save keeps the existing sentence.
 *
 * @param array{queued: bool, held: bool, added: list<string>, removed: list<string>} $mecm
 */
function vm_mecm_save_success_message(array $mecm): string
{
    if (!$mecm['queued']) {
        return __t('vm_edit.flash_saved');
    }

    $parts = [];
    if ($mecm['added'] !== []) {
        $parts[] = __t('vm_edit.flash_mecm_added', ['names' => implode(', ', $mecm['added'])]);
    }
    if ($mecm['removed'] !== []) {
        $parts[] = __t('vm_edit.flash_mecm_removed', ['names' => implode(', ', $mecm['removed'])]);
    }

    return __t('vm_edit.flash_mecm_queued', ['changes' => implode(' ', $parts)]);
}
