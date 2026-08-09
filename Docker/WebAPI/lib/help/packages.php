<?php
// Help panel partial, included by portal/help.php. Not directly reachable
// (nginx denies /lib/).
declare(strict_types=1);
?>
    <div class="stack" id="panel-packages" role="tabpanel" aria-labelledby="tab-packages" tabindex="0" data-tab-panel>
        <section class="panel">
            <h2><?php echo h(__t('help_packages.packages_heading')); ?></h2>
            <p><?php echo h(__t('help_packages.packages_p1')); ?></p>
            <p><?php echo h(__t('help_packages.packages_p1b')); ?></p>
            <?php // Interpolates the constant the purge uses, so text and behaviour cannot drift. ?>
            <?php // Split in two: what happens to an ASSIGNMENT (a decision an
                  // operator has to understand) and what happens to the retired
                  // ROW after the cleanup window. The purge days belong to the
                  // second sentence only. ?>
            <p><?php echo h(__t('help_packages.packages_p2')); ?></p>
            <p><?php echo h(__t('help_packages.packages_p2b', ['days' => VIRTUSPHERE_PACKAGE_PURGE_AFTER_DAYS])); ?></p>
            <p><?php echo h(__t('help_packages.packages_p3')); ?></p>
        </section>
        <section class="panel">
            <h2><?php echo h(__t('help_packages.packages_os_heading')); ?></h2>
            <p><?php echo h(__t('help_packages.packages_os_p1')); ?></p>
            <p><?php echo h(__t('help_packages.packages_os_p2', ['days' => VIRTUSPHERE_PACKAGE_PURGE_AFTER_DAYS])); ?></p>
        </section>
    </div>
