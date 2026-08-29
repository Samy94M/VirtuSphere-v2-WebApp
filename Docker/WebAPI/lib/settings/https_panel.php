<?php

declare(strict_types=1);

/** @var bool $httpsEnabled */
/** @var bool $httpsListenerLive */
/** @var bool $httpsRedirectEnabled */
/** @var bool $httpsHstsEnabled */
/** @var array<string,mixed>|null $httpsMeta */
/** @var string $httpsPort */

?>
    <div class="stack" id="panel-https" role="tabpanel" aria-labelledby="tab-https" tabindex="0" data-tab-panel hidden>
        <section class="panel">
            <h2><?php echo h(__t('settings.https_title')); ?></h2>
            <p class="muted"><?php echo h(__t('settings.https_hint')); ?></p>
            <?php if ($httpsEnabled && !$httpsListenerLive) { ?>
                <?php // The setting says "on" but the web server threw the generated config
                      // out (init.sh quarantine). Without this the card claims HTTPS is live
                      // while nothing listens, and the redirect has already been suppressed. ?>
                <div class="alert alert-warning"><?php echo h(__t('settings.https_quarantined')); ?></div>
            <?php } ?>
            <div class="table-wrap" tabindex="0"><table>
                <tbody>
                    <tr>
                        <th><?php echo h(__t('settings.https_enabled_title')); ?></th>
                        <td><?php echo portal_badge($httpsEnabled ? 'success' : 'neutral', $httpsEnabled ? __t('settings.https_state_on') : __t('settings.https_state_off')); ?></td>
                    </tr>
                    <tr>
                        <th><?php echo h(__t('settings.https_redirect_title')); ?></th>
                        <td><?php echo portal_badge($httpsRedirectEnabled ? 'success' : 'neutral', $httpsRedirectEnabled ? __t('settings.https_state_on') : __t('settings.https_state_off')); ?></td>
                    </tr>
                    <tr>
                        <th><?php echo h(__t('settings.https_hsts_title')); ?></th>
                        <td><?php echo portal_badge($httpsHstsEnabled ? 'success' : 'neutral', $httpsHstsEnabled ? __t('settings.https_state_on') : __t('settings.https_state_off')); ?></td>
                    </tr>
                </tbody>
            </table></div>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('settings.https_upload_title')); ?></h2>
            <p class="muted"><?php echo h(__t('settings.https_upload_hint')); ?></p>
            <form class="form-grid" method="post" action="settings.php" enctype="multipart/form-data" autocomplete="off">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="upload_https_cert">
                <label><?php echo h(__t('settings.https_cert_label')); ?>
                    <input name="cert_file" type="file"<?php echo form_input_class('https_upload', 'cert_file'); ?>>
                    <?php echo form_error_html('https_upload', 'cert_file'); ?>
                </label>
                <label><?php echo h(__t('settings.https_key_label')); ?>
                    <input name="key_file" type="file"<?php echo form_input_class('https_upload', 'key_file'); ?>>
                    <?php echo form_error_html('https_upload', 'key_file'); ?>
                </label>
                <label><?php echo h(__t('settings.https_password_label')); ?>
                    <input name="pfx_password" type="password" autocomplete="off"<?php echo form_input_class('https_upload', 'pfx_password'); ?>>
                    <?php echo form_error_html('https_upload', 'pfx_password'); ?>
                </label>
                <?php
                // The first upload is harmless; overwriting an installed
                // certificate asks first. Omitted, never rendered empty.
                ?>
                <div class="actions"><button class="button" type="submit"<?php echo $httpsMeta !== null ? ' data-confirm="' . h(__t('settings.https_confirm_overwrite')) . '"' : ''; ?>><?php echo h(__t('settings.https_upload_button')); ?></button></div>
            </form>
            <h3><?php echo h(__t('settings.https_meta_title')); ?></h3>
            <?php if ($httpsMeta === null) { ?>
                <p class="muted"><?php echo h(__t('settings.https_meta_none')); ?></p>
            <?php } else { ?>
                <?php if ($httpsMeta['days_remaining'] <= VIRTUSPHERE_HTTPS_CERT_EXPIRY_WARN_DAYS) { ?>
                    <p><?php echo portal_badge('warning', __t('settings.https_meta_expires_soon', ['days' => max(0, $httpsMeta['days_remaining'])])); ?></p>
                <?php } ?>
                <div class="table-wrap" tabindex="0"><table>
                    <tbody>
                        <tr><th><?php echo h(__t('settings.https_meta_subject')); ?></th><td><?php echo h($httpsMeta['subject']); ?></td></tr>
                        <tr><th><?php echo h(__t('settings.https_meta_sans')); ?></th><td><?php echo $httpsMeta['sans'] !== '' ? h($httpsMeta['sans']) : '<span class="muted">&mdash;</span>'; ?></td></tr>
                        <tr><th><?php echo h(__t('settings.https_meta_issuer')); ?></th><td><?php echo h($httpsMeta['issuer']); ?></td></tr>
                        <tr><th><?php echo h(__t('settings.https_meta_valid_from')); ?></th><td><?php echo h(portal_format_epoch($httpsMeta['valid_from'])); ?></td></tr>
                        <tr><th><?php echo h(__t('settings.https_meta_valid_to')); ?></th><td><?php echo h(portal_format_epoch($httpsMeta['valid_to'])); ?></td></tr>
                        <tr><th><?php echo h(__t('settings.https_meta_fingerprint')); ?></th><td><code class="wrap-anywhere"><?php echo h($httpsMeta['fingerprint']); ?></code></td></tr>
                    </tbody>
                </table></div>
            <?php } ?>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('settings.https_toggles_title')); ?></h2>
            <p class="muted"><?php echo h(__t('settings.https_enabled_hint', ['port' => $httpsPort])); ?></p>
            <div class="actions">
                <form method="post" action="settings.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="save_https_enabled">
                    <input type="hidden" name="https_enabled" value="<?php echo $httpsEnabled ? '0' : '1'; ?>">
                    <?php
                    // Enabling just adds a listener; disabling drops every
                    // HTTPS session (and the redirect with it), so only that
                    // branch asks. Omitted, never rendered empty.
                    ?>
                    <button class="button" type="submit"<?php echo $httpsEnabled ? ' data-confirm="' . h(__t('settings.https_confirm_disable')) . '"' : ''; ?>><?php echo h($httpsEnabled ? __t('settings.https_disable_button') : __t('settings.https_enable_button')); ?></button>
                </form>
            </div>
            <p class="muted"><?php echo h(__t('settings.https_redirect_hint')); ?></p>
            <div class="actions">
                <form method="post" action="settings.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="save_https_redirect">
                    <input type="hidden" name="https_redirect_enabled" value="<?php echo $httpsRedirectEnabled ? '0' : '1'; ?>">
                    <?php
                    // Enabling moves every HTTP browser to the new listener
                    // (lockout risk with an untrusted cert), so that branch
                    // asks; switching it off is the recovery path and stays
                    // one click.
                    ?>
                    <button class="button" type="submit"<?php echo !$httpsRedirectEnabled ? ' data-confirm="' . h(__t('settings.https_confirm_redirect')) . '"' : ''; ?>><?php echo h($httpsRedirectEnabled ? __t('settings.https_redirect_disable_button') : __t('settings.https_redirect_enable_button')); ?></button>
                </form>
            </div>
            <p class="muted"><?php echo h(__t('settings.https_hsts_hint', ['days' => intdiv(VIRTUSPHERE_HTTPS_HSTS_MAX_AGE_SECONDS, 86400)])); ?></p>
            <div class="actions">
                <form method="post" action="settings.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="save_https_hsts">
                    <input type="hidden" name="https_hsts_enabled" value="<?php echo $httpsHstsEnabled ? '0' : '1'; ?>">
                    <button class="button" type="submit"><?php echo h($httpsHstsEnabled ? __t('settings.https_hsts_disable_button') : __t('settings.https_hsts_enable_button')); ?></button>
                </form>
            </div>
        </section>
    </div>
