<?php
// Help panel partial, included by portal/help.php. Not directly reachable
// (nginx denies /lib/).
declare(strict_types=1);

require_once __DIR__ . '/../vm_phase.php';
require_once __DIR__ . '/../help_vm_path.php';
?>
    <div class="stack" id="panel-missions" role="tabpanel" aria-labelledby="tab-missions" tabindex="0" data-tab-panel>
        <section class="panel">
            <h2><?php echo h(__t('help_missions.mission_heading')); ?></h2>
            <p><?php echo h(__t('help_missions.mission_p1')); ?></p>
            <p><?php echo h(__t('help_missions.mission_p2')); ?></p>
            <p><?php echo h(__t('help_missions.mission_p3')); ?></p>
            <p><?php echo h(__t('help_missions.mission_p4')); ?></p>
            <h3><?php echo h(__t('help_missions.edit_conflict_heading')); ?></h3>
            <p><?php echo h(__t('help_missions.edit_conflict_p1')); ?></p>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('help_missions.vm_steps_heading')); ?></h2>
            <p><?php echo h(__t('help_missions.vm_steps_intro')); ?></p>
            <p><?php echo h(__t('help_missions.vm_steps_1')); ?></p>
            <p><?php echo h(__t('help_missions.vm_steps_2')); ?></p>
            <p><?php echo h(__t('help_missions.vm_steps_3', ['max' => (int) VIRTUSPHERE_VM_LIMITS['vm_name_max_length']])); ?></p>
            <p><?php echo h(__t('help_missions.vm_steps_4')); ?></p>
            <p><?php echo h(__t('help_missions.vm_steps_5')); ?></p>
            <p><?php echo h(__t('help_missions.vm_steps_6')); ?></p>
            <p><?php echo h(__t('help_missions.vm_steps_7')); ?></p>
            <p><?php echo h(__t('help_missions.vm_steps_8')); ?></p>
            <p class="muted"><?php echo h(__t('help_missions.vm_steps_note')); ?></p>
            <p><?php echo h(__t('help_missions.vm_steps_mecm')); ?></p>
            <p><?php echo h(__t('help_missions.vm_steps_mecm_os')); ?></p>
        </section>

        <section class="panel" id="help-work-context">
            <h2><?php echo h(__t('help_missions.work_context_heading')); ?></h2>
            <p><?php echo h(__t('help_missions.work_context_p1')); ?></p>
            <p><?php echo h(__t('help_missions.work_context_p2')); ?></p>
            <p><?php echo h(__t('help_missions.work_context_p3')); ?></p>
            <p><?php echo h(__t('help_missions.unsaved_p1')); ?></p>
        </section>

        <section class="panel" id="help-effective-values">
            <h2><?php echo h(__t('help_missions.effective_heading')); ?></h2>
            <p><?php echo h(__t('help_missions.effective_p1')); ?></p>
            <p><?php echo h(__t('help_missions.effective_p2')); ?></p>
            <p><?php echo h(__t('help_missions.effective_p3')); ?></p>
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
            <p><?php echo h(__t('help_missions.domain_p1')); ?></p>
        </section>

        <?php // Etappe 14D: Ein eigener Abschnitt, weil die beiden Namen und der
              // eingefrorene Rolloutname die haeufigste Rueckfrage der Etappe sind
              // und in der Namensregelliste als sechster Aufzaehlungspunkt
              // untergegangen waeren. ?>
        <section class="panel">
            <h2><?php echo h(__t('help_missions.rollout_heading')); ?></h2>
            <p><?php echo h(__t('help_missions.rollout_p1')); ?></p>
            <p><?php echo h(__t('help_missions.rollout_p2')); ?></p>
            <p><?php echo h(__t('help_missions.rollout_p3')); ?></p>
            <p><?php echo h(__t('help_missions.rollout_p4')); ?></p>
        </section>

        <section class="panel" id="help-vm-path">
            <h2><?php echo h(__t('help_vm_path.heading')); ?></h2>
            <p><?php echo h(__t('help_vm_path.intro', ['section' => __t('help_missions.status_heading')])); ?></p>
            <?php echo help_vm_path_figure(); ?>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('help_missions.status_heading')); ?></h2>
            <p><?php echo h(__t('help_missions.status_p1')); ?></p>
            <?php // The one sentence that makes the list readable: a phase names the
                  // last report that arrived, not the work that is done. ?>
            <p><?php echo h(__t('help_missions.status_p2')); ?></p>
            <?php // The phase keys are built from the closed phase order of lib/vm_phase.php. ?>
            <ul>
                <?php foreach (VIRTUSPHERE_VM_PHASES as $phase) { ?>
                    <li><?php echo h(__t('help_missions.status_phase_' . $phase)); ?></li>
                <?php } ?>
                <li><?php echo h(__t('help_missions.status_template')); ?></li>
            </ul>
            <h3><?php echo h(__t('help_missions.status_technical_heading')); ?></h3>
            <p><?php echo h(__t('help_missions.status_technical_p1')); ?></p>
            <h3><?php echo h(__t('help_missions.status_findings_heading')); ?></h3>
            <p><?php echo h(__t('help_missions.status_findings_p1')); ?></p>
            <?php // The finding keys follow the closed kind table; each sentence takes only the
                  // placeholders its own text uses. The two overdue limits come from their constants. ?>
            <ul>
                <?php foreach (array_keys(VIRTUSPHERE_VM_FINDINGS) as $kind) { ?>
                    <li><?php echo h(__t('help_missions.status_finding_' . $kind, [
                        'hours' => $kind === VIRTUSPHERE_VM_FINDING_OS_INSTALL_OVERDUE
                            ? intdiv(VIRTUSPHERE_VM_OS_INSTALL_WARN_SECONDS, 3600)
                            : intdiv(VIRTUSPHERE_VM_MECM_PENDING_WARN_SECONDS, 3600),
                        'minutes' => intdiv(VIRTUSPHERE_CLIENT_PHASE_UNCONFIRMED_AFTER_SECONDS, 60),
                    ])); ?></li>
                <?php } ?>
            </ul>
            <h3><?php echo h(__t('help_missions.status_axes_heading')); ?></h3>
            <p><?php echo h(__t('help_missions.status_axes_p1')); ?></p>
            <ul>
                <li><?php echo h(__t('help_missions.status_axes_mecm_not_ready')); ?></li>
                <li><?php echo h(__t('help_missions.status_axes_mecm_pending')); ?></li>
                <li><?php echo h(__t('help_missions.status_axes_mecm_registered')); ?></li>
                <li><?php echo h(__t('help_missions.status_axes_mecm_failed')); ?></li>
                <li><?php echo h(__t('help_missions.status_axes_lifecycle')); ?></li>
            </ul>
            <h3><?php echo h(__t('help_missions.status_stuck_heading')); ?></h3>
            <ul>
                <li><?php echo h(__t('help_missions.status_stuck_1')); ?> <a href="<?php echo h('deploy.php'); ?>"><?php echo h(__t('help_missions.link_deploy')); ?></a></li>
                <li><?php echo h(__t('help_missions.status_stuck_2', ['hours' => intdiv(VIRTUSPHERE_VM_MECM_PENDING_WARN_SECONDS, 3600)])); ?> <a href="<?php echo h(system_status_url(VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_MECM)); ?>"><?php echo h(__t('help_missions.link_system_status')); ?></a></li>
                <li><?php echo h(__t('help_missions.status_stuck_3', ['hours' => intdiv(VIRTUSPHERE_VM_OS_INSTALL_WARN_SECONDS, 3600)])); ?></li>
                <li><?php echo h(__t('help_missions.status_stuck_4')); ?></li>
            </ul>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('help_missions.badges_heading')); ?></h2>
            <p><?php echo h(__t('help_missions.badges_p1')); ?></p>
            <ul>
                <li><?php echo h(__t('help_missions.badge_mission_attention')); ?></li>
                <li><?php echo h(__t('help_missions.badge_vm_attention', [
                    'mecm_hours' => intdiv(VIRTUSPHERE_VM_MECM_PENDING_WARN_SECONDS, 3600),
                    'install_hours' => intdiv(VIRTUSPHERE_VM_OS_INSTALL_WARN_SECONDS, 3600),
                ])); ?></li>
                <li><?php echo h(__t('help_missions.badge_vm_editor')); ?></li>
                <li><?php echo h(__t('help_missions.badge_network')); ?></li>
                <li><?php echo h(__t('help_missions.badge_rollout')); ?></li>
            </ul>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('help_missions.location_heading')); ?></h2>
            <p><?php echo h(__t('help_missions.location_p1')); ?></p>
            <p><?php echo h(__t('help_missions.location_p2')); ?></p>
            <ul>
                <li><?php echo h(__t('help_missions.location_datacenter_optional', [
                    'hours' => intdiv(VIRTUSPHERE_ESXI_DATACENTER_DERIVATION_MAX_AGE_SECONDS, 3600),
                ])); ?> <a href="<?php echo h(system_status_url(VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_ESXI)); ?>"><?php echo h(__t('help_missions.link_system_status')); ?></a></li>
                <li><?php echo h(__t('help_missions.location_datastore_scope')); ?></li>
                <li><?php echo h(__t('help_missions.location_per_disk')); ?></li>
                <li><?php echo h(__t('help_missions.location_single_host')); ?></li>
            </ul>
            <p><?php echo h(__t('help_missions.location_p3')); ?> <a href="<?php echo h(system_status_url(VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_ESXI)); ?>"><?php echo h(__t('help_missions.link_system_status')); ?></a></p>
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
            <p><?php echo h(__t('help_missions.disktype_p3', [
                'minutes' => intdiv(VIRTUSPHERE_SSH_IDLE_TIMEOUT_SECONDS, 60),
                'hours' => intdiv(deploy_create_total_budget_seconds(), 3600),
            ])); ?></p>
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
