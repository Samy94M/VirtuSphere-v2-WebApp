<?php
// Help panel partial, included by portal/help.php. Not directly reachable
// (nginx denies /lib/).
declare(strict_types=1);
?>
    <div class="stack" id="panel-stack" role="tabpanel" aria-labelledby="tab-stack" tabindex="0" data-tab-panel>
        <section class="panel faq">
            <h2><?php echo h(__t('help_stack.stack_heading')); ?></h2>
            <p><?php echo h(__t('help_stack.stack_p1')); ?></p>
            <?php
            // The FAQ is read from the catalog (help_stack_faq() in lib/help_page.php),
            // never from a written table of counts: a new key renders without an edit here.
            $stackFaq = help_stack_faq(static fn (string $prefix): int => Lang::countNumbered($prefix));
            ?>
            <?php foreach ($stackFaq as $faqEntry): ?>
                <details>
                    <summary><?php echo h(__t($faqEntry['question'])); ?></summary>
                    <?php foreach ($faqEntry['before'] as $faqKey): ?>
                        <p><?php echo h(__t($faqKey)); ?></p>
                    <?php endforeach; ?>
                    <?php if ($faqEntry['list_tag'] === 'ol'): ?>
                        <ol>
                            <?php foreach ($faqEntry['items'] as $faqKey): ?>
                                <li><?php echo h(__t($faqKey)); ?></li>
                            <?php endforeach; ?>
                        </ol>
                    <?php elseif ($faqEntry['list_tag'] === 'ul'): ?>
                        <ul>
                            <?php foreach ($faqEntry['items'] as $faqKey): ?>
                                <li><?php echo h(__t($faqKey)); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php foreach ($faqEntry['after'] as $faqKey): ?>
                        <p><?php echo h(__t($faqKey)); ?></p>
                    <?php endforeach; ?>
                </details>
            <?php endforeach; ?>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('help_stack.ops_heading')); ?></h2>
            <p><?php echo h(__t('help_stack.ops_p1')); ?></p>
            <ul>
                <li><?php echo h(__t('help_stack.ops_daily')); ?></li>
                <li><?php echo h(__t('help_stack.ops_weekly')); ?></li>
                <li><?php echo h(__t('help_stack.ops_monthly')); ?></li>
            </ul>
            <?php // Deep-link target of the backup card in portal/settings.php. From here
                  // the section reads in the order the link promises: Ablauf (ops_jobs_backup),
                  // Ablageort and Zurueckspielen (ops_restore_p1/p2). ?>
            <h3 id="help-backup" tabindex="-1"><?php echo h(__t('help_stack.ops_jobs_heading')); ?></h3>
            <p><?php echo h(__t('help_stack.ops_jobs_p1')); ?></p>
            <ul>
                <li><?php echo h(__t('help_stack.ops_jobs_backup')); ?></li>
                <li><?php echo h(__t('help_stack.ops_jobs_deploy', ['seconds' => VIRTUSPHERE_DEPLOY_WORKER_SLEEP_SECONDS])); ?></li>
                <li><?php echo h(__t('help_stack.ops_jobs_maintenance', [
                    'sleep' => VIRTUSPHERE_MAINTENANCE_WORKER_SLEEP_SECONDS,
                ])); ?></li>
                <li><?php echo h(__t('help_stack.ops_jobs_esxi', ['hours' => VIRTUSPHERE_ESXI_INVENTORY_INTERVAL_HOURS_DEFAULT])); ?></li>
            </ul>
            <p><?php echo h(__t('help_stack.ops_jobs_p2')); ?></p>
            <p><code>sudo sh scripts/install-backup-schedule.sh --schedule "0 6 * * *"</code></p>
            <p><?php echo h(__t('help_stack.ops_jobs_p3')); ?></p>

            <h3><?php echo h(__t('help_stack.ops_restore_heading')); ?></h3>
            <p><?php echo h(__t('help_stack.ops_restore_p1')); ?></p>
            <p><?php echo h(__t('help_stack.ops_restore_p2')); ?></p>
            <p><?php echo h(__t('help_stack.ops_restore_p3')); ?></p>
            <p><?php echo h(__t('help_stack.ops_restore_p4')); ?></p>

            <h3><?php echo h(__t('help_stack.ops_backup_heading')); ?></h3>
            <ul>
                <li><?php echo h(__t('help_stack.ops_backup_failed')); ?></li>
                <li><?php echo h(__t('help_stack.ops_backup_stale')); ?></li>
                <li><?php echo h(__t('help_stack.ops_backup_disk_low')); ?></li>
                <li><?php echo h(__t('help_stack.ops_backup_unknown')); ?></li>
                <li><?php echo h(__t('help_stack.ops_backup_unencrypted')); ?></li>
            </ul>
            <p class="muted"><?php echo h(__t('help_stack.ops_backup_more')); ?></p>
        </section>

        <?php // Target of the APP_KEY links from the directory error texts (R11). ?>
        <section class="panel">
            <h2 id="help-app-key" tabindex="-1"><?php echo h(__t('help_stack.app_key_heading')); ?></h2>
            <p><?php echo h(__t('help_stack.app_key_p1')); ?></p>
            <p><?php echo h(__t('help_stack.app_key_p2')); ?></p>
            <p><?php echo h(__t('help_stack.app_key_p3')); ?></p>
            <p><?php echo h(__t('help_stack.app_key_p4')); ?></p>
        </section>
    </div>
