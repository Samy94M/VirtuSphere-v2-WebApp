<?php
// Help panel partial, included by portal/help.php. Not directly reachable
// (nginx denies /lib/).
declare(strict_types=1);
?>
    <div class="stack" id="panel-stack" role="tabpanel" aria-labelledby="tab-stack" tabindex="0" data-tab-panel>
        <section class="panel faq">
            <h2><?php echo h(__t('help.stack_heading')); ?></h2>
            <p><?php echo h(__t('help.stack_p1')); ?></p>
            <?php
            // Struktur je Frage: 'pre' Absaetze, optionale Liste ('ul'|'ol' + Anzahl),
            // 'post' Absaetze (p-Nummerierung laeuft nach der Liste weiter).
            $stackFaq = [
                1 => ['pre' => 1, 'list' => ['ul', 7], 'post' => 1],
                2 => ['pre' => 1, 'list' => ['ul', 5], 'post' => 1],
                3 => ['pre' => 4],
                4 => ['pre' => 1, 'list' => ['ol', 5], 'post' => 1],
                5 => ['pre' => 3],
                6 => ['pre' => 3],
                7 => ['pre' => 3],
                8 => ['pre' => 3],
                9 => ['pre' => 1, 'list' => ['ul', 7], 'post' => 1],
                10 => ['pre' => 3],
                13 => ['pre' => 2],
                11 => ['pre' => 1, 'list' => ['ul', 6], 'post' => 1],
                12 => ['pre' => 2],
            ];
            ?>
            <?php foreach ($stackFaq as $faqIndex => $faqShape): $faqParagraph = 0; ?>
                <details>
                    <summary><?php echo h(__t('help.stack_q' . $faqIndex)); ?></summary>
                    <?php for ($i = 0; $i < $faqShape['pre']; $i++): $faqParagraph++; ?>
                        <p><?php echo h(__t('help.stack_a' . $faqIndex . '_p' . $faqParagraph)); ?></p>
                    <?php endfor; ?>
                    <?php if (isset($faqShape['list'])): ?>
                        <?php if ($faqShape['list'][0] === 'ol'): ?>
                            <ol>
                                <?php for ($i = 1; $i <= $faqShape['list'][1]; $i++): ?>
                                    <li><?php echo h(__t('help.stack_a' . $faqIndex . '_li' . $i)); ?></li>
                                <?php endfor; ?>
                            </ol>
                        <?php else: ?>
                            <ul>
                                <?php for ($i = 1; $i <= $faqShape['list'][1]; $i++): ?>
                                    <li><?php echo h(__t('help.stack_a' . $faqIndex . '_li' . $i)); ?></li>
                                <?php endfor; ?>
                            </ul>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php for ($i = 0; $i < ($faqShape['post'] ?? 0); $i++): $faqParagraph++; ?>
                        <p><?php echo h(__t('help.stack_a' . $faqIndex . '_p' . $faqParagraph)); ?></p>
                    <?php endfor; ?>
                </details>
            <?php endforeach; ?>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('help.ops_heading')); ?></h2>
            <p><?php echo h(__t('help.ops_p1')); ?></p>
            <ul>
                <li><?php echo h(__t('help.ops_daily')); ?></li>
                <li><?php echo h(__t('help.ops_weekly')); ?></li>
                <li><?php echo h(__t('help.ops_monthly')); ?></li>
            </ul>
            <?php // Deep-link target of the backup card in portal/settings.php. From here
                  // the section reads in the order the link promises: Ablauf (ops_jobs_backup),
                  // Ablageort and Zurueckspielen (ops_restore_p1/p2). ?>
            <h3 id="help-backup"><?php echo h(__t('help.ops_jobs_heading')); ?></h3>
            <p><?php echo h(__t('help.ops_jobs_p1')); ?></p>
            <ul>
                <li><?php echo h(__t('help.ops_jobs_backup')); ?></li>
                <li><?php echo h(__t('help.ops_jobs_deploy', ['seconds' => VIRTUSPHERE_DEPLOY_WORKER_SLEEP_SECONDS])); ?></li>
                <li><?php echo h(__t('help.ops_jobs_maintenance', [
                    'sleep' => VIRTUSPHERE_MAINTENANCE_WORKER_SLEEP_SECONDS,
                ])); ?></li>
                <li><?php echo h(__t('help.ops_jobs_esxi', ['hours' => VIRTUSPHERE_ESXI_INVENTORY_INTERVAL_HOURS_DEFAULT])); ?></li>
            </ul>
            <p><?php echo h(__t('help.ops_jobs_p2')); ?></p>
            <p><code>sudo sh scripts/install-backup-schedule.sh --schedule "0 6 * * *"</code></p>
            <p><?php echo h(__t('help.ops_jobs_p3')); ?></p>

            <h3><?php echo h(__t('help.ops_restore_heading')); ?></h3>
            <p><?php echo h(__t('help.ops_restore_p1')); ?></p>
            <p><?php echo h(__t('help.ops_restore_p2')); ?></p>
            <p><?php echo h(__t('help.ops_restore_p3')); ?></p>

            <h3><?php echo h(__t('help.ops_backup_heading')); ?></h3>
            <ul>
                <li><?php echo h(__t('help.ops_backup_failed')); ?></li>
                <li><?php echo h(__t('help.ops_backup_stale')); ?></li>
                <li><?php echo h(__t('help.ops_backup_disk_low')); ?></li>
                <li><?php echo h(__t('help.ops_backup_unknown')); ?></li>
            </ul>
            <p class="muted"><?php echo h(__t('help.ops_backup_more')); ?></p>
        </section>
    </div>
