<?php

declare(strict_types=1);

require_once __DIR__ . '/lang.php';

/**
 * Word and fragment helpers of the audit presenter (lib/audit_presenter.php). Every word
 * of a sentence comes from the catalog through audit_t(), in the locale the caller asks
 * for, so the writers (locale en) and the logs page (the viewer locale) share one owner.
 * Split from the presenter to keep both files under the 400-line budget.
 */

/**
 * One key of the audit catalog, in one locale. A key that is missing comes back as
 * its own name, so a gap is visible rather than silently English.
 *
 * @param array<string,string|int> $replace
 */
function audit_t(string $locale, string $key, array $replace = []): string
{
    return Lang::getIn($locale, 'audit_log.' . $key, $replace);
}

/**
 * A word a writer chose (an action, a scope, an object type): its label when the
 * catalog has one, else the word exactly as it was written.
 */
function audit_token_label(string $locale, string $prefix, string $word): string
{
    $key = 'audit_log.' . $prefix . $word;
    $label = Lang::getIn($locale, $key);

    return $label === $key ? $word : $label;
}

/** @param array<string,mixed> $context */
function audit_scope_label(string $locale, array $context): string
{
    return audit_token_label($locale, 'scope_', (string) ($context['scope'] ?? 'own'));
}

function audit_bool_word(bool $enabled, string $locale = 'en'): string
{
    return audit_t($locale, $enabled ? 'bool_enabled' : 'bool_disabled');
}

/**
 * How a deploy job and a deploy group name themselves in a description.
 *
 * They differ, and the difference is not cosmetic: the job carries "id" and the
 * group does not, because that is what these lines have said since the deploy
 * queue shipped, and existing operator filters and E2E assertions match on it.
 */
function audit_deploy_object_label(string $objectType, string $id, string $locale = 'en'): string
{
    return $objectType === 'deploy_group'
        ? audit_t($locale, 'deploy_label_group', ['id' => $id])
        : audit_t($locale, 'deploy_label_job', ['id' => $id]);
}

/** @param array<string,mixed> $context */
function audit_login_description(string $id, string $result, array $context, string $locale = 'en'): string
{
    $source = (string) ($context['source'] ?? 'local');
    if ($result === VIRTUSPHERE_AUDIT_RESULT_SUCCESS) {
        return audit_t($locale, 'login_succeeded', [
            'source' => $source,
            'controller_part' => isset($context['controller_id']) ? audit_t($locale, 'login_controller', ['controller' => (string) $context['controller_id']]) : '',
        ]);
    }
    $reason = (string) ($context['reason'] ?? audit_t($locale, 'invalid_credentials'));
    $user = isset($context['username'])
        ? audit_t($locale, 'login_user_name', ['username' => (string) $context['username']])
        : ($id !== 'unknown' ? audit_t($locale, 'login_user_id', ['id' => $id]) : '');

    return audit_t($locale, 'login_rejected', ['user_part' => $user, 'reason' => $reason]);
}

/** @param array<string,mixed> $context */
function audit_session_description(array $context, string $locale = 'en'): string
{
    $reason = (string) ($context['reason'] ?? audit_t($locale, 'ended'));

    return $reason === 'expired' && isset($context['duration_seconds'])
        ? audit_t($locale, 'session_expired', ['seconds' => (string) $context['duration_seconds']])
        : audit_t($locale, 'session_ended', ['reason' => $reason]);
}

/** @param array<string,mixed> $context */
function audit_directory_controller_description(string $id, array $context, string $locale = 'en'): string
{
    $address = isset($context['host'])
        ? audit_t($locale, 'address_part', ['address' => (string) $context['host'] . ':' . (string) ($context['port'] ?? 0)])
        : '';

    return audit_t($locale, 'directory_controller_changed', [
        'action' => audit_token_label($locale, 'action_', (string) ($context['action'] ?? 'changed')),
        'id' => $id,
        'address_part' => $address,
    ]);
}

/** @param array<string,mixed> $context */
function audit_mission_transfer_description(string $id, array $context, string $locale = 'en'): string
{
    $action = audit_token_label($locale, 'action_', (string) ($context['action'] ?? 'exported'));
    if (isset($context['target_mission_id'])) {
        return audit_t($locale, 'mission_transfer_to', ['action' => $action, 'id' => $id, 'target' => (string) $context['target_mission_id']]);
    }

    return audit_t($locale, 'mission_action', ['action' => $action, 'id' => $id, 'named' => audit_description_named($context, $locale)]);
}

/** @param array<string,mixed> $context */
function audit_setting_description(string $id, array $context, string $locale = 'en'): string
{
    $setting = str_replace('_', ' ', $id);
    if (array_key_exists('enabled', $context)) {
        return audit_t($locale, 'setting_enabled', [
            'state' => audit_bool_word((bool) $context['enabled'], $locale),
            'setting' => $setting,
            'redirect_part' => !empty($context['redirect_disabled']) ? audit_t($locale, 'redirect_part') : '',
        ]);
    }
    $change = isset($context['old_value'], $context['new_value'])
        ? audit_t($locale, 'change_part', ['old_value' => (string) $context['old_value'], 'new_value' => (string) $context['new_value']])
        : '';

    return audit_t($locale, 'setting_changed', [
        'action' => audit_token_label($locale, 'action_', (string) ($context['action'] ?? 'updated')),
        'setting' => $setting,
        'change_part' => $change,
    ]);
}

/** @param array<string,mixed> $context */
function audit_inventory_refresh_description(array $context, string $locale = 'en'): string
{
    return audit_t($locale, 'inventory_refresh', [
        'targets' => audit_description_ids($context['target_ids'] ?? []),
        'jobs' => audit_description_ids($context['job_ids'] ?? []),
        'queued' => (string) ($context['queued_count'] ?? 0),
        'open' => (string) ($context['open_count'] ?? 0),
        'paused' => (string) ($context['paused_count'] ?? 0),
        'failed' => (string) ($context['failed_count'] ?? 0),
    ]);
}

/** @param array<string,mixed> $context */
function audit_logs_export_description(string $tab, array $context, string $locale = 'en'): string
{
    $message = audit_t($locale, 'logs_export', [
        'tab' => $tab,
        'rows' => (string) ($context['rows_exported'] ?? 0),
        'total' => (string) ($context['total_rows'] ?? 0),
    ]);

    return !empty($context['truncated'])
        ? $message . audit_t($locale, 'logs_export_truncated', ['limit' => (string) ($context['limit'] ?? 0)])
        : $message;
}

/** @param array<string,mixed> $context */
function audit_machine_api_denied(string $id, array $context, string $locale = 'en'): string
{
    $action = isset($context['action']) ? audit_t($locale, 'machine_api_action', ['action' => (string) $context['action']]) : '';
    $refused = audit_t($locale, 'machine_api_refused', ['endpoint' => $id, 'action_part' => $action]);

    return $refused . audit_t($locale, ($context['reason'] ?? '') === 'ip_role_not_permitted' ? 'machine_api_role_denied' : 'machine_api_not_listed');
}

/** @param array<string,mixed> $context */
function audit_description_reason(array $context, string $locale = 'en'): string
{
    return isset($context['reason']) && $context['reason'] !== ''
        ? audit_t($locale, 'reason_part', ['reason' => (string) $context['reason']])
        : '';
}

/** @param array<string,mixed> $context */
function audit_description_named(array $context, string $locale = 'en'): string
{
    return isset($context['name']) && $context['name'] !== ''
        ? audit_t($locale, 'named', ['name' => (string) $context['name']])
        : '';
}

/** @param array<string,mixed> $context */
function audit_description_changes(array $context, string $locale = 'en'): string
{
    if (isset($context['changes']) && $context['changes'] !== '') {
        return audit_t($locale, 'changes_part', ['changes' => (string) $context['changes']]);
    }

    // An update that changed nothing says so rather than reading as an
    // unexplained write: under optimistic locking a no-op save is a real,
    // recorded event, and silence would look like a missing diff.
    return ($context['action'] ?? '') === 'updated' ? audit_t($locale, 'no_field_changes') : '';
}

/** @param array<string,mixed> $context */
function audit_description_component(array $context, string $locale = 'en'): string
{
    return isset($context['component']) && $context['component'] !== ''
        ? audit_t($locale, 'component_part', ['component' => (string) $context['component']])
        : '';
}

/** @param list<int> $ids */
function audit_description_ids(array $ids): string
{
    return ' [' . implode(',', $ids) . ']';
}

/** @param array<string,mixed> $context */
function audit_description_items(array $context, string $locale = 'en'): string
{
    $items = is_array($context['items'] ?? null) ? $context['items'] : [];

    return $items !== []
        ? implode('; ', $items)
        : audit_t($locale, 'items_count', ['count' => (string) ($context['item_count'] ?? 0)]);
}

/** @param array<string,mixed> $context */
function audit_description_throttle(array $context, string $locale = 'en'): string
{
    $suppressed = (int) ($context['suppressed_count'] ?? 0);
    if ($suppressed <= 0) {
        return '';
    }

    return audit_t($locale, 'throttle_part', ['count' => (string) $suppressed, 'seconds' => (string) ($context['throttle_seconds'] ?? 0)]);
}
