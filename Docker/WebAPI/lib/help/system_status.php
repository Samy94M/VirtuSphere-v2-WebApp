<?php
// Help panel partial, included by portal/help.php. Not directly reachable
// (nginx denies /lib/).
declare(strict_types=1);
?>
    <div class="stack" id="panel-system-status" role="tabpanel" aria-labelledby="tab-system-status" tabindex="0" data-tab-panel>
        <section class="panel">
            <h2><?php echo h(__t('help_system_status.system_status_roles_heading')); ?></h2>
            <p><?php echo h(__t('help_system_status.system_status_roles_p1')); ?></p>
            <p><?php echo h(__t('help_system_status.system_status_single_site')); ?></p>
            <h3><?php echo h(__t('help_system_status.system_status_signals_heading')); ?></h3>
            <ul>
                <li><?php echo h(__t('help_system_status.system_status_signal_heartbeat')); ?></li>
                <li><?php echo h(__t('help_system_status.system_status_signal_combination')); ?></li>
            </ul>
        </section>
        <section class="panel">
            <h2><?php echo h(__t('help_system_status.firstaid_heading')); ?></h2>
            <p><?php echo h(__t('help_system_status.firstaid_p1')); ?></p>
            <ol>
                <li><?php echo h(__t('help_system_status.firstaid_step1')); ?></li>
                <li><?php echo h(__t('help_system_status.firstaid_step2')); ?></li>
                <li><?php echo h(__t('help_system_status.firstaid_step3')); ?></li>
                <li><?php echo h(__t('help_system_status.firstaid_step4')); ?></li>
            </ol>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('help_system_status.system_status_heading')); ?></h2>
            <p><?php echo h(__t('help_system_status.system_status_p1')); ?></p>
            <p><?php echo h(__t('help_system_status.system_status_p2')); ?></p>
            <p><?php echo h(__t('help_system_status.system_status_p3')); ?></p>
            <h3><?php echo h(__t('help_system_status.system_status_sources_heading')); ?></h3>
            <ul>
                <li><?php echo h(__t('help_system_status.system_status_source_1')); ?></li>
                <li><?php echo h(__t('help_system_status.system_status_source_2')); ?></li>
                <li><?php echo h(__t('help_system_status.system_status_source_3')); ?></li>
                <li><?php echo h(__t('help_system_status.system_status_source_4')); ?></li>
                <li><?php echo h(__t('help_system_status.system_status_source_5')); ?></li>
            </ul>
            <h3><?php echo h(__t('help_system_status.system_status_status_heading')); ?></h3>
            <p><?php echo h(__t('help_system_status.system_status_status_p1')); ?></p>
            <?php // Same renderer as the page's own legend, so the two cannot list different states again. ?>
            <?php system_status_legend_items('heartbeat'); ?>
            <?php // What a dash in a row means. The fields are rendered unconditionally
                  // (lib/system_status_shared_panels.php) so the same field stays in the
                  // same column; that only reads correctly if the placeholder is
                  // explained once, here, instead of guessed at per card. ?>
            <p><?php echo h(__t('help_system_status.system_status_status_p2')); ?></p>
            <?php // Directly after the field explanation, because the cause line sits
                  // in the same card and is the only place a counter names a VM. ?>
            <p><?php echo h(__t('help_system_status.system_status_status_p3')); ?></p>
            <h3><?php echo h(__t('help_system_status.system_status_work_heading')); ?></h3>
            <p><?php echo h(__t('help_system_status.system_status_work_0')); ?></p>
            <ol>
                <li><?php echo h(__t('help_system_status.system_status_work_1')); ?></li>
                <li><?php echo h(__t('help_system_status.system_status_work_2')); ?></li>
                <li><?php echo h(__t('help_system_status.system_status_work_3')); ?></li>
                <li><?php echo h(__t('help_system_status.system_status_work_4')); ?></li>
            </ol>
            <h3><?php echo h(__t('help_system_status.system_status_fix_heading')); ?></h3>
            <ul>
                <li><?php echo h(__t('help_system_status.system_status_fix_1')); ?></li>
                <li><?php echo h(__t('help_system_status.system_status_fix_2')); ?></li>
                <li><?php echo h(__t('help_system_status.system_status_fix_3')); ?></li>
                <li><?php echo h(__t('help_system_status.system_status_fix_4')); ?></li>
            </ul>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('help_system_status.mecmfolders_heading')); ?></h2>
            <p><?php echo h(__t('help_system_status.mecmfolders_p1')); ?></p>
            <ul>
                <li><?php echo h(__t('help_system_status.mecmfolders_item1')); ?></li>
                <li><?php echo h(__t('help_system_status.mecmfolders_item2')); ?></li>
                <li><?php echo h(__t('help_system_status.mecmfolders_item3')); ?></li>
            </ul>
            <p><?php echo h(__t('help_system_status.mecmfolders_p2')); ?></p>
            <p><?php echo h(__t('help_system_status.mecmfolders_p3')); ?></p>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('help_system_status.clientphases_heading')); ?></h2>
            <p><?php echo h(__t('help_system_status.clientphases_p1')); ?></p>
            <ul>
                <li><?php echo h(__t('help_system_status.clientphases_step1')); ?></li>
                <li><?php echo h(__t('help_system_status.clientphases_step2')); ?></li>
                <li><?php echo h(__t('help_system_status.clientphases_step3')); ?></li>
                <li><?php echo h(__t('help_system_status.clientphases_step4')); ?></li>
            </ul>
            <p><?php echo h(__t('help_system_status.clientphases_p2', ['minutes' => intdiv(VIRTUSPHERE_CLIENT_PHASE_UNCONFIRMED_AFTER_SECONDS, 60)])); ?></p>
            <p><?php echo h(__t('help_system_status.clientphases_p3')); ?></p>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('help_system_status.logs_heading')); ?></h2>
            <p><?php echo h(__t('help_system_status.logs_p1')); ?></p>
            <p><?php echo h(__t('help_system_status.logs_p2')); ?></p>
            <p><?php echo h(__t('help_system_status.logs_p2b')); ?></p>
            <?php // Interpolates the constants the prune uses, so text and behaviour cannot drift. ?>
            <p><?php echo h(__t('help_system_status.logs_p3', [
                'security_days' => VIRTUSPHERE_LOG_RETENTION_SECURITY_DAYS,
                'other_days' => VIRTUSPHERE_LOG_RETENTION_DAYS,
                'attempt_days' => VIRTUSPHERE_LOGIN_ATTEMPT_RETENTION_DAYS,
                'client_days' => VIRTUSPHERE_CLIENT_EVENT_RETENTION_DAYS,
                'package_days' => VIRTUSPHERE_PACKAGE_PURGE_AFTER_DAYS,
            ])); ?></p>
            <p><?php echo h(__t('help_system_status.logs_p4', [
                'log_days' => VIRTUSPHERE_DEPLOY_JOB_LOG_RETENTION_DAYS,
                'system_days' => VIRTUSPHERE_SYSTEM_JOB_RETENTION_DAYS,
            ])); ?></p>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('help_system_status.esxi_inv_heading')); ?></h2>
            <p><?php echo h(__t('help_system_status.esxi_inv_p1', ['hours' => VIRTUSPHERE_ESXI_INVENTORY_INTERVAL_HOURS_DEFAULT])); ?></p>
            <p><?php echo h(__t('help_system_status.esxi_inv_p2')); ?></p>
            <?php // The cadence line each inventory card carries. The card names the
                  // blocker that actually stops the pull (esxi_inventory_automation_blocker());
                  // this says why the named one is the one to fix first. ?>
            <p><?php echo h(__t('help_system_status.esxi_inv_cadence')); ?></p>
            <h3><?php echo h(__t('help_system_status.esxi_inv_rights_heading')); ?></h3>
            <ul>
                <li><?php echo h(__t('help_system_status.esxi_inv_rights_read')); ?></li>
                <li><?php echo h(__t('help_system_status.esxi_inv_rights_deploy')); ?></li>
            </ul>
            <p><?php echo h(__t('help_system_status.esxi_inv_p3')); ?></p>
            <p><?php echo h(__t('help_system_status.esxi_inv_deviation')); ?></p>
            <p><?php echo h(__t('help_system_status.esxi_inv_refresh_pause')); ?></p>
            <h3><?php echo h(__t('help_system_status.esxi_inv_ampel_heading')); ?></h3>
            <p><?php
                // The legend interpolates the same constants the code uses, so
                // text and behaviour cannot drift (ADR-0016 idea).
                echo h(__t('help_system_status.esxi_inv_ampel_p1', [
                    'streak' => VIRTUSPHERE_ESXI_INVENTORY_FAILURE_STREAK_DANGER,
                    'factor' => VIRTUSPHERE_ESXI_INVENTORY_STALE_FACTOR,
                ]));
            ?></p>
            <?php system_status_legend_items('esxi'); ?>
            <h3><?php echo h(__t('help_system_status.esxi_test_heading')); ?></h3>
            <p><?php echo h(__t('help_system_status.esxi_test_p1')); ?></p>
            <p><?php echo h(__t('help_system_status.esxi_test_p2')); ?></p>

            <h3><?php echo h(__t('help_system_status.esxi_test_ansible_heading')); ?></h3>
            <p><?php echo h(__t('help_system_status.esxi_test_ansible_p1')); ?></p>
            <p><?php echo h(__t('help_system_status.esxi_test_ansible_p2')); ?></p>
            <p><?php echo h(__t('help_system_status.esxi_test_ansible_p3')); ?></p>
            <h4><?php echo h(__t('system_status.ansible_legend_heading')); ?></h4>
            <?php system_status_legend_items('ansible'); ?>

            <h3><?php echo h(__t('help_system_status.esxi_cap_heading')); ?></h3>
            <p><?php echo h(__t('help_system_status.esxi_cap_p1')); ?></p>
            <ul>
                <li><?php echo portal_badge('warning', __t('system_status.cap_license_free')); ?> <?php echo h(__t('system_status.cap_legend_license_free')); ?></li>
                <li><?php echo portal_badge('warning', __t('system_status.cap_in_ha_cluster')); ?> <?php echo h(__t('system_status.cap_legend_in_ha_cluster')); ?></li>
                <li><?php echo portal_badge('info', __t('system_status.cap_in_maintenance')); ?> <?php echo h(__t('system_status.cap_legend_in_maintenance')); ?></li>
            </ul>
            <p><?php echo h(__t('help_system_status.esxi_cap_unknown')); ?></p>
            <p><?php echo h(__t('help_system_status.esxi_inv_host_facts', ['minutes' => intdiv(VIRTUSPHERE_ESXI_CLOCK_SKEW_WARN_SECONDS, 60)])); ?></p>

            <h3><?php echo h(__t('help_system_status.esxi_cause_heading')); ?></h3>
            <p><?php echo h(__t('help_system_status.esxi_cause_p1')); ?></p>
            <div class="table-wrap"><table>
                <thead><tr><th><?php echo h(__t('help_system_status.esxi_cause_th_cause')); ?></th><th><?php echo h(__t('help_system_status.esxi_cause_th_meaning')); ?></th><th><?php echo h(__t('help_system_status.esxi_cause_th_fix')); ?></th></tr></thead>
                <tbody>
                <?php foreach (VIRTUSPHERE_INVENTORY_ERROR_CATEGORIES as $causeCode): ?>
                    <tr>
                        <td><code><?php echo h($causeCode); ?></code></td>
                        <td><?php echo h(connection_error_message($causeCode, ['host' => __t('help_system_status.esxi_cause_host_placeholder')])); ?></td>
                        <td><?php echo h(__t('help_system_status.esxi_cause_fix_' . $causeCode)); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>

            <?php // Sits after the failure table on purpose: this is the other case, a pull that succeeded and still left a category empty. ?>
            <h3><?php echo h(__t('help_system_status.esxi_inv_zero_heading')); ?></h3>
            <p><?php echo h(__t('help_system_status.esxi_inv_zero_p1')); ?></p>
            <p><?php echo h(__t('help_system_status.esxi_inv_zero_p2')); ?></p>
            <p><?php echo h(__t('help_system_status.esxi_inv_zero_p3')); ?></p>
            <p><?php echo h(__t('help_system_status.esxi_inv_zero_p4')); ?></p>
            <p><?php echo h(__t('help_system_status.esxi_inv_zero_p5')); ?></p>

            <h3><?php echo h(__t('help_system_status.esxi_inv_catalog_heading')); ?></h3>
            <p><?php echo h(__t('help_system_status.esxi_inv_catalog_presence')); ?></p>
            <p><?php echo h(__t('help_system_status.esxi_inv_catalog_vlanid')); ?></p>
            <p><?php echo h(__t('help_system_status.esxi_inv_templates')); ?></p>
        </section>
    </div>
