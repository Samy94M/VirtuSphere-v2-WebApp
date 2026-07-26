<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/settings_page.php';
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

/**
 * One notice per unmet prerequisite of the queue form, each naming the page that
 * clears it.
 *
 * A single sentence used to list all four requirements at once and pointed
 * nowhere: it named neither the one that is actually missing nor the page that
 * fixes it, so the operator in front of the disabled button had to know the
 * portal's layout to act on it.
 *
 * `permission` is the permission of the TARGET page, not of this one, and the
 * caller renders the link only when the user holds it (portal rule: a link uses
 * the same permission as its handler). The sentence is not gated: a user with
 * deploy.run alone holds none of these, and hiding the reason would leave the
 * button disabled without a cause. They lose the link, not the answer.
 *
 * The API base URL keeps the resolver's own message, which already separates
 * the portal setting from APP_PUBLIC_BASE_URL in the .env. Whether the notice
 * appears is decided by the flag, never by that string: a resolver failure with
 * an empty message would otherwise disable the button and explain nothing.
 *
 * The result is also the queue gate (`$canQueue`), so the boxes and the disabled
 * button cannot disagree. Anything that blocks queueing has to become a notice
 * here, which is the point: a prerequisite added to the gate alone would grey
 * the button out silently. The one deliberate exception is a selected mission
 * without VMs, which is answered at the VM field itself.
 *
 * @return list<array{message: string, url: string, label: string, permission: string}>
 */
function deploy_prerequisite_notices(
    bool $hasMissions,
    bool $hasEsxiCredential,
    bool $hasAnsibleCredential,
    bool $apiBaseUrlReady,
    string $apiBaseUrlError
): array {
    $notices = [];

    if (!$hasMissions) {
        $notices[] = [
            'message' => __t('deploy.req_missions'),
            // The list the deploy form draws from, not the template view.
            'url' => 'missions.php?type=missions',
            'label' => __t('deploy.req_missions_link'),
            // The page is open to everyone, but the fix on it is not: sending a
            // reader to an empty list promises something the page will refuse.
            'permission' => 'missions.write',
        ];
    }
    if (!$hasEsxiCredential) {
        $notices[] = [
            'message' => __t('deploy.req_esxi'),
            'url' => 'credentials.php',
            'label' => __t('deploy.req_credentials_link'),
            'permission' => 'credentials.manage',
        ];
    }
    if (!$hasAnsibleCredential) {
        $notices[] = [
            'message' => __t('deploy.req_ansible'),
            'url' => 'credentials.php',
            'label' => __t('deploy.req_credentials_link'),
            'permission' => 'credentials.manage',
        ];
    }
    if (!$apiBaseUrlReady) {
        $notices[] = [
            'message' => $apiBaseUrlError !== '' ? $apiBaseUrlError : __t('settings.api_base_url_missing'),
            'url' => settings_url(VIRTUSPHERE_SETTINGS_TAB_DEPLOY),
            'label' => __t('deploy.req_api_base_url_link'),
            'permission' => 'system.config',
        ];
    }

    return $notices;
}
