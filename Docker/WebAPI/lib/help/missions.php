<?php
// Help panel partial, included by portal/help.php. Not directly reachable
// (nginx denies /lib/).
declare(strict_types=1);
?>
    <div class="stack" id="panel-missions" role="tabpanel" aria-labelledby="tab-missions" tabindex="0" data-tab-panel>
        <section class="panel">
            <h2><?php echo h(__t('help.mission_heading')); ?></h2>
            <p><?php echo h(__t('help.mission_p1')); ?></p>
            <p><?php echo h(__t('help.mission_p2')); ?></p>
            <p><?php echo h(__t('help.mission_p3')); ?></p>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('help.naming_heading')); ?></h2>
            <p><?php echo h(__t('help.naming_p0')); ?></p>
            <ul>
                <li><?php echo h(__t('help.naming_p1')); ?></li>
                <li><?php echo h(__t('help.naming_p2')); ?></li>
                <li><?php echo h(__t('help.naming_p3')); ?></li>
                <li><?php echo h(__t('help.naming_p4')); ?></li>
            </ul>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('help.status_heading')); ?></h2>
            <p><?php echo h(__t('help.status_p1')); ?></p>
            <?php // The one sentence that makes the list readable: a stage names the
                  // last report that arrived, not the work that is done. Without it
                  // "5/5" reads as "finished", which is what it used to promise. ?>
            <p><?php echo h(__t('help.status_p2')); ?></p>
            <ul>
                <li><?php echo h(__t('help.status_1')); ?></li>
                <li><?php echo h(__t('help.status_2')); ?></li>
                <li><?php echo h(__t('help.status_3')); ?></li>
                <li><?php echo h(__t('help.status_4')); ?></li>
                <li><?php echo h(__t('help.status_5')); ?></li>
            </ul>
            <h3><?php echo h(__t('help.status_stuck_heading')); ?></h3>
            <ul>
                <li><?php echo h(__t('help.status_stuck_1')); ?></li>
                <li><?php echo h(__t('help.status_stuck_2')); ?></li>
                <li><?php echo h(__t('help.status_stuck_3')); ?></li>
            </ul>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('help.location_heading')); ?></h2>
            <p><?php echo h(__t('help.location_p1')); ?></p>
            <p><?php echo h(__t('help.location_p2')); ?></p>
            <ul>
                <li><?php echo h(__t('help.location_datacenter_optional')); ?></li>
                <li><?php echo h(__t('help.location_datastore_scope')); ?></li>
                <li><?php echo h(__t('help.location_per_disk')); ?></li>
                <li><?php echo h(__t('help.location_single_host')); ?></li>
            </ul>
            <p><?php echo h(__t('help.location_p3')); ?></p>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('help.vm_delete_heading')); ?></h2>
            <p><?php echo h(__t('help.vm_delete_p1')); ?></p>
            <p><?php echo h(__t('help.vm_delete_step1')); ?></p>
            <p><?php echo h(__t('help.vm_delete_step2')); ?></p>
            <p><?php echo h(__t('help.vm_delete_step3')); ?></p>
            <p><?php echo h(__t('help.vm_delete_p2')); ?></p>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('help.transfer_heading')); ?></h2>
            <p><?php echo h(__t('help.transfer_p1')); ?></p>
            <p><?php echo h(__t('help.transfer_p2')); ?></p>
            <p><?php echo h(__t('help.transfer_p3', ['minutes' => intdiv(VIRTUSPHERE_MISSION_IMPORT_TTL_SECONDS, 60)])); ?></p>
            <p><?php echo h(__t('help.transfer_p4', ['max' => virtusphere_human_bytes(VIRTUSPHERE_MISSION_IMPORT_MAX_BYTES)])); ?></p>
            <p><?php echo h(__t('help.transfer_p5')); ?></p>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('help.bulk_heading')); ?></h2>
            <p><?php echo h(__t('help.bulk_p1')); ?></p>
            <p><?php echo h(__t('help.bulk_p2', ['cap' => VIRTUSPHERE_VM_BULK_CAP])); ?></p>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('help.hotplug_heading')); ?></h2>
            <p><?php echo h(__t('help.hotplug_p1')); ?></p>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('help.creator_heading')); ?></h2>
            <p><?php echo h(__t('help.creator_p1')); ?></p>
            <p><?php echo h(__t('help.creator_p2')); ?></p>
            <ul>
                <li><?php echo h(__t('help.creator_capture')); ?></li>
                <li><?php echo h(__t('help.creator_clone')); ?></li>
                <li><?php echo h(__t('help.creator_import')); ?></li>
            </ul>
            <p><?php echo h(__t('help.creator_unknown')); ?></p>
        </section>
    </div>
