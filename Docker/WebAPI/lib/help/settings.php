<?php
// Help panel partial, included by portal/help.php only when the viewer has
// system.config. Not directly reachable (nginx denies /lib/).
// One panel per settings.php tab, headed by the same __t('settings.tab_*')
// label the tab itself renders, so the grouping cannot drift from the page
// it explains. Sections inside a group are h3, mirroring the card order.
declare(strict_types=1);
?>
    <div class="stack" id="panel-settings" role="tabpanel" aria-labelledby="tab-settings" tabindex="0" data-tab-panel>
        <section class="panel">
            <h2><?php echo h(__t('help.settings_heading')); ?></h2>
            <p><?php echo h(__t('help.settings_p1')); ?></p>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('settings.tab_deploy')); ?></h2>
            <h3><?php echo h(__t('help.settings_api_heading')); ?></h3>
            <p><?php echo h(__t('help.settings_api_p1')); ?></p>
            <p><?php echo h(__t('help.settings_api_p2')); ?></p>
            <p><?php echo h(__t('help.settings_api_p3')); ?></p>
            <h3><?php echo h(__t('help.settings_runtime_heading')); ?></h3>
            <p><?php echo h(__t('help.settings_runtime_p1')); ?></p>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('settings.tab_machine_api')); ?></h2>
            <?php // The one sentence that makes the whole tab readable: two paths, opposite directions. ?>
            <p><?php echo h(__t('help.settings_machine_api_directions')); ?></p>
            <h3><?php echo h(__t('help.settings_allowlist_heading')); ?></h3>
            <p><?php echo h(__t('help.settings_allowlist_p1')); ?></p>
            <p><?php echo h(__t('help.settings_allowlist_p2')); ?></p>
            <h3><?php echo h(__t('help.settings_probe_heading')); ?></h3>
            <p><?php echo h(__t('help.settings_probe_p1', ['minutes' => intdiv(VIRTUSPHERE_MECM_PROBE_INTERVAL_SECONDS, 60)])); ?></p>
            <p><?php echo h(__t('help.settings_probe_p2', ['port' => VIRTUSPHERE_MECM_PROBE_PORT_DEFAULT])); ?></p>
            <p><?php echo h(__t('help.settings_probe_modes')); ?></p>
            <h3><?php echo h(__t('help.settings_token_heading')); ?></h3>
            <p><?php echo h(__t('help.settings_token_p1')); ?></p>
            <p><?php echo h(__t('help.settings_token_p2')); ?></p>
            <p><?php echo h(__t('help.settings_token_p3')); ?></p>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('settings.tab_catalog')); ?></h2>
            <h3><?php echo h(__t('help.settings_retire_heading')); ?></h3>
            <p><?php echo h(__t('help.settings_retire_p1', [
                'default' => VIRTUSPHERE_PACKAGE_RETIRE_THRESHOLD_DEFAULT,
                'min' => VIRTUSPHERE_PACKAGE_RETIRE_THRESHOLD_MIN,
                'max' => VIRTUSPHERE_PACKAGE_RETIRE_THRESHOLD_MAX,
            ])); ?></p>
            <p><?php echo h(__t('help.settings_retire_p2')); ?></p>
            <h3><?php echo h(__t('help.settings_esxi_heading')); ?></h3>
            <p><?php echo h(__t('help.settings_esxi_p1', ['max' => VIRTUSPHERE_ESXI_INVENTORY_INTERVAL_HOURS_MAX])); ?></p>
            <p><?php echo h(__t('help.settings_esxi_p2_interval_zero')); ?></p>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('settings.tab_https')); ?></h2>
            <h3><?php echo h(__t('help.settings_https_heading')); ?></h3>
            <p><?php echo h(__t('help.settings_https_p1')); ?></p>
            <p><?php echo h(__t('help.settings_https_p2', ['days' => VIRTUSPHERE_HTTPS_CERT_EXPIRY_WARN_DAYS])); ?></p>
            <p><?php echo h(__t('help.settings_https_p3', [
                'port' => envboot_optional('WEB_HTTPS_PORT', '8443'),
                'days' => intdiv(VIRTUSPHERE_HTTPS_HSTS_MAX_AGE_SECONDS, 86400),
            ])); ?></p>
            <p><?php echo h(__t('help.settings_https_p4')); ?></p>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('settings.tab_system')); ?></h2>
            <h3><?php echo h(__t('help.settings_time_heading')); ?></h3>
            <p><?php echo h(__t('help.settings_time_p1')); ?></p>
            <p><?php echo h(__t('help.settings_time_p2')); ?></p>
            <h3><?php echo h(__t('help.settings_session_heading')); ?></h3>
            <p><?php echo h(__t('help.settings_session_p1')); ?></p>
            <p><?php echo h(__t('help.settings_session_p2', ['warn' => intdiv(VIRTUSPHERE_SESSION_WARN_SECONDS, 60)])); ?></p>
            <p><?php echo h(__t('help.settings_session_p3', [
                'min' => VIRTUSPHERE_SESSION_LIFETIME_MINUTES_MIN,
                'max' => VIRTUSPHERE_SESSION_LIFETIME_MINUTES_MAX,
                'default' => VIRTUSPHERE_SESSION_LIFETIME_MINUTES_DEFAULT,
            ])); ?></p>
            <h3><?php echo h(__t('help.settings_password_heading')); ?></h3>
            <p><?php echo h(__t('help.settings_password_p1')); ?></p>
            <p><?php echo h(__t('help.settings_password_p2', [
                'min' => VIRTUSPHERE_PASSWORD_MIN_LENGTH_MIN,
                'max' => VIRTUSPHERE_PASSWORD_MIN_LENGTH_MAX,
                'default' => VIRTUSPHERE_PASSWORD_MIN_LENGTH_DEFAULT,
            ])); ?></p>
            <h3><?php echo h(__t('help.settings_retention_heading')); ?></h3>
            <p><?php echo h(__t('help.settings_retention_p1')); ?></p>
            <h3><?php echo h(__t('help.settings_backup_heading')); ?></h3>
            <p><?php echo h(__t('help.settings_backup_p1')); ?></p>
            <p><?php echo h(__t('help.settings_backup_p2')); ?></p>
            <p><?php echo h(__t('help.settings_backup_p3')); ?></p>
            <p><?php echo h(__t('help.settings_backup_p4')); ?></p>
        </section>
    </div>
