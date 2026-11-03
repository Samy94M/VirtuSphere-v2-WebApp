<?php

declare(strict_types=1);

// Display-only deploy-mode labels, shared by portal views and input validation.
// Stored modes and the technical validation set remain unchanged.
require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/vm_network_contract.php';

/**
 * The human name of a deploy mode.
 *
 * The six postable modes are exactly the keys of
 * virtusphere_deploy_mode_labels(); `inventory` is the seventh value a reader
 * can meet, because the ESXi scheduler queues it and it therefore appears in
 * the job list, on the System status Ansible card and in a job's own header.
 * It stays out of the technical set on purpose: that set decides what a POST
 * may contain, and a mode nobody can post must not become postable by being
 * given a name.
 */
function deploy_mode_label(string $mode): string
{
    return match ($mode) {
        VIRTUSPHERE_DEPLOY_MODE_FULL => __t('status.mode_full'),
        'create' => __t('status.mode_create'),
        'powercycle' => __t('status.mode_powercycle'),
        'export' => __t('status.mode_export'),
        'start' => __t('status.mode_start'),
        VIRTUSPHERE_DEPLOY_MODE_AUTOSTART => __t('status.mode_autostart'),
        VIRTUSPHERE_DEPLOY_MODE_INVENTORY => __t('status.mode_inventory'),
        default => __t('status.mode_unknown'),
    };
}

/**
 * The display names of several modes for a sentence that lists them. A text
 * naming "the modes that can be staggered" gets its list from the constant or
 * predicate that decides it, so it cannot keep naming a mode the code dropped.
 *
 * @param list<string> $modes
 */
function deploy_mode_label_list(array $modes): string
{
    return implode(', ', array_map(static fn (string $mode): string => deploy_mode_label($mode), $modes));
}

/**
 * The four mode lists of the network contract help: which modes block on a
 * missing unique mapping or WDS readiness and which only warn. Each list comes
 * from the predicate the deploy gate itself asks (lib/vm_network_contract.php).
 *
 * @return array{mapping_modes:string,mapping_notice_modes:string,wds_modes:string,wds_notice_modes:string}
 */
function deploy_network_contract_mode_lists(): array
{
    $user = virtusphere_user_deploy_modes();
    $mapping = array_values(array_filter($user, 'deploy_mode_requires_unique_network_mapping'));
    $wds = array_values(array_filter($user, 'deploy_mode_requires_wds_ready'));

    return [
        'mapping_modes' => deploy_mode_label_list($mapping),
        'mapping_notice_modes' => deploy_mode_label_list(array_values(array_diff($user, $mapping))),
        'wds_modes' => deploy_mode_label_list($wds),
        'wds_notice_modes' => deploy_mode_label_list(array_values(array_diff($user, $wds))),
    ];
}

