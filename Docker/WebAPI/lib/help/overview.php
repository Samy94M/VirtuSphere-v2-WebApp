<?php
// Help panel partial, included by portal/help.php in the signed-in page scope.
// Not directly reachable: nginx denies /lib/ (Docker/nginx/default.conf).
declare(strict_types=1);
?>
    <div class="stack" id="panel-overview" role="tabpanel" aria-labelledby="tab-overview" tabindex="0" data-tab-panel>
        <section class="panel" id="help-intro">
            <h2><?php echo h(__t('help_overview.intro_heading')); ?></h2>
            <p><?php echo h(__t('help_overview.intro_p1')); ?></p>
            <p><?php echo h(__t('help_overview.intro_p2')); ?></p>
        </section>

        <section class="panel" id="help-workflow">
            <h2><?php echo h(__t('help_overview.workflow_heading')); ?></h2>
            <p><?php echo h(__t('help_overview.workflow_step1')); ?> <a href="missions.php"><?php echo h(__t('help_overview.link_missions')); ?></a></p>
            <p><?php echo h(__t('help_overview.workflow_step2')); ?> <a href="credentials.php"><?php echo h(__t('help_overview.link_credentials')); ?></a></p>
            <p><?php echo h(__t('help_overview.workflow_step3')); ?> <a href="deploy.php"><?php echo h(__t('help_overview.link_deploy')); ?></a></p>
            <p><?php echo h(__t('help_overview.workflow_step4')); ?> <a href="deploy.php"><?php echo h(__t('help_overview.link_deploy')); ?></a></p>
        </section>

        <section class="panel" id="help-dashboard-counts">
            <h2><?php echo h(__t('help_overview.dashboard_counts_heading')); ?></h2>
            <p><?php echo h(__t('help_overview.dashboard_counts_p1')); ?></p>
            <p><?php echo h(__t('help_overview.dashboard_counts_p1_b')); ?></p>
            <p><?php echo h(__t('help_overview.dashboard_groups_p1', [
                'mecm_hours' => intdiv(VIRTUSPHERE_VM_MECM_PENDING_WARN_SECONDS, 3600),
                'install_hours' => intdiv(VIRTUSPHERE_VM_OS_INSTALL_WARN_SECONDS, 3600),
            ])); ?></p>
        </section>

        <section class="panel" id="help-unsaved-changes">
            <h2><?php echo h(__t('help_overview.unsaved_heading')); ?></h2>
            <p><?php echo h(__t('help_overview.unsaved_p1')); ?></p>
            <p><?php echo h(__t('help_overview.unsaved_p2')); ?></p>
            <p><?php echo h(__t('help_overview.unsaved_p3')); ?></p>
        </section>

        <section class="panel" id="help-copying-values">
            <h2><?php echo h(__t('help_overview.copy_heading')); ?></h2>
            <p><?php echo h(__t('help_overview.copy_p1')); ?></p>
            <p><?php echo h(__t('help_overview.copy_p2')); ?></p>
        </section>

        <?php // PR-R6: the operator words of the portal glossary (R15). The internal
              // terms that stood here moved to the architecture help. ?>
        <section class="panel" id="help-glossary">
            <h2><?php echo h(__t('help_overview.glossary_heading')); ?></h2>
            <p><?php echo h(__t('help_overview.glossary_intro')); ?></p>
            <ul>
                <li><?php echo h(__t('help_overview.glossary_deploy')); ?></li>
                <li><?php echo h(__t('help_overview.glossary_job')); ?></li>
                <li><?php echo h(__t('help_overview.glossary_stagger')); ?></li>
                <li><?php echo h(__t('help_overview.glossary_services')); ?></li>
                <li><?php echo h(__t('help_overview.glossary_heartbeat')); ?></li>
                <li><?php echo h(__t('help_overview.glossary_reaper')); ?></li>
                <li><?php echo h(__t('help_overview.glossary_sweep')); ?></li>
                <li><?php echo h(__t('help_overview.glossary_cleanup')); ?></li>
                <li><?php echo h(__t('help_overview.glossary_credential')); ?></li>
                <li><?php echo h(__t('help_overview.glossary_full_test')); ?></li>
                <li><?php echo h(__t('help_overview.glossary_inventory')); ?></li>
                <li><?php echo h(__t('help_overview.glossary_instance_uuid')); ?></li>
                <li><?php echo h(__t('help_overview.glossary_port_group')); ?></li>
                <li><?php echo h(__t('help_overview.glossary_gast_ip_dhcp')); ?></li>
                <li><?php echo h(__t('help_overview.glossary_rollout_name')); ?></li>
                <li><?php echo h(__t('help_overview.glossary_resource_id')); ?></li>
                <li><?php echo h(__t('help_overview.glossary_task_sequence')); ?></li>
                <li><?php echo h(__t('help_overview.glossary_correlation_id')); ?></li>
            </ul>
        </section>
    </div>
