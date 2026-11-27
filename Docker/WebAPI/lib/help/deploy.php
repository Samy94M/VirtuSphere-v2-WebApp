<?php
// Help panel partial, included by portal/help.php. Not directly reachable
// (nginx denies /lib/).
declare(strict_types=1);
require_once __DIR__ . '/../deploy_create_constants.php';
require_once __DIR__ . '/../deploy_late_start.php';
// The draft lifetime in the blockers text comes from its owner (PR-R3C, TS1 H2a-6).
require_once __DIR__ . '/../deploy_form_state.php';
// The hardware versions shown in the create text come from their owner, not from a typed number (R9).
require_once __DIR__ . '/../esxi_create_compatibility.php';
require_once __DIR__ . '/../settings_page.php';
// The two figures of this panel: what each mode runs, and the states of a job.
require_once __DIR__ . '/../help_deploy_modes.php';
require_once __DIR__ . '/../help_deploy_job_states.php';

// PR-R6 order: queuing a job, the modes, following it, stepping in, the
// service. Every former subsection keeps its anchor on its heading; the table
// of contents is generated from the H2 sections (help_panel_with_toc()).
?>
    <div class="stack" id="panel-deploy" role="tabpanel" aria-labelledby="tab-deploy" tabindex="0" data-tab-panel>
        <section class="panel" id="help-deploy-requirements">
            <h2><?php echo h(__t('help_deploy.requirements_heading')); ?></h2>
            <p><?php echo h(__t('help_deploy.deploy_requirements_p1')); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_requirements_p2')); ?></p>
            <h3><?php echo h(__t('help_deploy.credentials_heading')); ?></h3>
            <p><?php echo h(__t('help_deploy.credentials_p1')); ?> <a href="<?php echo h(settings_url(VIRTUSPHERE_SETTINGS_TAB_DEPLOY)); ?>"><?php echo h(__t('help_deploy.link_settings')); ?></a></p>
            <h3><?php echo h(__t('help_deploy.deploy_blockers_heading')); ?></h3>
            <p><?php echo h(__t('help_deploy.deploy_blockers_p1')); ?></p>
            <?php $draftParams = ['minutes' => intdiv(VIRTUSPHERE_DEPLOY_DRAFT_MAX_AGE_SECONDS, 60)]; ?>
            <p><?php echo h(__t('help_deploy.deploy_blockers_p2', $draftParams)); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_blockers_p2_b', $draftParams)); ?></p>
            <h3 id="help-network-contract" tabindex="-1"><?php echo h(__t('help_deploy.network_contract_heading')); ?></h3>
            <p><?php echo h(__t('help_deploy.network_contract_p1')); ?></p>
            <p><?php echo h(__t('help_deploy.network_contract_p2', deploy_network_contract_mode_lists())); ?></p>
            <p><?php echo h(__t('help_deploy.network_contract_p3')); ?></p>
            <p><?php echo h(__t('help_deploy.network_contract_p4', ['vms' => VIRTUSPHERE_DEPLOY_JOB_SCOPE_MAX_VMS, 'nics' => VIRTUSPHERE_DEPLOY_JOB_SCOPE_MAX_INTERFACES_PER_VM])); ?></p>
        </section>

        <section class="panel" id="help-deploy-storage">
            <h2><?php echo h(__t('help_deploy.deploy_storage_heading')); ?></h2>
            <p><?php echo h(__t('help_deploy.deploy_storage_p1', [
                // The default disk size with its unit and the default
                // provisioning type, from the constants the creator actually
                // applies, so the example and the default cannot drift apart.
                // The type goes through the label: the stored value is the
                // vmware_guest token, and "eagerzeroedthick" in a sentence about
                // storage demand tells a reader nothing.
                'size' => VIRTUSPHERE_VM_DEFAULTS['disk_size_gb'] . ' GB',
                'type' => disk_type_label(VIRTUSPHERE_VM_DEFAULTS['disk_type']),
            ])); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_storage_p2')); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_storage_p3')); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_storage_p4')); ?></p>
        </section>

        <section class="panel" id="help-deploy-warnings">
            <h2><?php echo h(__t('help_deploy.deploy_warn_heading')); ?></h2>
            <p><?php echo h(__t('help_deploy.deploy_warn_p1')); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_warn_p1_b')); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_warn_p2')); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_warn_p3')); ?></p>
        </section>

        <section class="panel" id="help-deploy-mode-list">
            <h2><?php echo h(__t('help_deploy.deploy_heading')); ?></h2>
            <h3 id="help-deploy-modes" tabindex="-1"><?php echo h(__t('help_deploy_figures.modes_heading')); ?></h3>
            <p><?php echo h(__t('help_deploy_figures.modes_intro')); ?></p>
            <?php echo help_deploy_modes_figure(); ?>
            <p><?php echo h(__t('help_deploy.deploy_p1')); ?></p>
            <ul>
                <li><?php echo h(__t('help_deploy.deploy_mode_full')); ?></li>
                <li>
                    <p><?php echo h(__t('help_deploy.deploy_mode_create')); ?></p>
                    <p><?php echo h(__t('help_deploy.deploy_mode_create_b')); ?></p>
                </li>
                <li>
                    <p><?php echo h(__t('help_deploy.deploy_mode_powercycle')); ?></p>
                    <p><?php echo h(__t('help_deploy.deploy_mode_powercycle_b')); ?></p>
                </li>
                <li>
                    <p><?php echo h(__t('help_deploy.deploy_mode_export')); ?></p>
                    <p><?php echo h(__t('help_deploy.deploy_mode_export_b')); ?></p>
                    <p><?php echo h(__t('help_deploy.deploy_mode_export_c')); ?></p>
                </li>
                <li><?php echo h(__t('help_deploy.deploy_mode_start')); ?></li>
                <li><?php echo h(__t('help_deploy.deploy_mode_autostart')); ?></li>
            </ul>
            <?php $modeRetryParams = [
                'retries' => (string) count(VIRTUSPHERE_CREATE_RETRY_PAUSES_SECONDS),
                'pause_2' => (string) VIRTUSPHERE_CREATE_RETRY_PAUSES_SECONDS[0],
                'pause_3' => (string) VIRTUSPHERE_CREATE_RETRY_PAUSES_SECONDS[1],
                'pause_4' => (string) VIRTUSPHERE_CREATE_RETRY_PAUSES_SECONDS[2],
            ]; ?>
            <p><?php echo h(__t('help_deploy.deploy_mode_retry_p1', $modeRetryParams)); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_mode_retry_p1_b', $modeRetryParams)); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_uncreated_vm_p1')); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_mode_p2')); ?></p>
            <h3><?php echo h(__t('help_deploy.deploy_powercycle_wait_heading')); ?></h3>
            <p><?php echo h(__t('help_deploy.deploy_powercycle_wait_p1', ['modes' => deploy_mode_label_list(ansible_modes_using_powercycle())])); ?></p>
            <h3><?php echo h(__t('help_deploy.deploy_start_wait_heading')); ?></h3>
            <p><?php echo h(__t('help_deploy.deploy_start_wait_p1', [
                'default' => VIRTUSPHERE_START_WAIT_SECONDS_DEFAULT,
            ])); ?></p>
            <?php // All three numbers come from the constants the deploy actually
                  // runs on, including the SSH idle budget the upper bound derives
                  // from: the explanation of WHY there is a ceiling is only true as
                  // long as it names the ceiling that exists. ?>
            <p><?php echo h(__t('help_deploy.deploy_start_wait_p2', [
                'max' => VIRTUSPHERE_START_WAIT_SECONDS_MAX,
                'idle' => VIRTUSPHERE_SSH_IDLE_TIMEOUT_SECONDS,
                'modes' => deploy_mode_label_list(ansible_modes_using_start()),
            ])); ?></p>
            <h3><?php echo h(__t('help_deploy.deploy_identity_heading')); ?></h3>
            <p><?php echo h(__t('help_deploy.deploy_identity_p1')); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_identity_p1_b')); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_identity_p2')); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_identity_p2_b')); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_identity_p3')); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_identity_p4')); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_identity_p4_b')); ?></p>
            <h3><?php echo h(__t('help_deploy.deploy_transport_heading')); ?></h3>
            <p><?php echo h(__t('help_deploy.deploy_transport_p1', [
                'sftp_operation' => VIRTUSPHERE_SFTP_OP_TIMEOUT_SECONDS,
                'sftp_total' => VIRTUSPHERE_SFTP_TOTAL_TIMEOUT_SECONDS,
                'ssh_idle' => VIRTUSPHERE_SSH_IDLE_TIMEOUT_SECONDS,
                'ssh_total' => VIRTUSPHERE_SSH_TOTAL_TIMEOUT_SECONDS,
            ])); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_transport_p2')); ?></p>
        </section>

        <section class="panel" id="help-deploy-schedule">
            <h2><?php echo h(__t('help_deploy.deploy_schedule_heading')); ?></h2>
            <p><?php echo h(__t('help_deploy.deploy_schedule_p1')); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_schedule_p2', ['modes' => deploy_mode_label_list(VIRTUSPHERE_DEPLOY_STAGGER_MODES)])); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_schedule_p3')); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_schedule_p4', ['minutes' => VIRTUSPHERE_DEPLOY_LATE_START_THRESHOLD_MINUTES])); ?></p>
        </section>

        <section class="panel" id="help-deploy-result">
            <h2><?php echo h(__t('help_deploy.result_heading')); ?></h2>
            <h3 id="help-deploy-job-states" tabindex="-1"><?php echo h(__t('help_deploy_figures.job_states_heading')); ?></h3>
            <p><?php echo h(__t('help_deploy_figures.job_states_intro')); ?></p>
            <?php echo help_deploy_job_states_figure(); ?>
            <h3><?php echo h(__t('help_deploy.deploy_terminal_heading')); ?></h3>
            <p><?php echo h(__t('help_deploy.deploy_terminal_p1')); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_terminal_p2')); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_terminal_p3')); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_terminal_p3_b')); ?></p>
            <h3><?php echo h(__t('help_deploy.deploy_state_heading')); ?></h3>
            <p><?php echo h(__t('help_deploy.deploy_state_p1')); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_state_p2')); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_state_p3')); ?></p>
        </section>

        <section class="panel" id="help-create-progress">
            <h2><?php echo h(__t('help_deploy.create_progress_heading')); ?></h2>
            <p><?php echo h(__t('help_deploy.create_progress_p1')); ?></p>
            <p><?php echo h(__t('help_deploy.create_progress_p2')); ?></p>
            <p><?php echo h(__t('help_deploy.create_progress_p3')); ?></p>
            <p><?php echo h(__t('help_deploy.create_progress_p3_b')); ?></p>
            <p><?php echo h(__t('help_deploy.create_progress_release')); ?></p>
            <p><?php echo h(__t('help_deploy.create_progress_p4')); ?></p>
            <?php $createRetryParams = [
                'retries' => (string) (VIRTUSPHERE_CREATE_MAX_ATTEMPTS - 1),
                'max' => (string) VIRTUSPHERE_CREATE_MAX_ATTEMPTS,
                'pause_2' => (string) VIRTUSPHERE_CREATE_RETRY_PAUSES_SECONDS[0],
                'pause_3' => (string) VIRTUSPHERE_CREATE_RETRY_PAUSES_SECONDS[1],
                'pause_4' => (string) VIRTUSPHERE_CREATE_RETRY_PAUSES_SECONDS[2],
            ]; ?>
            <p><?php echo h(__t('help_deploy.create_progress_p5', $createRetryParams)); ?></p>
            <p><?php echo h(__t('help_deploy.create_progress_p5_b', $createRetryParams)); ?></p>
            <p><?php echo h(__t('help_deploy.create_progress_p6')); ?></p>
            <p><?php echo h(__t('help_deploy.create_progress_p6_b')); ?></p>
            <p><?php echo h(__t('help_deploy.create_progress_p6_c')); ?></p>
            <?php
            // PR-R3C (TS1 H2a-7): releases and guest minimums come from their tables, not typed into the text.
            $helpCreateHwList = implode(', ', array_map(
                static fn (array $row): string => __t('help_deploy.create_progress_p7_hw', [
                    'release' => esxi_release_label((string) $row['esxi']),
                    'vmx' => (string) $row['vmx'],
                ]),
                VIRTUSPHERE_ESXI_CREATE_HARDWARE_VERSIONS
            ));
            $helpCreateGuestFloors = array_values(array_filter(
                VIRTUSPHERE_GUEST_OS_OPTIONS,
                static fn (array $option): bool => version_compare((string) $option['min_esxi'], esxi_create_release_floor(), '>')
            ));
            usort($helpCreateGuestFloors, static fn (array $a, array $b): int => version_compare((string) $a['min_esxi'], (string) $b['min_esxi']));
            $helpCreateGuestList = implode(', ', array_map(
                static fn (array $option): string => __t('help_deploy.create_progress_p7_guest', [
                    'guest' => __t((string) $option['label_key']),
                    'release' => esxi_release_label((string) $option['min_esxi']),
                ]),
                $helpCreateGuestFloors
            ));
            ?>
            <p><?php echo h(__t('help_deploy.create_progress_p7', [
                'hw_list' => $helpCreateHwList,
                'guest_list' => $helpCreateGuestList,
            ])); ?></p>
        </section>

        <section class="panel" id="help-deploy-log">
            <h2><?php echo h(__t('help_deploy.log_view_heading')); ?></h2>
            <p><?php echo h(__t('help_deploy.log_view_p1')); ?></p>
            <?php $logSearchParams = ['limit' => VIRTUSPHERE_DEPLOY_LOG_SEARCH_LIMIT]; ?>
            <p><?php echo h(__t('help_deploy.log_view_p2', $logSearchParams)); ?></p>
            <p><?php echo h(__t('help_deploy.log_view_p2_b', $logSearchParams)); ?></p>
            <p><?php echo h(__t('help_deploy.log_view_p3')); ?></p>
            <h3 id="help-deploy-verbose" tabindex="-1"><?php echo h(__t('help_deploy.deploy_verbose_heading')); ?></h3>
            <p><?php echo h(__t('help_deploy.deploy_verbose_p1')); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_verbose_p2')); ?></p>
            <h3><?php echo h(__t('help_deploy.deploy_output_limits_heading')); ?></h3>
            <p><?php echo h(__t('help_deploy.deploy_output_limits_p1', [
                'line_bytes' => VIRTUSPHERE_DEPLOY_OUTPUT_LINE_MAX_BYTES,
                'job_bytes' => VIRTUSPHERE_DEPLOY_OUTPUT_JOB_MAX_BYTES,
            ])); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_output_limits_p2')); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_log_window_p1', [
                'limit' => VIRTUSPHERE_DEPLOY_LOG_DOM_WINDOW,
            ])); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_log_window_p2', [
                'days' => VIRTUSPHERE_DEPLOY_JOB_LOG_RETENTION_DAYS,
            ])); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_preflight_output_p1')); ?></p>
        </section>

        <section class="panel" id="help-deploy-cancel">
            <h2><?php echo h(__t('help_deploy.deploy_cancel_heading')); ?></h2>
            <p><?php echo h(__t('help_deploy.deploy_cancel_p1')); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_cancel_p1b')); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_cancel_p1c')); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_cancel_p2', ['minutes' => intdiv(VIRTUSPHERE_DEPLOY_STALE_AFTER_SECONDS, 60)])); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_cancel_p3')); ?></p>
        </section>

        <section class="panel" id="help-deploy-retry">
            <h2><?php echo h(__t('help_deploy.deploy_retry_heading')); ?></h2>
            <p><?php echo h(__t('help_deploy.deploy_retry_p1')); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_retry_p2')); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_retry_p2_b')); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_retry_p2_c')); ?></p>
            <p><?php echo h(__t('help_deploy.deploy_retry_p3')); ?></p>
        </section>

        <section class="panel" id="help-action-outcomes">
            <h2><?php echo h(__t('help_deploy.action_outcomes_heading')); ?></h2>
            <p><?php echo h(__t('help_deploy.action_outcomes_p1')); ?></p>
            <p><?php echo h(__t('help_deploy.action_outcomes_p2')); ?></p>
            <p><?php echo h(__t('help_deploy.action_outcomes_p3')); ?></p>
        </section>

        <section class="panel" id="help-deploy-service">
            <h2><?php echo h(__t('help_deploy.service_heading')); ?></h2>
            <p><?php echo h(__t('help_deploy.service_p1')); ?></p>
            <p><?php echo h(__t('help_deploy.service_p2')); ?></p>
            <p><?php echo h(__t('help_deploy.service_p3')); ?></p>
            <p><?php echo h(__t('help_deploy.service_p4')); ?></p>
            <p><?php echo h(__t('help_deploy.service_supervisor_p1')); ?></p>
            <p><?php echo h(__t('help_deploy.service_supervisor_p2')); ?></p>
            <p><?php echo h(__t('help_deploy.service_supervisor_p3')); ?></p>
            <p><?php echo h(__t('help_deploy.service_supervisor_p4')); ?></p>
            <p><?php echo h(__t('help_deploy.service_supervisor_p5')); ?></p>
        </section>

        <section class="panel" id="help-autostart">
            <h2><?php echo h(__t('help_deploy.autostart_heading')); ?></h2>
            <p><?php echo h(__t('help_deploy.autostart_p1')); ?></p>
            <h3><?php echo h(__t('help_deploy.autostart_inherit_heading')); ?></h3>
            <p><?php echo h(__t('help_deploy.autostart_inherit_p1', ['seconds' => VIRTUSPHERE_AUTOSTART_DELAY_DEFAULT])); ?></p>
            <p><?php echo h(__t('help_deploy.autostart_inherit_p2')); ?></p>
            <p><?php echo h(__t('help_deploy.autostart_delays_p1')); ?></p>
            <h3><?php echo h(__t('help_deploy.autostart_heartbeat_heading')); ?></h3>
            <p><?php echo h(__t('help_deploy.autostart_heartbeat_p1')); ?></p>
            <p><?php echo h(__t('help_deploy.autostart_heartbeat_p2')); ?></p>
            <h3><?php echo h(__t('help_deploy.autostart_limits_heading')); ?></h3>
            <ul>
                <li><?php echo h(__t('help_deploy.autostart_limit_license')); ?></li>
                <li><?php echo h(__t('help_deploy.autostart_limit_ha')); ?></li>
                <li><?php echo h(__t('help_deploy.autostart_limit_tools')); ?></li>
                <li><?php echo h(__t('help_deploy.autostart_limit_order')); ?></li>
                <li><?php echo h(__t('help_deploy.autostart_limit_shared_host')); ?></li>
            </ul>
            <h3><?php echo h(__t('help_deploy.autostart_run_heading')); ?></h3>
            <p><?php echo h(__t('help_deploy.autostart_run_p1b')); ?></p>
            <p><?php echo h(__t('help_deploy.autostart_run_p1')); ?></p>
            <p><?php echo h(__t('help_deploy.autostart_run_p2')); ?></p>
        </section>
    </div>
