<?php

declare(strict_types=1);
?>
    <div class="stack" id="panel-credentials" role="tabpanel" aria-labelledby="tab-credentials" tabindex="0" data-tab-panel>
        <section class="panel">
            <h2><?php echo h(__t('help.credentials_heading')); ?></h2>
            <p><?php echo h(__t('help.credentials_p1')); ?></p>
            <ul>
                <li><?php echo h(__t('help.credentials_ansible')); ?></li>
                <li><?php echo h(__t('help.credentials_esxi')); ?></li>
                <li><?php echo h(__t('help.credentials_mecm')); ?></li>
            </ul>
        </section>
        <section class="panel">
            <h2><?php echo h(__t('help.credentials_tests_heading')); ?></h2>
            <p><?php echo h(__t('help.credentials_tests_p1', ['days' => VIRTUSPHERE_ANSIBLE_PREFLIGHT_STALE_AFTER_DAYS])); ?></p>
            <p><?php echo h(__t('help.credentials_tests_p2')); ?></p>
            <p><?php echo h(__t('help.credentials_tests_p3')); ?></p>
            <h3><?php echo h(__t('help.credentials_cadence_heading')); ?></h3>
            <p><?php echo h(__t('help.credentials_cadence_p1')); ?></p>
            <ul>
                <li><?php echo h(__t('help.credentials_cadence_off')); ?></li>
                <li><?php echo h(__t('help.credentials_cadence_no_ansible')); ?></li>
                <li><?php echo h(__t('help.credentials_cadence_paused')); ?></li>
            </ul>
        </section>
    </div>
