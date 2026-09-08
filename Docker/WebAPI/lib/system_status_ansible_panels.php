<?php

declare(strict_types=1);

// The Ansible half of the System status page: one card per Ansible credential
// with its preflight result, its cadence line and the latest mission activity.
// The activity presenter itself lives in lib/system_status_ansible_activity.php
// (ADR-0006).
require_once __DIR__ . '/credentials_status.php';
require_once __DIR__ . '/repo/log.php';
require_once __DIR__ . '/settings_page.php';
require_once __DIR__ . '/system_status.php';
require_once __DIR__ . '/system_status_ansible_activity.php';
require_once __DIR__ . '/system_status_shared_panels.php';

/** @param array<string,mixed> $snapshot */
function system_status_render_ansible(array $snapshot, array $user): void
{
    $canOpenJobLog = can('deploy.run', $user);
    $canManageCredentials = can('credentials.manage', $user);
    $canViewCredentialAudit = can('users.manage', $user);
    ?>
    <section class="panel status-section" id="<?php echo h(VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_ANSIBLE); ?>">
        <?php // The same heading shape as the MECM, ESXi and internal panels: the
              // link to the credentials page is this section's action and belongs
              // beside the title, not as a loose paragraph under the last row. ?>
        <div class="section-heading-actions">
            <div><h2><?php echo h(__t('system_status.ansible_heading')); ?></h2><p class="muted"><?php echo h(__t('system_status.ansible_hint')); ?></p></div>
            <?php // Not in the empty case: the empty-state below already carries this
                  // link as its call to action, and two identical buttons above each
                  // other read as two different destinations. ?>
            <?php if ($snapshot['ansible']['rows'] !== [] && can('credentials.manage', $user)) { ?><a class="button button-secondary" href="credentials.php"><?php echo h(__t('system_status.ansible_test_link')); ?></a><?php } ?>
        </div>
        <?php if ($snapshot['ansible']['rows'] === []) { ?>
            <div class="empty-state"><p><?php echo h(__t('system_status.ansible_empty')); ?></p><?php if (can('credentials.manage', $user)) { ?><a class="button button-secondary" href="credentials.php"><?php echo h(__t('system_status.ansible_test_link')); ?></a><?php } ?></div>
        <?php } else { ?>
            <div class="status-list">
            <?php foreach ($snapshot['ansible']['rows'] as $entry) {
                $credential = $entry['credential'];
                // $stateRow is the stored result, $entry['state'] the derived
                // Ampel. They were both called "state" here while only one of
                // them may colour a badge, which is how the row came to derive
                // its own instead of taking the snapshot's.
                $stateRow = $entry['state_row'];
                $lastMissionJob = is_array($entry['last_mission_job'] ?? null) ? $entry['last_mission_job'] : null;
                $component = trim((string) ($stateRow['last_component'] ?? ''));
                ?>
                <article class="status-row" data-deep-link-target id="credential-<?php echo h((string) $credential['id']); ?>">
                    <?php // The snapshot's state, not a fresh derivation from the row: re-deriving
                          // here would put a second clock on a page whose whole point is that every
                          // age is measured against one, and the row could then disagree with the
                          // overview card above it across a threshold. ?>
                    <div class="status-row-head"><strong><?php echo h((string) $credential['name']); ?></strong><?php echo ansible_state_badge((string) $entry['state']); ?></div>
                    <?php echo system_status_fact_list([
                        ['label' => __t('system_status.ansible_th_host'), 'html' => '<code>' . h((string) $credential['host']) . '</code>'],
                        ['label' => __t('system_status.ansible_th_last_full_test'), 'html' => ($stateRow !== null && !empty($stateRow['last_checked_at']))
                            ? h(portal_format_timestamp($stateRow['last_checked_at']))
                            : h(__t('system_status.ansible_never_tested'))],
                        ['label' => __t('system_status.ansible_th_last_mission_job'), 'html' => system_status_ansible_job_fact($lastMissionJob, $canOpenJobLog)],
                    ]); ?>
                    <?php if ((string) $entry['state'] === 'stale') { ?>
                        <p class="status-action"><?php echo h(__t('system_status.ansible_stale_detail', ['days' => VIRTUSPHERE_ANSIBLE_PREFLIGHT_STALE_AFTER_DAYS])); ?></p>
                    <?php } ?>
                    <?php // A badge over a timestamp reads as "last poll" everywhere else in
                          // the portal, and the preflight is the one status here that nothing
                          // refreshes. The line says so, from the same helper the Credentials
                          // page uses, so the two pages cannot describe the same row
                          // differently. ?>
                    <small class="status-cadence"><?php echo h(credential_cadence_ansible()); ?></small>
                    <?php if ($canManageCredentials || $canViewCredentialAudit) { ?>
                        <div class="actions">
                            <?php if ($canManageCredentials) { ?>
                                <form class="inline-form" method="post" action="credentials.php">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="test">
                                    <input type="hidden" name="credential_id" value="<?php echo h((string) $credential['id']); ?>">
                                    <input type="hidden" name="return_to" value="ansible_status">
                                    <button class="button button-secondary" type="submit" data-busy-label="<?php echo h(__t('system_status.ansible_testing')); ?>"><?php echo h(__t('system_status.ansible_test_now')); ?></button>
                                </form>
                            <?php } ?>
                            <?php if ($canViewCredentialAudit) { ?>
                                <a class="button button-ghost" href="<?php echo h(log_category_url(VIRTUSPHERE_LOG_CATEGORY_CREDENTIALS)); ?>"><?php echo h(__t('system_status.ansible_test_logs')); ?></a>
                            <?php } ?>
                        </div>
                    <?php } ?>
                    <?php
                    // A preflight warning stores the check that raised it in the
                    // same column a failure stores its broken component in, so
                    // "failed at: allowlist" would read as a failure of a test
                    // that in fact passed. The warning gets its own sentence.
                    // Read the stored result here, not the age-derived Ampel:
                    // an old allowlist warning becomes stale, but it remains
                    // a successful restricted test and must never be described
                    // as a failed component merely because its evidence aged.
                    // Its last sentence names another page, so the box carries the way
                    // there, gated like that page and under the label the MECM empty
                    // state already uses: one destination, one name.
                    if (($stateRow['last_status'] ?? '') === 'warning') { ?><div class="alert alert-warning"><?php echo h(__t('system_status.ansible_allowlist_detail')); ?><?php if (can('system.config', $user)) { ?> <a href="<?php echo h(settings_url(VIRTUSPHERE_SETTINGS_TAB_MACHINE_API)); ?>"><?php echo h(__t('system_status.mecm_configure_allowlist')); ?></a><?php } ?></div><?php
                    } elseif ($component !== '') { ?><p class="muted"><?php echo h(__t('system_status.ansible_failed_component', ['component' => $component])); ?></p><?php } ?>
                </article>
            <?php } ?>
            </div>
        <?php } ?>
    </section>
    <?php
}
