<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_constants.php';

/**
 * The durable result of a deploy job the Ansible host preflight blocked before
 * any VM was touched (K2 / DF-L8). Today one blocker exists: the allowlist
 * probe saw the portal answer the host's MAC upload with the legacy 403, so a
 * mode that ends in a MAC import could never finish. The worker stores this
 * instead of a sentence so the job page can say it in the reader's language
 * and link the allowlist; the English job error stays in last_error.
 */
const VIRTUSPHERE_DEPLOY_HOST_PREFLIGHT_RESULT_VERSION = 1;
const VIRTUSPHERE_DEPLOY_HOST_PREFLIGHT_RESULT_KIND = 'host_preflight';
const VIRTUSPHERE_DEPLOY_HOST_PREFLIGHT_ALLOWLIST_DENIED = 'allowlist_denied';

/** @return array{version:int,kind:string,outcome:string,blocker:string,ip:string} */
function deploy_host_preflight_allowlist_result(string $ip): array
{
    return [
        'version' => VIRTUSPHERE_DEPLOY_HOST_PREFLIGHT_RESULT_VERSION,
        'kind' => VIRTUSPHERE_DEPLOY_HOST_PREFLIGHT_RESULT_KIND,
        'outcome' => VIRTUSPHERE_DEPLOY_STATUS_FAILED,
        'blocker' => VIRTUSPHERE_DEPLOY_HOST_PREFLIGHT_ALLOWLIST_DENIED,
        // Already validated by ansible_preflight_allowlist_verdict(); checked
        // again because this value is rendered from the database later.
        'ip' => filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '',
    ];
}

/** @return array{blocker:string,ip:string}|null */
function deploy_host_preflight_decode_result(?string $json): ?array
{
    if ($json === null || trim($json) === '') {
        return null;
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)
        || count($decoded) !== 5
        || ($decoded['version'] ?? null) !== VIRTUSPHERE_DEPLOY_HOST_PREFLIGHT_RESULT_VERSION
        || ($decoded['kind'] ?? null) !== VIRTUSPHERE_DEPLOY_HOST_PREFLIGHT_RESULT_KIND
        || ($decoded['outcome'] ?? null) !== VIRTUSPHERE_DEPLOY_STATUS_FAILED
        || ($decoded['blocker'] ?? null) !== VIRTUSPHERE_DEPLOY_HOST_PREFLIGHT_ALLOWLIST_DENIED
        || !is_string($decoded['ip'] ?? null)
    ) {
        return null;
    }
    $ip = $decoded['ip'];
    if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return null;
    }

    return ['blocker' => $decoded['blocker'], 'ip' => $ip];
}
