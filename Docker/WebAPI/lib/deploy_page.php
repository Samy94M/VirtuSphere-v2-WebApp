<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/esxi_datacenter_presenter.php';
require_once __DIR__ . '/settings_page.php';
require_once __DIR__ . '/repo/missions.php';
require_once __DIR__ . '/repo/esxi_inventory.php';

const VIRTUSPHERE_DEPLOY_BLOCKER_PREREQUISITE = 'prerequisite';
const VIRTUSPHERE_DEPLOY_BLOCKER_EMPTY_MISSION = 'empty_mission';
const VIRTUSPHERE_DEPLOY_BLOCKER_IDENTITY_CONFLICT = 'identity_conflict';
const VIRTUSPHERE_DEPLOY_BLOCKER_VM_NETWORK_MAPPING = 'vm_network_mapping';

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

    $resolution = repo_esxi_datacenter_resolution($db, $esxiCredentialId, virtusphere_request_now());
    if ((string) $resolution['resolution'] === 'resolved') {
        return;
    }
    $presentation = esxi_datacenter_compact_presentation($resolution);

    throw new ValidationException(
        ['credential_esxi_id' => __t('deploy.err_datacenter_unresolved')],
        $presentation['code'] . ': ' . $presentation['message']
    );
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
 * @return list<array{kind:string,code:string,message:string,action:array{type:string,url:string,label:string,permission:string}}>
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
            'kind' => VIRTUSPHERE_DEPLOY_BLOCKER_PREREQUISITE,
            'code' => 'missions',
            'message' => __t('deploy.req_missions'),
            // The list the deploy form draws from, not the template view.
            'action' => [
                'type' => 'link',
                'url' => 'missions.php?type=missions',
                'label' => __t('deploy.req_missions_link'),
                // The page is open to everyone, but the fix on it is not: sending a
                // reader to an empty list promises something the page will refuse.
                'permission' => 'missions.write',
            ],
        ];
    }
    if (!$hasEsxiCredential) {
        $notices[] = [
            'kind' => VIRTUSPHERE_DEPLOY_BLOCKER_PREREQUISITE,
            'code' => 'esxi_credential',
            'message' => __t('deploy.req_esxi'),
            'action' => [
                'type' => 'link',
                'url' => 'credentials.php',
                'label' => __t('deploy.req_credentials_link'),
                'permission' => 'credentials.manage',
            ],
        ];
    }
    if (!$hasAnsibleCredential) {
        $notices[] = [
            'kind' => VIRTUSPHERE_DEPLOY_BLOCKER_PREREQUISITE,
            'code' => 'ansible_credential',
            'message' => __t('deploy.req_ansible'),
            'action' => [
                'type' => 'link',
                'url' => 'credentials.php',
                'label' => __t('deploy.req_credentials_link'),
                'permission' => 'credentials.manage',
            ],
        ];
    }
    if (!$apiBaseUrlReady) {
        $notices[] = [
            'kind' => VIRTUSPHERE_DEPLOY_BLOCKER_PREREQUISITE,
            'code' => 'api_base_url',
            'message' => $apiBaseUrlError !== '' ? $apiBaseUrlError : __t('settings.api_base_url_missing'),
            'action' => [
                'type' => 'link',
                'url' => settings_url(VIRTUSPHERE_SETTINGS_TAB_DEPLOY),
                'label' => __t('deploy.req_api_base_url_link'),
                'permission' => 'system.config',
            ],
        ];
    }

    return $notices;
}
