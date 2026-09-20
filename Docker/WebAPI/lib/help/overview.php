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
            <p><?php echo h(__t('help_overview.workflow_step1')); ?></p>
            <p><?php echo h(__t('help_overview.workflow_step2')); ?></p>
            <p><?php echo h(__t('help_overview.workflow_step3')); ?></p>
            <p><?php echo h(__t('help_overview.workflow_step4')); ?></p>
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
            <p><?php echo h(__t('help_overview.copy_p3')); ?></p>
        </section>
    </div>
