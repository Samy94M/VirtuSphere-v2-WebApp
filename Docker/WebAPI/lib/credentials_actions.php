<?php

declare(strict_types=1);

// The write half of portal/credentials.php: the two ESXi side effects a save
// carries and the POST dispatch behind them. The page keeps auth, RBAC and
// portal_guard_post(); everything a request CHANGES lives here, so the page
// stays a shell and the renderers next door stay read-only (ADR-0006).
require_once __DIR__ . '/repo/credentials.php';
require_once __DIR__ . '/repo/log.php';
require_once __DIR__ . '/repo/esxi_inventory.php';
require_once __DIR__ . '/esxi_inventory.php';
require_once __DIR__ . '/esxi_capabilities.php';
require_once __DIR__ . '/repo/ansible_preflight.php';
require_once __DIR__ . '/credentials_status.php';
require_once __DIR__ . '/credentials_test_message.php';
require_once __DIR__ . '/log_redaction.php';
require_once __DIR__ . '/settings_page.php';
require_once __DIR__ . '/ssh.php';
require_once __DIR__ . '/system_status.php';


/**
 * Testing an ESXi credential means pulling its inventory over the Ansible host:
 * the one path a deploy actually uses. The result is not a flash but the traffic
 * light on the System status page, which survives the redirect and every reload.
 *
 * Same call chain as saving the credential, so the deliberate single retry of an
 * auth-paused credential stays possible without weakening the lockout guard.
 *
 * @return array{0: string, 1: string, 2: array<string,mixed>} flash type, message and enqueue result
 */
function credentials_test_esxi(mysqli $db, int $credentialId, int $userId): array
{
    $credential = repo_credential($db, $credentialId);
    if ($credential === null || (string) ($credential['type'] ?? '') !== VIRTUSPHERE_CREDENTIAL_TYPE_ESXI) {
        throw new RuntimeException(__t('credentials.err_not_found'));
    }
    $strictProbe = credential_esxi_trust_mode($credential) === VIRTUSPHERE_ESXI_TRUST_LEGACY_INSECURE
        && trim((string) ($credential['esxi_certificate_pem'] ?? '')) !== '';
    repo_esxi_inventory_clear_pause($db, $credentialId);
    $result = esxi_inventory_enqueue_for_credential($db, $credentialId, $userId, $strictProbe);
    $result['strict_trust_probe'] = $strictProbe;

    if (!empty($result['enqueued'])) {
        return ['success', __t($strictProbe ? 'credentials.test_esxi_strict_queued' : 'credentials.test_esxi_queued'), $result];
    }
    if (($result['reason'] ?? '') === 'no_ansible_credential') {
        return ['warning', __t('credentials.test_esxi_no_ansible'), $result];
    }
    if (($result['reason'] ?? '') === 'ambiguous_ansible_credential') {
        return ['warning', __t('credentials.test_esxi_ambiguous_ansible'), $result];
    }
    if (($result['reason'] ?? '') === 'invalid_ansible_credential') {
        return ['warning', __t('credentials.test_esxi_invalid_ansible'), $result];
    }
    if (($result['reason'] ?? '') === 'already_pending') {
        return ['warning', __t('credentials.test_esxi_already_pending'), $result];
    }

    return ['error', __t('credentials.test_esxi_failed'), $result];
}

// After saving an ESXi credential: clear any auth pause and trigger an immediate
// inventory pull (fail-soft; a scheduling hiccup must not fail the save).
function credentials_after_esxi_save(mysqli $db, string $type, int $credentialId, int $userId): void
{
    if ($type !== VIRTUSPHERE_CREDENTIAL_TYPE_ESXI || $credentialId <= 0) {
        return;
    }
    try {
        $state = repo_esxi_inventory_state($db, $credentialId);
        $wasPaused = $state !== null && (int) $state['paused_until_credential_change'] === 1;
        repo_esxi_inventory_clear_pause($db, $credentialId);
        if ($wasPaused) {
            // The pause is what stopped every future pull; its end deserves the
            // same line in the log its start got.
            audit_event($db, VIRTUSPHERE_AUDIT_EVENT_CREDENTIAL_INVENTORY_AUTOMATION, 'credential', $credentialId, VIRTUSPHERE_AUDIT_RESULT_RECOVERED, [
                'action' => 'resumed',
                'reason' => 'credential saved',
            ], $userId);
        }
        esxi_inventory_enqueue_for_credential($db, $credentialId, $userId);
    } catch (Throwable $exception) {
        error_log('[credentials] ESXi inventory pull enqueue failed: ' . virtusphere_redact_log_text($exception->getMessage()));
    }
}

/**
 * The POST dispatch of the credentials page.
 *
 * It returns the page to redirect to instead of redirecting itself: the CSRF
 * guard and the redirect stay visible in the page, so a reader of the entry
 * point still sees the whole request shape, and a test can drive the dispatch
 * without a terminating call.
 *
 * @param array<string,mixed> $user
 * @return string the portal page this request redirects to
 */
function credentials_handle_post(mysqli $connection, array $user): string
{
    $redirect = 'credentials.php';

    try {
        $action = request_string($_POST, 'action');
        if (!in_array($action, ['create', 'update', 'delete', 'test', 'activate_strict', 'use_legacy'], true)) {
            http_response_code(400);
            echo h(__t('common.unknown_action'));
            exit;
        }
        $id = request_int($_POST, 'credential_id');
        $payload = [
            'type' => $_POST['type'] ?? '',
            'name' => $_POST['name'] ?? '',
            'host' => $_POST['host'] ?? '',
            'port' => $_POST['port'] ?? null,
            'username' => $_POST['username'] ?? '',
            'esxi_cert_kind' => $_POST['esxi_cert_kind'] ?? '',
            'esxi_certificate_pem' => $_POST['esxi_certificate_pem'] ?? '',
        ];
        $secret = request_string($_POST, 'secret');

        if ($action === 'create') {
            $createdId = repo_create_credential($connection, $payload, $secret, (int) $user['id']);
            audit_event($connection, VIRTUSPHERE_AUDIT_EVENT_CREDENTIAL_CHANGED, 'credential', $createdId, VIRTUSPHERE_AUDIT_RESULT_SUCCESS, [
                'action' => 'created',
            ], (int) $user['id']);
            flash_set('success', __t('credentials.flash_created'));
            credentials_after_esxi_save($connection, (string) $payload['type'], $createdId, (int) $user['id']);
        } elseif ($action === 'update') {
            // Pre-update row for the diff; the secret is never read back here and a
            // rotation is reported as "secret: changed", not with its value.
            $before = repo_credential($connection, $id, true) ?? [];
            repo_update_credential($connection, $id, $payload, $secret !== '' ? $secret : null);
            // Certificate material is public, not a secret, but a PEM block is
            // still bulk configuration data that must not flood the audit log.
            $credentialDiff = audit_change_summary($before, $payload, ['esxi_certificate_pem']);
            if ($secret !== '') {
                $credentialDiff = audit_join_summary(array_filter([$credentialDiff, 'credential material: changed']));
            }
            // The stored preflight result proved the OLD host/account; an edit
            // invalidates it. ESXi gets a fresh pull below, Ansible honestly
            // drops back to "not tested" until someone clicks Test again. The
            // reset rides the update's own audit line rather than adding a
            // second entry for a deterministic consequence.
            if (repo_ansible_preflight_clear($connection, $id)) {
                $credentialDiff = audit_join_summary(array_filter([$credentialDiff, 'ansible preflight state: reset']));
            }
            if ((string) ($before['type'] ?? '') === VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE
                && (string) $payload['type'] !== VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE
                && esxi_inventory_clear_ansible_selection_if_matches($connection, $id)
            ) {
                $credentialDiff = audit_join_summary(array_filter([$credentialDiff, 'inventory ansible selection: cleared']));
            }
            audit_event($connection, VIRTUSPHERE_AUDIT_EVENT_CREDENTIAL_CHANGED, 'credential', $id, VIRTUSPHERE_AUDIT_RESULT_SUCCESS, [
                'action' => 'updated',
                'changes' => $credentialDiff === '' ? 'no field changes' : $credentialDiff,
            ], (int) $user['id']);
            flash_set('success', __t('credentials.flash_updated'));
            credentials_after_esxi_save($connection, (string) $payload['type'], $id, (int) $user['id']);
        } elseif ($action === 'activate_strict') {
            repo_activate_esxi_strict_trust($connection, $id);
            audit_event($connection, VIRTUSPHERE_AUDIT_EVENT_CREDENTIAL_CHANGED, 'credential', $id, VIRTUSPHERE_AUDIT_RESULT_SUCCESS, [
                'action' => 'trust_mode_changed',
                'trust_mode' => 'strict',
            ], (int) $user['id']);
            flash_set('success', __t('credentials.flash_strict_activated'));
        } elseif ($action === 'use_legacy') {
            repo_activate_esxi_legacy_trust($connection, $id);
            audit_event($connection, VIRTUSPHERE_AUDIT_EVENT_CREDENTIAL_CHANGED, 'credential', $id, VIRTUSPHERE_AUDIT_RESULT_SUCCESS, [
                'action' => 'trust_mode_changed',
                'trust_mode' => 'legacy_insecure',
            ], (int) $user['id']);
            flash_set('warning', __t('credentials.flash_legacy_activated'));
        } elseif ($action === 'delete') {
            $before = repo_credential($connection, $id) ?? [];
            repo_delete_credential($connection, $id);
            $selectionCleared = (string) ($before['type'] ?? '') === VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE
                && esxi_inventory_clear_ansible_selection_if_matches($connection, $id);
            audit_event($connection, VIRTUSPHERE_AUDIT_EVENT_CREDENTIAL_CHANGED, 'credential', $id, VIRTUSPHERE_AUDIT_RESULT_SUCCESS, [
                'action' => 'deleted',
                'selection_cleared' => $selectionCleared,
            ], (int) $user['id']);
            flash_set('success', __t('credentials.flash_deleted'));
        } elseif ($action === 'test') {
            $credential = repo_credential($connection, $id, true);
            if ($credential === null) {
                throw new RuntimeException(__t('credentials.err_not_found'));
            }
            if ((string) ($credential['type'] ?? '') === VIRTUSPHERE_CREDENTIAL_TYPE_ESXI) {
                // Asynchronous by nature: the pull is a queued job on the Ansible
                // host, so the outcome lands in the traffic light, not in a flash.
                [$flashType, $flashMessage, $enqueue] = credentials_test_esxi($connection, $id, (int) $user['id']);
                $inventoryContext = ['reason' => (string) ($enqueue['reason'] ?? 'queued')];
                if (isset($enqueue['job_id'])) {
                    $inventoryContext['job_id'] = (int) $enqueue['job_id'];
                }
                audit_event($connection, VIRTUSPHERE_AUDIT_EVENT_DEPLOY_INVENTORY_REQUESTED, 'credential', $id, VIRTUSPHERE_AUDIT_RESULT_SUCCESS, $inventoryContext, (int) $user['id']);
                $actionUrl = in_array(($enqueue['reason'] ?? ''), ['ambiguous_ansible_credential', 'invalid_ansible_credential', 'no_ansible_credential'], true)
                    ? settings_url(VIRTUSPHERE_SETTINGS_TAB_CATALOG)
                    : system_status_url('credential-' . $id, ['inventory' => $id]);
                flash_set($flashType, $flashMessage, '', [
                    'url' => $actionUrl,
                    'label' => __t('credentials.test_esxi_action'),
                ]);
            } else {
                // The System-status card reuses this exact handler rather than
                // growing a second preflight implementation. The return token is
                // a closed value, never a caller-provided URL (open redirects are
                // impossible), and only the Ansible branch accepts it. Resolve it
                // before the checks so validation and runtime errors return to the
                // page that initiated the test as well.
                $returnToAnsibleStatus = request_string($_POST, 'return_to') === 'ansible_status';
                if ($returnToAnsibleStatus) {
                    // Land at the page top so the one-shot result/technical detail
                    // is visible. The flash action below leads back to this row.
                    $redirect = 'system_status.php';
                }
                // The preflight also probes the portal return route, so it needs
                // the configured API base URL (empty is fine: that check is then
                // skipped, the tooling checks still run). The resolver THROWS when
                // no URL is configured (that is correct for the deploy path), but
                // a test must still run its tooling checks, so a missing URL just
                // disables the portal probe here rather than aborting the test.
                try {
                    $apiBaseUrl = ansible_resolve_api_base_url($connection);
                } catch (Throwable $exception) {
                    $apiBaseUrl = '';
                }
                $result = credential_test_connection($credential, repo_credential_secret($connection, $id), $apiBaseUrl);
                // Persist so the credential row and the System status page can show
                // a badge instead of only this one-shot flash. An SFTP failure has
                // no preflight marker, so its code doubles as the component name;
                // the system-status detail then says what broke instead of a dash.
                // The allowlist verdict rides the same slot: it is the check that
                // raised the warning, not a broken component.
                $isAllowlistWarning = credentials_test_is_allowlist_warning($result);
                $failedComponent = ($result['code'] === VIRTUSPHERE_CREDENTIAL_TEST_SFTP || $isAllowlistWarning)
                    ? $result['code']
                    : (string) ($result['context']['component'] ?? '');
                $preflightStatus = VIRTUSPHERE_ANSIBLE_PREFLIGHT_STATUS_FAILED;
                if ($result['ok']) {
                    $preflightStatus = $isAllowlistWarning
                        ? VIRTUSPHERE_ANSIBLE_PREFLIGHT_STATUS_WARNING
                        : VIRTUSPHERE_ANSIBLE_PREFLIGHT_STATUS_OK;
                }
                repo_ansible_preflight_record($connection, $id, $preflightStatus, $failedComponent);
                $detail = $result['ok'] ? '' : (string) $result['detail'];
                // The audit line names the failed component too ("preflight:
                // pyvmomi"), so the trail answers WHAT broke without the flash.
                $warnedIp = trim((string) ($result['context']['ip'] ?? ''));
                if ($isAllowlistWarning) {
                    $auditOutcome = 'ok with warning (allowlist' . ($warnedIp !== '' ? ': ' . $warnedIp : '') . ')';
                } elseif ($result['ok']) {
                    $auditOutcome = 'ok';
                } else {
                    $auditOutcome = 'failed (' . $result['code'] . ($failedComponent !== '' && $failedComponent !== $result['code'] ? ': ' . $failedComponent : '') . ')';
                }
                $testContext = [
                    'outcome' => (string) ($result['code'] ?? ($result['ok'] ? 'ok' : 'failed')),
                ];
                if ($failedComponent !== '') {
                    $testContext['component'] = $failedComponent;
                }
                if ($warnedIp !== '') {
                    $testContext['ip'] = $warnedIp;
                }
                $testResult = $result['ok']
                    ? ($isAllowlistWarning ? VIRTUSPHERE_AUDIT_RESULT_WARNING : VIRTUSPHERE_AUDIT_RESULT_SUCCESS)
                    : VIRTUSPHERE_AUDIT_RESULT_FAILURE;
                audit_event($connection, VIRTUSPHERE_AUDIT_EVENT_CREDENTIAL_TESTED, 'credential', $id, $testResult, $testContext, (int) $user['id']);
                $flashType = 'error';
                if ($result['ok']) {
                    $flashType = $isAllowlistWarning ? 'warning' : 'success';
                }
                // Same shape as the ESXi branch above: a result whose fix lives on
                // another page carries the way there.
                $flashAction = credentials_test_action($result);
                if ($returnToAnsibleStatus && $flashAction === null) {
                    $flashAction = [
                        'url' => system_status_url('credential-' . $id),
                        'label' => __t('credentials.test_action_system_status'),
                    ];
                }
                flash_set($flashType, credentials_test_message($result), $detail, $flashAction);
            }
        }
    } catch (ValidationException $exception) {
        $formKey = ($_POST['action'] ?? '') === 'create' ? 'create' : 'row-' . request_int($_POST, 'credential_id');
        form_remember($formKey, $_POST, $exception->errors());
        flash_set('error', portal_error_message($exception));
    } catch (Throwable $exception) {
        flash_set('error', portal_error_message($exception));
    }

    return $redirect;
}
