<?php
// Help panel partial, included by portal/help.php. Not directly reachable
// (nginx denies /lib/).
declare(strict_types=1);
// The tab is named like the navigation group "Katalog" (PR-R6): packages,
// operating systems and port groups. The panel id stays panel-packages, so
// every existing link keeps its target.
?>
    <div class="stack" id="panel-packages" role="tabpanel" aria-labelledby="tab-packages" tabindex="0" data-tab-panel>
        <section class="panel" id="help-packages">
            <h2><?php echo h(__t('help_packages.packages_heading')); ?></h2>
            <?php // PR-R6: the config.json rules stood in one parenthesis of about
                  // 400 characters; they are a list now. ?>
            <p><?php echo h(__t('help_packages.packages_p1')); ?></p>
            <ul>
                <li><?php echo h(__t('help_packages.packages_p1_li1')); ?></li>
                <li><?php echo h(__t('help_packages.packages_p1_li2')); ?></li>
                <li><?php echo h(__t('help_packages.packages_p1_li3')); ?></li>
            </ul>
            <p><?php echo h(__t('help_packages.packages_p1_b')); ?></p>
            <p><?php echo h(__t('help_packages.packages_p1_c')); ?></p>
            <p><?php echo h(__t('help_packages.packages_p1b')); ?></p>
            <p><?php echo h(__t('help_packages.packages_p1b_b')); ?></p>
            <?php // Interpolates the constant the purge uses, so text and behaviour cannot drift. ?>
            <?php // Split in two: what happens to an ASSIGNMENT (a decision an
                  // operator has to understand) and what happens to the retired
                  // ROW after the cleanup window. The purge days belong to the
                  // second sentence only. ?>
            <p><?php echo h(__t('help_packages.packages_p2')); ?></p>
            <p><?php echo h(__t('help_packages.packages_p2b', ['days' => VIRTUSPHERE_PACKAGE_PURGE_AFTER_DAYS])); ?></p>
            <p><?php echo h(__t('help_packages.packages_p3')); ?></p>
            <h3><?php echo h(__t('help_packages.packages_diagnostics_heading')); ?></h3>
            <p><?php echo h(__t('help_packages.packages_diagnostics_current')); ?></p>
            <p><?php echo h(__t('help_packages.packages_diagnostics_current_b')); ?></p>
            <p><?php echo h(__t('help_packages.packages_diagnostics_current_c')); ?></p>
            <p><?php echo h(__t('help_packages.packages_usage')); ?></p>
            <p><?php echo h(__t('help_packages.packages_portal_reports')); ?> <a href="package_reports.php"><?php echo h(__t('help_packages.link_reports')); ?></a></p>
            <?php // PR-R4B: the safeguards the report page used to repeat per row. ?>
            <p><?php echo h(__t('help_packages.packages_portal_reports_reading')); ?></p>
        </section>
        <section class="panel" id="help-os">
            <h2><?php echo h(__t('help_packages.packages_os_heading')); ?></h2>
            <p><?php echo h(__t('help_packages.packages_os_p1')); ?></p>
            <p><?php echo h(__t('help_packages.packages_os_p2', ['days' => VIRTUSPHERE_PACKAGE_PURGE_AFTER_DAYS])); ?></p>
            <p><?php echo h(__t('help_packages.packages_os_p2_b', ['days' => VIRTUSPHERE_PACKAGE_PURGE_AFTER_DAYS])); ?></p>
            <p><?php echo h(__t('help_packages.packages_os_usage')); ?></p>
        </section>
        <?php // The port group catalog (PR-R6: moved here from the System status
              // help). How the inventory fills it stays in the ESXi inventory
              // section there, which the third paragraph names. ?>
        <section class="panel">
            <h2 id="help-vlans" tabindex="-1"><?php echo h(__t('help_packages.vlans_heading')); ?></h2>
            <p><?php echo h(__t('help_packages.vlans_p1')); ?></p>
            <p><?php echo h(__t('help_packages.vlans_p2')); ?></p>
            <p><?php echo h(__t('help_packages.vlans_p3')); ?></p>
            <p><?php echo h(__t('help_packages.vlans_usage')); ?></p>
        </section>
    </div>
