<?php
// Help panel partial, included by portal/help.php. Not directly reachable
// (nginx denies /lib/).
declare(strict_types=1);
?>
    <div class="stack" id="panel-deploy" role="tabpanel" aria-labelledby="tab-deploy" tabindex="0" data-tab-panel>
        <section class="panel">
            <h2><?php echo h(__t('help_deploy.deploy_heading')); ?></h2>
            <p><?php echo h(__t('help_deploy.deploy_requirements_p1')); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_requirements_p2')); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_p1')); ?></p>
            <ul>
                <li><?php echo h(__t('help_deploy.deploy_mode_full')); ?></li>
                <li><?php echo h(__t('help_deploy.deploy_mode_create')); ?></li>
                <li><?php echo h(__t('help_deploy.deploy_mode_powercycle')); ?></li>
                <li><?php echo h(__t('help_deploy.deploy_mode_export')); ?></li>
                <li><?php echo h(__t('help_deploy.deploy_mode_start')); ?></li>
                <li><?php echo h(__t('help_deploy.deploy_mode_autostart')); ?></li>
            </ul>
            <h3><?php echo h(__t('help_deploy.deploy_verbose_heading')); ?></h3>
            <p><?php echo h(__t('help_deploy.deploy_verbose_p1')); ?></p>
            <h3><?php echo h(__t('help_deploy.deploy_powercycle_wait_heading')); ?></h3>
            <p><?php echo h(__t('help_deploy.deploy_powercycle_wait_p1')); ?></p>
            <h3><?php echo h(__t('help_deploy.deploy_start_wait_heading')); ?></h3>
            <p><?php echo h(__t('help_deploy.deploy_start_wait_p1', [
                'default' => VIRTUSPHERE_START_WAIT_SECONDS_DEFAULT,
            ])); ?></p>
            <?php // All three numbers come from the constants the deploy actually
                  // runs on, including the SSH idle budget the upper bound derives
                  // from: the explanation of WHY there is a ceiling is only true as
                  // long as it names the ceiling that exists. ?>
            <p><?php echo h(__t('help_deploy.deploy_start_wait_p2', [
                'max' => VIRTUSPHERE_START_WAIT_SECONDS_MAX,
                'idle' => VIRTUSPHERE_SSH_IDLE_TIMEOUT_SECONDS,
            ])); ?></p>
            <h3><?php echo h(__t('help_deploy.deploy_identity_heading')); ?></h3>
            <p><?php echo h(__t('help_deploy.deploy_identity_p1')); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_identity_p2')); ?></p>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('help_deploy.deploy_schedule_heading')); ?></h2>
            <p><?php echo h(__t('help_deploy.deploy_schedule_p1')); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_schedule_p2')); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_schedule_p3')); ?></p>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('help_deploy.deploy_cancel_heading')); ?></h2>
            <p><?php echo h(__t('help_deploy.deploy_cancel_p1')); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_cancel_p2')); ?></p>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('help_deploy.deploy_storage_heading')); ?></h2>
            <p><?php echo h(__t('help_deploy.deploy_storage_p1', [
                // The default disk size with its unit and the default
                // provisioning type, from the constants the creator actually
                // applies, so the example and the default cannot drift apart.
                'size' => VIRTUSPHERE_VM_DEFAULTS['disk_size_gb'] . ' GB',
                'type' => VIRTUSPHERE_VM_DEFAULTS['disk_type'],
            ])); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_storage_p2')); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_storage_p3')); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_storage_p4')); ?></p>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('help_deploy.deploy_warn_heading')); ?></h2>
            <p><?php echo h(__t('help_deploy.deploy_warn_p1')); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_warn_p2')); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_warn_p3')); ?></p>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('help_deploy.deploy_retry_heading')); ?></h2>
            <p><?php echo h(__t('help_deploy.deploy_retry_p1')); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_retry_p2')); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_retry_p3')); ?></p>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('help_deploy.autostart_heading')); ?></h2>
            <p><?php echo h(__t('help_deploy.autostart_p1')); ?></p>
            <h3><?php echo h(__t('help_deploy.autostart_inherit_heading')); ?></h3>
            <p><?php echo h(__t('help_deploy.autostart_inherit_p1', ['seconds' => VIRTUSPHERE_AUTOSTART_DELAY_DEFAULT])); ?></p>
            <p><?php echo h(__t('help_deploy.autostart_inherit_p2')); ?></p>
            <h3><?php echo h(__t('help_deploy.autostart_heartbeat_heading')); ?></h3>
            <p><?php echo h(__t('help_deploy.autostart_heartbeat_p1')); ?></p>
            <p><?php echo h(__t('help_deploy.autostart_heartbeat_p2')); ?></p>
            <h3><?php echo h(__t('help_deploy.autostart_limits_heading')); ?></h3>
            <ul>
                <li><?php echo h(__t('help_deploy.autostart_limit_license')); ?></li>
                <li><?php echo h(__t('help_deploy.autostart_limit_ha')); ?></li>
                <li><?php echo h(__t('help_deploy.autostart_limit_tools')); ?></li>
                <li><?php echo h(__t('help_deploy.autostart_limit_order')); ?></li>
                <li><?php echo h(__t('help_deploy.autostart_limit_shared_host')); ?></li>
            </ul>
            <h3><?php echo h(__t('help_deploy.autostart_run_heading')); ?></h3>
            <p><?php echo h(__t('help_deploy.autostart_run_p1b')); ?></p>
            <p><?php echo h(__t('help_deploy.autostart_run_p1')); ?></p>
            <p><?php echo h(__t('help_deploy.autostart_run_p2')); ?></p>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('help_deploy.credentials_heading')); ?></h2>
            <p><?php echo h(__t('help_deploy.credentials_p1')); ?></p>
        </section>
    </div>
