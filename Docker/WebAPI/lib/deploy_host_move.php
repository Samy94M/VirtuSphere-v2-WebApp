<?php

declare(strict_types=1);

require_once __DIR__ . '/lang.php';

/**
 * K17 (FM-E1): one sentence for bound VMs a Create would build anew on another
 * host. It names the previous host(s) and says what keeps running there. The
 * queue warning, the queue confirmation and the retry confirmation all read it,
 * so the three places cannot describe the same case differently.
 *
 * @param list<array<string,mixed>> $moves rows from repo_vm_create_host_moves()
 */
function deploy_host_move_message(array $moves): string
{
    $hosts = [];
    foreach ($moves as $move) {
        $access = trim((string) ($move['bound_credential_name'] ?? ''));
        $label = $access === ''
            ? (string) $move['bound_host']
            : __t('deploy.host_move_host_with_access', ['host' => (string) $move['bound_host'], 'access' => $access]);
        $hosts[$label] = true;
    }
    $hostList = implode(', ', array_keys($hosts));

    return count($moves) === 1
        ? __t('deploy.host_move_one', ['name' => (string) $moves[0]['vm_name'], 'hosts' => $hostList])
        : __t('deploy.host_move_many', ['count' => count($moves), 'hosts' => $hostList]);
}
