<?php

declare(strict_types=1);

require_once __DIR__ . '/audit_vocabulary.php';
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/directory_constants.php';
require_once __DIR__ . '/inventory_error_constants.php';
require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/remote_execution_constants.php';
require_once __DIR__ . '/mac_import_constants.php';
require_once __DIR__ . '/ssh.php';
require_once __DIR__ . '/deploy_create_release.php';
require_once __DIR__ . '/repo/deploy_supervisor_contract.php';
require_once __DIR__ . '/repo/deploy_job_vm_states.php';

/**
 * Every closed list a presenter field shows, by field (words without an owner of
 * their own: lib/audit_vocabulary.php). The field is the <field> of the catalog key;
 * AuditCodeVocabularyContractTest demands a label in both locales for every word.
 * Kept apart from lib/audit_vocabulary.php because it loads every list owner, which
 * a page that only renders a row must not pull in.
 *
 * @return array<string, list<string>>
 */
function audit_code_vocabularies(): array
{
    $lists = [
        'auth_source' => VIRTUSPHERE_AUTH_SOURCES,
        'login_reason' => [VIRTUSPHERE_AUDIT_LOGIN_REASON_INVALID_CREDENTIALS, VIRTUSPHERE_AUDIT_LOGIN_REASON_ACCOUNT_LOCKED, VIRTUSPHERE_AUDIT_LOGIN_REASON_ACCOUNT_DEACTIVATED],
        'session_reason' => audit_constants_with_prefix('VIRTUSPHERE_AUDIT_SESSION_REASON_'),
        'password_reason' => [VIRTUSPHERE_AUDIT_PASSWORD_REASON_CURRENT_WRONG],
        'directory_outcome' => VIRTUSPHERE_DIRECTORY_OUTCOMES,
        'credential_test_outcome' => [
            ...audit_constants_with_prefix('VIRTUSPHERE_CREDENTIAL_TEST_'),
            ...VIRTUSPHERE_INVENTORY_ERROR_CATEGORIES,
            VIRTUSPHERE_AUDIT_CREDENTIAL_TEST_DISCARDED,
        ],
        'inventory_automation_reason' => audit_constants_with_prefix('VIRTUSPHERE_AUDIT_INVENTORY_AUTOMATION_REASON_'),
        'inventory_request_reason' => VIRTUSPHERE_AUDIT_INVENTORY_REQUEST_REASONS,
        'convergence_reason' => [VIRTUSPHERE_AUDIT_CONVERGENCE_REASON_STUCK],
        'progress_kind' => audit_constants_with_prefix('VIRTUSPHERE_VM_PROGRESS_'),
        'rollout_effect' => audit_constants_with_prefix('VIRTUSPHERE_AUDIT_ROLLOUT_EFFECT_'),
        'deploy_mode' => [...array_keys(virtusphere_deploy_mode_labels()), VIRTUSPHERE_DEPLOY_MODE_INVENTORY],
        'deploy_status' => VIRTUSPHERE_DEPLOY_JOB_TERMINAL_STATUSES,
        'deploy_reason' => [VIRTUSPHERE_DEPLOY_TERMINAL_REASON_STALE_HEARTBEAT],
        'claim_state' => VIRTUSPHERE_DEPLOY_CLAIM_STATES,
        'resolution_code' => VIRTUSPHERE_RECOVERY_RESOLUTION_CODES,
        'create_blocker' => VIRTUSPHERE_CREATE_RELEASE_BLOCKERS,
        'supervisor_blocker' => VIRTUSPHERE_SUPERVISOR_SWITCH_BLOCKERS,
        'integration_state' => VIRTUSPHERE_AUDIT_INTEGRATION_STATES,
        'callback_reason' => virtusphere_mac_import_callback_reasons(),
        'touch_cause' => VIRTUSPHERE_DEPLOY_TOUCH_REFUSAL_CAUSES,
    ];

    return array_map(static fn (array $words): array => array_values(array_unique(array_map('strval', $words))), $lists);
}

/**
 * The string values of every defined constant whose name starts with $prefix, so a
 * new constant joins its list without anyone editing one.
 *
 * @return list<string>
 */
function audit_constants_with_prefix(string $prefix): array
{
    $values = [];
    foreach (get_defined_constants(true)['user'] ?? [] as $name => $value) {
        if (str_starts_with($name, $prefix) && is_string($value)) {
            $values[] = $value;
        }
    }

    return $values;
}
