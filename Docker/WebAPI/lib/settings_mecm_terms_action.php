<?php

declare(strict_types=1);

require_once __DIR__ . '/mecm_terms.php';
require_once __DIR__ . '/repo/settings.php';

/**
 * Saves the MECM console language of the settings page (PR-R3M). Kept beside
 * the dispatcher like the allowlist actions; settings_handle_post() stays the
 * owner of the action-to-tab map, and the page shell already checked
 * system.config and CSRF. Display-only: the value only picks the term set.
 *
 * @param array<string,mixed> $user
 */
function settings_save_mecm_console_language(mysqli $connection, array $user): void
{
    $language = request_trimmed($_POST, 'mecm_console_language');
    if (!mecm_console_language_is_valid($language)) {
        $message = __t('settings.mecm_console_language_invalid');
        form_remember('mecm_console_language', $_POST, ['mecm_console_language' => $message]);
        flash_set('error', $message);

        return;
    }
    try {
        $previous = mecm_console_language();
        repo_transaction($connection, static function () use ($connection, $language, $previous, $user): void {
            repo_set_setting($connection, VIRTUSPHERE_SETTING_MECM_CONSOLE_LANGUAGE, $language);
            audit_event_required($connection, VIRTUSPHERE_AUDIT_EVENT_SETTINGS_CHANGED, 'setting',
                VIRTUSPHERE_SETTING_MECM_CONSOLE_LANGUAGE, VIRTUSPHERE_AUDIT_RESULT_SUCCESS, [
                    'action' => 'updated',
                    'old_value' => $previous,
                    'new_value' => $language,
                ], (int) $user['id']);
        });
        flash_set('success', __t('settings.mecm_console_language_saved'));
    } catch (Throwable $exception) {
        flash_set('error', portal_error_message($exception));
    }
}
