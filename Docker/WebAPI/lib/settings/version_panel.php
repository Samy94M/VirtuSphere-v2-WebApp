<?php

declare(strict_types=1);

/** @var array{
 *     portal: array{release: ?string, commit: ?string},
 *     database: array{state: string, last_applied: ?string, pending: int},
 *     runtime: array{php: string, mysql: string},
 *     ansible_hosts: list<array{id: int, name: string, state: string, reason: ?string, checked_at: ?string}>,
 *     mecm: array<string, array{state: string, reason: ?string, release: ?string, identifier: ?string, reported_at: ?string}>
 * } $versionOverview */

// The Version tab (plan docs/audits/2026-10-15-versioning-plan.md, 4.5). It only
// renders what lib/version_overview.php decided. No state is derived again here,
// so the rule and the picture cannot drift apart.

$versionBadge = static function (string $state): string {
    return match ($state) {
        VIRTUSPHERE_VERSION_STATE_OK => portal_badge('success', __t('settings.version_state_ok')),
        VIRTUSPHERE_VERSION_STATE_DEVIATES => portal_badge('warning', __t('settings.version_state_deviates')),
        default => portal_badge('neutral', __t('settings.version_state_unknown')),
    };
};

$versionPortalRelease = $versionOverview['portal']['release'];
$versionPortalValid = $versionPortalRelease !== null;
$versionPortalCommit = $versionOverview['portal']['commit'];
$versionDatabase = $versionOverview['database'];
$versionRuntime = $versionOverview['runtime'];

?>
    <div class="stack" id="panel-version" role="tabpanel" aria-labelledby="tab-version" tabindex="0" data-tab-panel hidden>
        <section class="panel">
            <h2><?php echo h(__t('settings.version_title')); ?></h2>
            <p class="muted"><?php echo h(__t('settings.version_hint')); ?></p>
            <div class="table-wrap" tabindex="0">
                <table>
                    <thead><tr><th><?php echo h(__t('settings.version_th_component')); ?></th><th><?php echo h(__t('settings.version_th_value')); ?></th><th><?php echo h(__t('settings.version_th_state')); ?></th></tr></thead>
                    <tbody>
                        <tr>
                            <th scope="row"><?php echo h(__t('settings.version_row_portal')); ?></th>
                            <td>
                                <?php echo h($versionPortalValid ? $versionPortalRelease : __t('settings.version_portal_invalid')); ?>
                                <?php if ($versionPortalCommit !== null) { ?>
                                    <div class="muted"><?php echo h(__t('settings.version_portal_commit', ['commit' => substr($versionPortalCommit, 0, 7)])); ?></div>
                                <?php } ?>
                            </td>
                            <td><?php echo $versionPortalValid
                                ? h(__t('settings.version_reference'))
                                : $versionBadge(VIRTUSPHERE_VERSION_STATE_UNKNOWN); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo h(__t('settings.version_row_database')); ?></th>
                            <td>
                                <?php echo h($versionDatabase['last_applied'] !== null
                                    ? __t('settings.version_database_value', ['name' => $versionDatabase['last_applied']])
                                    : __t('settings.version_database_none')); ?>
                                <?php if ($versionDatabase['pending'] > 0) { ?>
                                    <div class="muted"><?php echo h(__t('settings.version_database_pending', ['count' => $versionDatabase['pending']])); ?></div>
                                    <div class="muted"><?php echo h(__t('settings.version_database_action')); ?></div>
                                <?php } ?>
                            </td>
                            <td><?php echo $versionBadge($versionDatabase['state']); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo h(__t('settings.version_row_runtime')); ?></th>
                            <td><?php echo h(__t('settings.version_runtime_value', ['php' => $versionRuntime['php'], 'mysql' => $versionRuntime['mysql']])); ?></td>
                            <td></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo h(__t('settings.version_row_playbooks')); ?></th>
                            <td>
                                <?php echo h($versionPortalValid
                                    ? __t('settings.version_playbooks_value', ['release' => $versionPortalRelease])
                                    : __t('settings.version_playbooks_value_plain')); ?>
                                <div class="muted"><?php echo h(__t('settings.version_playbooks_note')); ?></div>
                            </td>
                            <td><?php echo $versionBadge($versionPortalValid ? VIRTUSPHERE_VERSION_STATE_OK : VIRTUSPHERE_VERSION_STATE_UNKNOWN); ?></td>
                        </tr>
                        <?php foreach ($versionOverview['ansible_hosts'] as $versionHost) { ?>
                            <tr>
                                <th scope="row"><?php echo h(__t('settings.version_row_ansible_host', ['name' => $versionHost['name']])); ?></th>
                                <td>
                                    <?php echo h($versionHost['checked_at'] !== null
                                        ? __t('settings.version_ansible_checked', ['time' => portal_format_timestamp($versionHost['checked_at'])])
                                        : __t('settings.version_ansible_never')); ?>
                                    <?php if ($versionHost['reason'] === 'runtime_versions') { ?>
                                        <div class="muted"><?php echo h(__t('settings.version_ansible_action_deviates')); ?> <a href="<?php echo h(system_status_url(VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_ANSIBLE)); ?>"><?php echo h(__t('ansible_test.status_link')); ?></a></div>
                                    <?php } elseif (in_array($versionHost['reason'], ['not_tested', 'stale_evidence', 'chain_failed'], true)) { ?>
                                        <div class="muted"><?php echo h(__t('settings.version_ansible_action_unknown', ['button' => __t('credentials.btn_test_ansible')])); ?></div>
                                    <?php } ?>
                                </td>
                                <td><?php echo $versionBadge($versionHost['state']); ?></td>
                            </tr>
                        <?php } ?>
                        <?php foreach ($versionOverview['mecm'] as $versionSource => $versionReport) { ?>
                            <tr>
                                <th scope="row"><?php echo h(integration_source_label((string) $versionSource)); ?></th>
                                <td>
                                    <?php if ($versionReport['release'] !== null) { ?>
                                        <?php echo h($versionReport['release']); ?>
                                    <?php } elseif ($versionReport['identifier'] !== null) { ?>
                                        <code><?php echo h($versionReport['identifier']); ?></code>
                                    <?php } else { ?>
                                        <?php echo h(__t('settings.version_mecm_no_report')); ?>
                                    <?php } ?>
                                    <?php if ($versionReport['reported_at'] !== null) { ?>
                                        <div class="muted"><?php echo h(__t('settings.version_mecm_reported', ['time' => portal_format_timestamp($versionReport['reported_at'])])); ?></div>
                                    <?php } ?>
                                    <?php if ($versionReport['reason'] === 'release') { ?>
                                        <div class="muted"><?php echo h(__t('settings.version_mecm_action_release')); ?></div>
                                    <?php } elseif ($versionReport['reason'] === 'files') { ?>
                                        <div class="muted"><?php echo h(__t('settings.version_mecm_action_files')); ?></div>
                                    <?php } elseif ($versionReport['reason'] === 'no_release') { ?>
                                        <div class="muted"><?php echo h(__t('settings.version_mecm_action_no_release')); ?></div>
                                    <?php } ?>
                                </td>
                                <td><?php echo $versionBadge($versionReport['state']); ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
