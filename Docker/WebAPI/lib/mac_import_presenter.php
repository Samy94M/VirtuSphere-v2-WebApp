<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_urls.php';
require_once __DIR__ . '/mac_import_constants.php';
require_once __DIR__ . '/vm_urls.php';

/** @return array{message:string,code:string,context:string,action_label:string,action_url:string} */
function mac_import_present_error(array $error, array $job, bool $vmExists = true): array
{
    $code = (string) ($error['code'] ?? '');
    $meta = VIRTUSPHERE_MAC_IMPORT_ERROR_META[$code] ?? null;
    $message = is_array($meta)
        ? __t('deploy.mac_error_' . (string) $meta['key'])
        : __t('deploy.mac_error_unknown', ['code' => $code]);
    $contextParts = [];
    if ((string) ($error['vlan'] ?? '') !== '') {
        $contextParts[] = __t('deploy.mac_context_vlan', ['value' => (string) $error['vlan']]);
    }
    if ((string) ($error['mac'] ?? '') !== '') {
        $contextParts[] = __t('deploy.mac_context_mac', ['value' => (string) $error['mac']]);
    }

    $source = is_array($meta) ? (string) $meta['source'] : 'protocol';
    $missionId = (int) ($job['mission_id'] ?? 0);
    $vmId = (int) ($error['vm_id'] ?? 0);
    [$actionLabel, $actionUrl] = match ($source) {
        'network' => $vmExists && $missionId > 0 && $vmId > 0
            ? [__t('deploy.mac_action_network'), vm_edit_url($missionId, $vmId, 'interfaces')]
            : [__t('deploy.mac_action_mission'), mission_details_url($missionId)],
        // ESXi-owned evidence cannot be repaired by a portal URL. Keep the
        // operator instruction visible, but do not invent a misleading link.
        'external' => [__t('deploy.mac_action_external'), ''],
        'identity' => [__t('deploy.mac_action_identity'), mission_details_url($missionId)],
        default => [__t('deploy.mac_action_log'), deploy_job_raw_log_url((int) ($job['id'] ?? 0))],
    };
    $requiredPermission = match ($source) {
        'network', 'identity' => 'vms.write',
        'external' => '',
        default => 'deploy.run',
    };
    if ($requiredPermission !== '' && (!function_exists('can') || !can($requiredPermission))) {
        $actionLabel = '';
        $actionUrl = '';
    }

    return [
        'message' => $message,
        'code' => $code,
        'context' => implode(' ', $contextParts),
        'action_label' => $actionLabel,
        'action_url' => $actionUrl,
    ];
}

/** @return list<array<string,mixed>> */
function mac_import_present_vm_rows(array $result, array $job, ?array $existingVmIds = null): array
{
    $errorsByVm = [];
    foreach ((array) ($result['errors'] ?? []) as $error) {
        if (is_array($error) && (int) ($error['vm_id'] ?? 0) > 0) {
            $errorsByVm[(int) $error['vm_id']][] = $error;
        }
    }
    $rows = [];
    foreach ((array) ($result['vm_results'] ?? []) as $vmResult) {
        if (!is_array($vmResult)) {
            continue;
        }
        $vmId = (int) ($vmResult['vm_id'] ?? 0);
        $vmExists = $existingVmIds === null || in_array($vmId, $existingVmIds, true);
        $presentedErrors = [];
        foreach ($errorsByVm[$vmId] ?? [] as $error) {
            $presentedErrors[] = mac_import_present_error($error, $job, $vmExists);
        }
        $rows[] = [
            'vm_id' => $vmId,
            'vm_name' => (string) ($vmResult['vm_name'] ?? ''),
            'vm_exists' => $vmExists,
            'outcome' => (string) ($vmResult['outcome'] ?? 'failed'),
            'updated_interfaces' => (int) ($vmResult['updated_interfaces'] ?? 0),
            'wds' => is_array($vmResult['wds'] ?? null) ? $vmResult['wds'] : null,
            'errors' => $presentedErrors,
        ];
    }
    return $rows;
}
