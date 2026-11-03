<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/vm_urls.php';

/** @return array{url:string,label:string}|null */
function deploy_create_progress_replacement_action(array $replacement, int $missionId, ?array $user = null): ?array
{
    $vmId = (int) ($replacement['vm_id'] ?? 0);
    if ($missionId <= 0 || $vmId <= 0 || !can('vms.write', $user)) {
        return null;
    }

    return ['url' => vm_edit_url($missionId, $vmId), 'label' => __t('deploy.create_progress_link_vm')];
}
