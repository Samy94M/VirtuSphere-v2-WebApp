<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/repo/missions.php';
require_once __DIR__ . '/repo/esxi_inventory.php';

/**
 * Gates and island builders of portal/deploy.php.
 *
 * Split out of the page (ADR-0006, portal rule) for two reasons: the page had
 * passed its line budget, and the gate below is a twin of a repository gate.
 * A twin that lives inside a page file cannot be unit-tested against its
 * original, which is how the two drifted in the first place.
 */

/**
 * Portal-side twin of repo_deploy_assert_mission_ready()'s location branch. The
 * repo throws English RuntimeExceptions as operator diagnostics; the user gets a
 * localized field error naming the actual reason. A mission may leave its
 * datacenter empty only when the ESXi credential chosen here reports exactly one
 * (ADR-0023).
 *
 * The mode check is the other half of the twin: the repo wraps the same
 * requirement in virtusphere_deploy_mode_needs_location(), and `autostart`
 * reads neither datacenter nor datastore (ADR-0025). Without the same guard here
 * the portal refused a job the backend would have queued, which is the worse
 * half of a disagreeing pair: the operator never reaches the backend's answer.
 */
function deploy_assert_datacenter_resolvable(mysqli $db, int $missionId, int $esxiCredentialId, string $mode): void
{
    if (!virtusphere_deploy_mode_needs_location($mode)) {
        return;
    }

    $mission = $missionId > 0 ? repo_get_mission($db, $missionId) : null;
    if ($mission === null || trim((string) ($mission['hypervisor_datacenter'] ?? '')) !== '') {
        return;
    }

    $candidates = repo_esxi_datacenters_for_credential($db, $esxiCredentialId);
    if (count($candidates) === 1) {
        return;
    }

    $message = $candidates === []
        ? __t('deploy.err_datacenter_no_inventory')
        : __t('deploy.err_datacenter_ambiguous', ['names' => implode(', ', $candidates)]);

    throw new ValidationException(['credential_esxi_id' => __t('deploy.err_datacenter_unresolved')], $message);
}
