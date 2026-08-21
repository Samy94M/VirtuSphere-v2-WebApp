<?php
// Help panel partial, included by portal/help.php. Not directly reachable
// (nginx denies /lib/).
declare(strict_types=1);
?>
    <div class="stack" id="panel-missions" role="tabpanel" aria-labelledby="tab-missions" tabindex="0" data-tab-panel>
        <section class="panel">
            <h2><?php echo h(__t('help_missions.mission_heading')); ?></h2>
            <p><?php echo h(__t('help_missions.mission_p1')); ?></p>
            <p><?php echo h(__t('help_missions.mission_p2')); ?></p>
            <p><?php echo h(__t('help_missions.mission_p3')); ?></p>
            <p><?php echo h(__t('help_missions.mission_p4')); ?></p>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('help_missions.naming_heading')); ?></h2>
            <p><?php echo h(__t('help_missions.naming_p0')); ?></p>
            <ul>
                <li><?php echo h(__t('help_missions.naming_p1')); ?></li>
                <li><?php echo h(__t('help_missions.naming_p2')); ?></li>
                <li><?php echo h(__t('help_missions.naming_p3')); ?></li>
                <li><?php echo h(__t('help_missions.naming_p4')); ?></li>
            </ul>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('help_missions.status_heading')); ?></h2>
            <p><?php echo h(__t('help_missions.status_p1')); ?></p>
            <?php // The one sentence that makes the list readable: a stage names the
                  // last report that arrived, not the work that is done. Without it
                  // "5/5" reads as "finished", which is what it used to promise. ?>
            <p><?php echo h(__t('help_missions.status_p2')); ?></p>
            <ul>
                <li><?php echo h(__t('help_missions.status_1')); ?></li>
                <li><?php echo h(__t('help_missions.status_2')); ?></li>
                <li><?php echo h(__t('help_missions.status_3')); ?></li>
                <li><?php echo h(__t('help_missions.status_4')); ?></li>
                <li><?php echo h(__t('help_missions.status_5')); ?></li>
            </ul>
            <h3><?php echo h(__t('help_missions.status_stuck_heading')); ?></h3>
            <ul>
                <li><?php echo h(__t('help_missions.status_stuck_1')); ?></li>
                <li><?php echo h(__t('help_missions.status_stuck_2', ['hours' => intdiv(VIRTUSPHERE_VM_MECM_PENDING_WARN_SECONDS, 3600)])); ?></li>
                <li><?php echo h(__t('help_missions.status_stuck_3', ['hours' => intdiv(VIRTUSPHERE_VM_OS_INSTALL_WARN_SECONDS, 3600)])); ?></li>
            </ul>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('help_missions.location_heading')); ?></h2>
            <p><?php echo h(__t('help_missions.location_p1')); ?></p>
            <p><?php echo h(__t('help_missions.location_p2')); ?></p>
            <ul>
                <li><?php echo h(__t('help_missions.location_datacenter_optional')); ?></li>
                <li><?php echo h(__t('help_missions.location_datastore_scope')); ?></li>
                <li><?php echo h(__t('help_missions.location_per_disk')); ?></li>
                <li><?php echo h(__t('help_missions.location_single_host')); ?></li>
            </ul>
            <p><?php echo h(__t('help_missions.location_p3')); ?></p>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('help_missions.disktype_heading')); ?></h2>
            <p><?php echo h(__t('help_missions.disktype_p1')); ?></p>
            <ul>
                <li><?php echo h(__t('help_missions.disktype_thin')); ?></li>
                <li><?php echo h(__t('help_missions.disktype_thick')); ?></li>
                <li><?php echo h(__t('help_missions.disktype_eager')); ?></li>
            </ul>
            <?php // Der vorbelegte Typ und die Zeitgrenze kommen aus ihren Konstanten:
                  // beide stehen sonst als Zahl bzw. Name im Text und lügen, sobald
                  // jemand die Konstante bewegt. ?>
            <p><?php echo h(__t('help_missions.disktype_p2', ['default' => disk_type_label(VIRTUSPHERE_VM_DEFAULTS['disk_type'])])); ?></p>
            <p><?php echo h(__t('help_missions.disktype_p3', ['minutes' => intdiv(VIRTUSPHERE_SSH_IDLE_TIMEOUT_SECONDS, 60)])); ?></p>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('help_missions.vm_delete_heading')); ?></h2>
            <p><?php echo h(__t('help_missions.vm_delete_p1')); ?></p>
            <p><?php echo h(__t('help_missions.vm_delete_step1')); ?></p>
            <p><?php echo h(__t('help_missions.vm_delete_step2')); ?></p>
            <p><?php echo h(__t('help_missions.vm_delete_step3')); ?></p>
            <p><?php echo h(__t('help_missions.vm_delete_p2')); ?></p>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('help_missions.transfer_heading')); ?></h2>
            <p><?php echo h(__t('help_missions.transfer_p1')); ?></p>
            <p><?php echo h(__t('help_missions.transfer_p2')); ?></p>
            <p><?php echo h(__t('help_missions.transfer_p3', ['minutes' => intdiv(VIRTUSPHERE_MISSION_IMPORT_TTL_SECONDS, 60)])); ?></p>
            <p><?php echo h(__t('help_missions.transfer_p4', ['max' => virtusphere_human_bytes(VIRTUSPHERE_MISSION_IMPORT_MAX_BYTES)])); ?></p>
            <p><?php echo h(__t('help_missions.transfer_scope')); ?></p>
            <p><?php echo h(__t('help_missions.transfer_session')); ?></p>
            <?php // The CSV note stays last: it answers "why can I not import the
                  // other download", which only comes up after the rest. ?>
            <p><?php echo h(__t('help_missions.transfer_p5')); ?></p>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('help_missions.bulk_heading')); ?></h2>
            <p><?php echo h(__t('help_missions.bulk_p1')); ?></p>
            <p><?php echo h(__t('help_missions.bulk_p2', ['cap' => VIRTUSPHERE_VM_BULK_CAP])); ?></p>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('help_missions.hotplug_heading')); ?></h2>
            <p><?php echo h(__t('help_missions.hotplug_p1')); ?></p>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('help_missions.creator_heading')); ?></h2>
            <p><?php echo h(__t('help_missions.creator_p1')); ?></p>
            <p><?php echo h(__t('help_missions.creator_p2')); ?></p>
            <ul>
                <li><?php echo h(__t('help_missions.creator_capture')); ?></li>
                <li><?php echo h(__t('help_missions.creator_clone')); ?></li>
                <li><?php echo h(__t('help_missions.creator_import')); ?></li>
            </ul>
            <p><?php echo h(__t('help_missions.creator_unknown')); ?></p>
        </section>
    </div>
