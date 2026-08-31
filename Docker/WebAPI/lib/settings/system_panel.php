<?php

declare(strict_types=1);

/** @var string $currentTimezone */
/** @var array<string,list<string>> $timezoneGroups */
/** @var int $serverEpoch */
/** @var string $sessionLifetimeMinutes */
/** @var string $passwordMinLength */
/** @var array<string,int> $retentionRows */
/** @var array<string,mixed> $backup */
/** @var string $backupState */
/** @var array<string,mixed> $backupLast */
/** @var int $backupTs */
/** @var int|null $backupAgeHours */
/** @var int|null $backupNextTs */
/** @var int|null $backupNextHours */
/** @var int|null $backupOverdueAt */
/** @var bool $backupIsOverdue */

?>
    <div class="stack" id="panel-system" role="tabpanel" aria-labelledby="tab-system" tabindex="0" data-tab-panel hidden>
        <section class="panel" id="panel-time">
            <h2><?php echo h(__t('settings.time_title')); ?></h2>
            <p class="muted" id="<?php echo h(form_hint_id('timezone', 'timezone')); ?>"><?php echo h(__t('settings.time_hint')); ?></p>
            <form class="form-grid" method="post" action="settings.php" autocomplete="off">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save_timezone">
                <label><?php echo h(__t('settings.timezone_label')); ?>
                    <select name="timezone"<?php echo form_control_attrs('timezone', 'timezone', null, true); ?>>
                        <?php $selectedTz = form_old('timezone', 'timezone', $currentTimezone); ?>
                        <?php foreach ($timezoneGroups as $groupKey => $identifiers) { ?>
                            <optgroup label="<?php echo h(__t('settings.timezone_group_' . $groupKey)); ?>">
                                <?php foreach ($identifiers as $tz) { ?>
                                    <option value="<?php echo h($tz); ?>"<?php echo $tz === $selectedTz ? ' selected' : ''; ?>><?php echo h(portal_timezone_option_label($tz)); ?></option>
                                <?php } ?>
                            </optgroup>
                        <?php } ?>
                    </select>
                    <?php echo form_error_html('timezone', 'timezone'); ?>
                </label>
                <div class="actions"><button class="button" type="submit"><?php echo h(__t('common.save')); ?></button></div>
            </form>
            <div class="table-wrap" tabindex="0"><table>
                <tbody>
                    <tr><th><?php echo h(__t('settings.time_server_now')); ?></th><td><?php echo h(portal_now_label()); ?></td></tr>
                </tbody>
            </table></div>
            <p class="alert alert-warning" data-time-drift hidden></p>
            <p class="muted"><?php echo h(__t('settings.time_ntp_hint')); ?></p>
            <script type="application/json" data-server-time nonce="<?php echo h(virtusphere_csp_nonce()); ?>"><?php
                echo json_encode([
                    'epoch' => $serverEpoch,
                    'warn_seconds' => 120,
                    'drift_message' => __t('settings.time_drift_warn'),
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            ?></script>
        </section>

        <section class="panel" id="panel-session">
            <h2><?php echo h(__t('settings.session_title')); ?></h2>
            <p class="muted" id="<?php echo h(form_hint_id('session', 'session_lifetime_minutes')); ?>"><?php echo h(__t('settings.session_hint')); ?></p>
            <form class="form-grid" method="post" action="settings.php" autocomplete="off">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save_session">
                <label><?php echo h(__t('settings.session_label')); ?>
                    <input name="session_lifetime_minutes" type="number" min="<?php echo h((string) VIRTUSPHERE_SESSION_LIFETIME_MINUTES_MIN); ?>" max="<?php echo h((string) VIRTUSPHERE_SESSION_LIFETIME_MINUTES_MAX); ?>" value="<?php echo h(form_old('session', 'session_lifetime_minutes', $sessionLifetimeMinutes)); ?>"<?php echo form_control_attrs('session', 'session_lifetime_minutes', null, true); ?>>
                    <?php echo form_error_html('session', 'session_lifetime_minutes'); ?>
                </label>
                <div class="actions"><button class="button" type="submit"><?php echo h(__t('common.save')); ?></button></div>
            </form>
        </section>

        <section class="panel" id="panel-password-policy">
            <h2><?php echo h(__t('settings.password_title')); ?></h2>
            <p class="muted" id="<?php echo h(form_hint_id('password_policy', 'password_min_length')); ?>"><?php echo h(__t('settings.password_hint')); ?></p>
            <form class="form-grid" method="post" action="settings.php" autocomplete="off">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save_password_policy">
                <label><?php echo h(__t('settings.password_label')); ?>
                    <input name="password_min_length" type="number" min="<?php echo h((string) VIRTUSPHERE_PASSWORD_MIN_LENGTH_MIN); ?>" max="<?php echo h((string) VIRTUSPHERE_PASSWORD_MIN_LENGTH_MAX); ?>" value="<?php echo h(form_old('password_policy', 'password_min_length', $passwordMinLength)); ?>"<?php echo form_control_attrs('password_policy', 'password_min_length', null, true); ?>>
                    <?php echo form_error_html('password_policy', 'password_min_length'); ?>
                </label>
                <div class="actions"><button class="button" type="submit"><?php echo h(__t('common.save')); ?></button></div>
            </form>
        </section>

        <section class="panel" id="panel-retention">
            <h2><?php echo h(__t('settings.retention_title')); ?></h2>
            <p class="muted"><?php echo h(__t('settings.retention_hint')); ?></p>
            <div class="table-wrap" tabindex="0"><table>
                <tbody>
                    <?php foreach ($retentionRows as $rowKey => $days) { ?>
                        <tr>
                            <th><?php echo h(__t('settings.' . $rowKey)); ?></th>
                            <td><?php echo h(__t('settings.retention_days', ['days' => $days])); ?></td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table></div>
        </section>

        <section class="panel" id="panel-backup">
            <h2><?php echo h(__t('settings.backup_title')); ?></h2>
            <p class="muted"><?php echo h(__t('settings.backup_hint')); ?></p>
            <?php if ($backupState !== VIRTUSPHERE_BACKUP_STATE_OK) { ?>
                <div class="alert <?php echo h(backup_status_alert_class($backupState)); ?>"><?php echo h(backup_status_message($backupState)); ?></div>
            <?php } ?>
            <div class="table-wrap" tabindex="0"><table>
                <tbody>
                    <tr>
                        <th><?php echo h(__t('settings.backup_state')); ?></th>
                        <td><span class="badge <?php echo h(backup_status_badge_class($backupState)); ?>"><?php echo h(backup_status_label($backupState)); ?></span></td>
                    </tr>
                    <tr>
                        <th><?php echo h(__t('settings.backup_schedule')); ?></th>
                        <td>
                            <?php if ($backup['schedule'] !== '') { ?>
                                <code><?php echo h($backup['schedule']); ?></code>
                                <span class="muted"><?php echo h(__t('settings.backup_schedule_from', ['source' => $backup['schedule_source']])); ?></span>
                            <?php } elseif ($backup['schedule_source'] !== '') { ?>
                                <code><?php echo h($backup['schedule_source']); ?></code>
                            <?php } else { ?>
                                <?php echo h(__t('settings.backup_schedule_none')); ?>
                                <span class="muted"><?php echo h(__t('settings.backup_schedule_none_hint')); ?></span>
                            <?php } ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php echo h(__t('settings.backup_last_run')); ?></th>
                        <td><?php echo $backupTs > 0 ? h(portal_format_epoch($backupTs)) : h(__t('settings.backup_never')); ?></td>
                    </tr>
                    <?php if ($backupAgeHours !== null) { ?>
                    <tr>
                        <th><?php echo h(__t('settings.backup_age')); ?></th>
                        <td><?php echo h($backupAgeHours >= 1
                            ? __t('settings.backup_age_hours', ['hours' => $backupAgeHours])
                            : __t('settings.backup_age_recent')); ?></td>
                    </tr>
                    <?php } ?>
                    <tr>
                        <th><?php echo h(__t('settings.backup_next_run')); ?></th>
                        <td>
                            <?php if ($backupNextTs === null) { ?>
                                &mdash; <span class="muted"><?php echo h(__t('settings.backup_next_unknown')); ?></span>
                            <?php } else { ?>
                                <?php echo h(portal_format_epoch($backupNextTs)); ?>
                                <span class="muted"><?php
                                    echo h($backupNextHours !== null && $backupNextHours >= 1
                                        ? __t('settings.backup_next_in_hours', ['hours' => $backupNextHours])
                                        : __t('settings.backup_next_soon'));
                                ?></span>
                            <?php } ?>
                        </td>
                    </tr>
                    <?php if ($backupOverdueAt !== null) { ?>
                    <tr>
                        <th><?php echo h(__t($backupIsOverdue ? 'settings.backup_overdue_since' : 'settings.backup_overdue_at')); ?></th>
                        <td><?php echo h(portal_format_epoch($backupOverdueAt)); ?></td>
                    </tr>
                    <?php } ?>
                    <?php if (($backupLast['db_bytes'] ?? null) !== null) { ?>
                    <tr>
                        <th><?php echo h(__t('settings.backup_db_size')); ?></th>
                        <td><?php echo h(backup_status_human_bytes((int) $backupLast['db_bytes'])); ?></td>
                    </tr>
                    <?php } ?>
                    <?php if (($backupLast['config_bytes'] ?? null) !== null) { ?>
                    <tr>
                        <th><?php echo h(__t('settings.backup_config_size')); ?></th>
                        <td><?php echo h(backup_status_human_bytes((int) $backupLast['config_bytes'])); ?></td>
                    </tr>
                    <?php } ?>
                    <?php if (($backupLast['disk_free_pct'] ?? null) !== null) { ?>
                    <tr>
                        <th><?php echo h(__t('settings.backup_disk_free')); ?></th>
                        <td><?php echo h((string) (int) $backupLast['disk_free_pct']); ?>&thinsp;%<?php
                            if (($backupLast['disk_free_bytes'] ?? null) !== null) {
                                echo ' (' . h(backup_status_human_bytes((int) $backupLast['disk_free_bytes'])) . ')';
                            }
                        ?></td>
                    </tr>
                    <?php } ?>
                    <?php if (($backupLast['keep'] ?? null) !== null) { ?>
                    <tr>
                        <th><?php echo h(__t('settings.backup_retention')); ?></th>
                        <td><?php echo h(__t('settings.backup_retention_value', ['count' => (int) $backupLast['keep']])); ?></td>
                    </tr>
                    <?php } ?>
                    <?php if (($backupLast['error'] ?? '') !== '') { ?>
                    <tr>
                        <th><?php echo h(__t('settings.backup_error')); ?></th>
                        <td><?php echo h((string) $backupLast['error']); ?></td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table></div>
            <p><?php echo h(__t('settings.backup_ops_where')); ?></p>
            <p><code>sudo sh scripts/install-backup-schedule.sh --schedule "0 6 * * *"</code></p>
            <p class="muted"><a href="<?php echo h(help_url('stack', 'help-backup')); ?>"><?php echo h(__t('settings.backup_ops_more')); ?></a></p>
        </section>
    </div>
