<?php

declare(strict_types=1);

/** @var string $storedApiBaseUrl */
/** @var string $apiBaseUrlSource */
/** @var string $apiBaseUrlSourceBadge */
/** @var string $apiBaseUrlSourceLabel */
/** @var string $effectiveApiBaseUrlError */
/** @var string $effectiveApiBaseUrl */

?>
    <div class="stack" id="panel-deploy" role="tabpanel" aria-labelledby="tab-deploy" tabindex="0" data-tab-panel>
        <section class="panel">
            <h2><?php echo h(__t('settings.deploy_settings_title')); ?></h2>
            <p class="muted settings-url-intro"><?php echo h(__t('settings.api_base_url_intro')); ?></p>
            <form class="settings-url-form" method="post" action="settings.php" autocomplete="off">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save_api">
                <label for="api-base-url"><?php echo h(__t('settings.api_base_url_label')); ?></label>
                <div class="settings-url-input-row" data-settings-url-row>
                    <input id="api-base-url" name="api_base_url" value="<?php echo h(form_old('settings', 'api_base_url', $storedApiBaseUrl)); ?>"<?php echo form_input_class('settings', 'api_base_url'); ?> placeholder="http://virtusphere.local:8021" required>
                    <button class="button" type="submit"><?php echo h(__t('common.save')); ?></button>
                </div>
                <?php echo form_error_html('settings', 'api_base_url'); ?>
            </form>
            <?php if ($storedApiBaseUrl !== '') { ?>
                <div class="settings-reset-row">
                    <form method="post" action="settings.php">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="clear_api">
                        <button class="button button-secondary" type="submit" data-confirm="<?php echo h(__t('settings.api_base_url_reset_confirm')); ?>"><?php echo h(__t('settings.api_base_url_reset')); ?></button>
                    </form>
                    <span class="hint"><?php echo h(__t('settings.api_base_url_reset_hint')); ?></span>
                </div>
            <?php } ?>
            <details class="settings-examples" data-settings-examples>
                <summary><?php echo h(__t('settings.api_base_url_examples_summary')); ?></summary>
                <ul>
                    <li><?php echo h(__t('settings.same_host_hint')); ?> <code><?php echo h(__t('settings.same_host_example')); ?></code></li>
                    <li><?php echo h(__t('settings.other_host_hint')); ?> <code><?php echo h(__t('settings.other_host_example')); ?></code></li>
                    <li><?php echo h(__t('settings.test_hint')); ?> <code><?php echo h(__t('settings.test_command')); ?></code></li>
                </ul>
            </details>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('settings.runtime_title')); ?></h2>
            <div class="runtime-grid" data-api-runtime data-api-source="<?php echo h($apiBaseUrlSource); ?>">
                <article class="runtime-fact">
                    <div class="runtime-fact-head">
                        <h3><?php echo h(__t('settings.effective_api_url')); ?></h3>
                        <span class="badge <?php echo h($apiBaseUrlSourceBadge); ?>"><?php echo h($apiBaseUrlSourceLabel); ?></span>
                    </div>
                    <?php if ($effectiveApiBaseUrlError === '') { ?>
                        <code class="runtime-value" data-effective-api-url><?php echo h($effectiveApiBaseUrl); ?></code>
                    <?php } else { ?>
                        <div class="alert alert-warning"><?php echo h($effectiveApiBaseUrlError); ?></div>
                    <?php } ?>
                </article>
                <article class="runtime-fact">
                    <div class="runtime-fact-head">
                        <h3><?php echo h(__t('settings.ansible_access')); ?></h3>
                        <span class="badge badge-info"><?php echo h(__t('settings.ansible_access_per_job')); ?></span>
                    </div>
                    <span class="runtime-value"><?php echo h(__t('settings.ansible_access_value')); ?></span>
                    <p class="muted"><?php echo h(__t('settings.ansible_access_detail')); ?></p>
                    <div class="runtime-fact-links">
                        <a href="credentials.php"><?php echo h(__t('settings.manage_credentials')); ?></a>
                        <a href="deploy.php"><?php echo h(__t('settings.open_deploy')); ?></a>
                    </div>
                </article>
            </div>
        </section>
    </div>
