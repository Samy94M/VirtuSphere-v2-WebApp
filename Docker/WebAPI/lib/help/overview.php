<?php
// Help panel partial, included by portal/help.php in the signed-in page scope.
// Not directly reachable: nginx denies /lib/ (Docker/nginx/default.conf).
declare(strict_types=1);
?>
    <div class="stack" id="panel-overview" role="tabpanel" aria-labelledby="tab-overview" tabindex="0" data-tab-panel>
        <section class="panel">
            <h2><?php echo h(__t('help_overview.intro_heading')); ?></h2>
            <p><?php echo h(__t('help_overview.intro_p1')); ?></p>
            <p><?php echo h(__t('help_overview.intro_p2')); ?></p>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('help_overview.workflow_heading')); ?></h2>
            <p><?php echo h(__t('help_overview.workflow_step1')); ?> <a href="missions.php"><?php echo h(__t('help_overview.link_missions')); ?></a></p>
            <p><?php echo h(__t('help_overview.workflow_step2')); ?> <a href="credentials.php"><?php echo h(__t('help_overview.link_credentials')); ?></a></p>
            <p><?php echo h(__t('help_overview.workflow_step3')); ?> <a href="deploy.php"><?php echo h(__t('help_overview.link_deploy')); ?></a></p>
            <p><?php echo h(__t('help_overview.workflow_step4')); ?> <a href="deploy.php"><?php echo h(__t('help_overview.link_deploy')); ?></a></p>
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

        <section class="panel">
            <h2><?php echo h(__t('help_overview.glossary_heading')); ?></h2>
            <p><?php echo h(__t('help_overview.glossary_intro')); ?></p>
            <ul>
                <li><?php echo h(__t('help_overview.glossary_instance_uuid')); ?></li>
                <li><?php echo h(__t('help_overview.glossary_moid')); ?></li>
                <li><?php echo h(__t('help_overview.glossary_resource_id')); ?></li>
                <li><?php echo h(__t('help_overview.glossary_task_sequence')); ?></li>
                <li><?php echo h(__t('help_overview.glossary_async_job')); ?></li>
                <li><?php echo h(__t('help_overview.glossary_start_intent')); ?></li>
                <li><?php echo h(__t('help_overview.glossary_client_ready_ack')); ?></li>
                <li><?php echo h(__t('help_overview.glossary_best_effort')); ?></li>
                <li><?php echo h(__t('help_overview.glossary_name_semantics')); ?></li>
                <li><?php echo h(__t('help_overview.glossary_gast_ip_dhcp')); ?></li>
                <li><?php echo h(__t('help_overview.glossary_reaper')); ?></li>
                <li><?php echo h(__t('help_overview.glossary_sweep')); ?></li>
                <li><?php echo h(__t('help_overview.glossary_cleanup')); ?></li>
            </ul>
        </section>
    </div>
