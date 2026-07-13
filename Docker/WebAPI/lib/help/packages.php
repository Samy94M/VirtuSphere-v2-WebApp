<?php
// Help panel partial, included by portal/help.php. Not directly reachable
// (nginx denies /lib/).
declare(strict_types=1);
?>
    <div class="stack" id="panel-packages" role="tabpanel" aria-labelledby="tab-packages" tabindex="0" data-tab-panel>
        <section class="panel">
            <h2><?php echo h(__t('help.packages_heading')); ?></h2>
            <p><?php echo h(__t('help.packages_p1')); ?></p>
            <p><?php echo h(__t('help.packages_p1b')); ?></p>
            <?php // Interpolates the constant the purge uses, so text and behaviour cannot drift. ?>
            <p><?php echo h(__t('help.packages_p2', ['days' => VIRTUSPHERE_PACKAGE_PURGE_AFTER_DAYS])); ?></p>
            <p><?php echo h(__t('help.packages_p3')); ?></p>
        </section>
        <section class="panel">
            <h2><?php echo h(__t('help.packages_os_heading')); ?></h2>
            <p><?php echo h(__t('help.packages_os_p1')); ?></p>
            <p><?php echo h(__t('help.packages_os_p2', ['days' => VIRTUSPHERE_PACKAGE_PURGE_AFTER_DAYS])); ?></p>
        </section>
    </div>
