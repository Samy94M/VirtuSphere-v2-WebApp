<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/repo/credentials.php';
require_once __DIR__ . '/../lib/repo/log.php';
require_once __DIR__ . '/../lib/repo/esxi_inventory.php';
require_once __DIR__ . '/../lib/esxi_inventory.php';
require_once __DIR__ . '/../lib/esxi_capabilities.php';
require_once __DIR__ . '/../lib/repo/ansible_preflight.php';
require_once __DIR__ . '/../lib/credentials_status.php';
require_once __DIR__ . '/../lib/credentials_test_message.php';
require_once __DIR__ . '/../lib/ssh.php';
require_once __DIR__ . '/../lib/system_status.php';
// The cadence line needs to know whether the deploy worker is alive; that answer
// lives with the other health derivations so both pages read one of them.
require_once __DIR__ . '/../lib/integration_health.php';

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
    repo_esxi_inventory_clear_pause($db, $credentialId);
    $result = esxi_inventory_enqueue_for_credential($db, $credentialId, $userId);

    if (!empty($result['enqueued'])) {
        return ['success', __t('credentials.test_esxi_queued'), $result];
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
            audit($db, VIRTUSPHERE_LOG_CATEGORY_CREDENTIALS, 'esxi inventory auto-pull resumed for credential id ' . $credentialId . ' after the credential was saved', $userId);
        }
        esxi_inventory_enqueue_for_credential($db, $credentialId, $userId);
    } catch (Throwable $exception) {
        error_log('[credentials] ESXi inventory pull enqueue failed: ' . $exception->getMessage());
    }
}

/** @var mysqli $connection Provided by bootstrap.php. */
$user = portal_require_user($connection);
if (!can('credentials.manage', $user)) {
    portal_forbid($connection, $user, 'credentials.manage');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    portal_guard_post($connection, $user);

    try {
        $action = request_string($_POST, 'action');
        if (!in_array($action, ['create', 'update', 'delete', 'test'], true)) {
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
        ];
        $secret = request_string($_POST, 'secret');

        if ($action === 'create') {
            $createdId = repo_create_credential($connection, $payload, $secret, (int) $user['id']);
            audit($connection, VIRTUSPHERE_LOG_CATEGORY_CREDENTIALS, 'created credential id ' . $createdId, (int) $user['id']);
            flash_set('success', __t('credentials.flash_created'));
            credentials_after_esxi_save($connection, (string) $payload['type'], $createdId, (int) $user['id']);
        } elseif ($action === 'update') {
            // Pre-update row for the diff; the secret is never read back here and a
            // rotation is reported as "secret: changed", not with its value.
            $before = repo_credential($connection, $id, true) ?? [];
            repo_update_credential($connection, $id, $payload, $secret !== '' ? $secret : null);
            $credentialDiff = audit_change_summary($before, $payload, []);
            if ($secret !== '') {
                $credentialDiff = audit_join_summary(array_filter([$credentialDiff, 'secret: changed']));
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
            audit($connection, VIRTUSPHERE_LOG_CATEGORY_CREDENTIALS, 'updated credential id ' . $id . audit_change_note($credentialDiff), (int) $user['id']);
            flash_set('success', __t('credentials.flash_updated'));
            credentials_after_esxi_save($connection, (string) $payload['type'], $id, (int) $user['id']);
        } elseif ($action === 'delete') {
            $before = repo_credential($connection, $id) ?? [];
            repo_delete_credential($connection, $id);
            $selectionCleared = (string) ($before['type'] ?? '') === VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE
                && esxi_inventory_clear_ansible_selection_if_matches($connection, $id);
            audit($connection, VIRTUSPHERE_LOG_CATEGORY_CREDENTIALS, 'deleted credential id ' . $id . ($selectionCleared ? '; inventory ansible selection cleared' : ''), (int) $user['id']);
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
                audit($connection, VIRTUSPHERE_LOG_CATEGORY_DEPLOY, 'requested ESXi inventory pull for credential id ' . $id . ' (' . ($enqueue['reason'] ?? 'queued') . ')' . (isset($enqueue['job_id']) ? '; job id ' . $enqueue['job_id'] : ''), (int) $user['id']);
                $actionUrl = in_array(($enqueue['reason'] ?? ''), ['ambiguous_ansible_credential', 'invalid_ansible_credential', 'no_ansible_credential'], true)
                    ? settings_url(VIRTUSPHERE_SETTINGS_TAB_CATALOG)
                    : system_status_url('credential-' . $id, ['inventory' => $id]);
                flash_set($flashType, $flashMessage, '', [
                    'url' => $actionUrl,
                    'label' => __t('credentials.test_esxi_action'),
                ]);
            } else {
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
                audit(
                    $connection,
                    VIRTUSPHERE_LOG_CATEGORY_CREDENTIALS,
                    'tested credential id ' . $id . ': ' . $auditOutcome,
                    (int) $user['id']
                );
                $flashType = 'error';
                if ($result['ok']) {
                    $flashType = $isAllowlistWarning ? 'warning' : 'success';
                }
                // Same shape as the ESXi branch above: a result whose fix lives on
                // another page carries the way there.
                flash_set($flashType, credentials_test_message($result), $detail, credentials_test_action($result));
            }
        }
    } catch (ValidationException $exception) {
        $formKey = ($_POST['action'] ?? '') === 'create' ? 'create' : 'row-' . request_int($_POST, 'credential_id');
        form_remember($formKey, $_POST, $exception->errors());
        flash_set('error', portal_error_message($exception));
    } catch (Throwable $exception) {
        flash_set('error', portal_error_message($exception));
    }

    redirect_to('credentials.php');
}

$credentials = repo_credentials($connection);
// Pointer badges only: the credentials page answers "is this account healthy",
// the System status page answers "what does this host report". One page per
// question (ADR-0023), so the detail lives there and this links to it.
$inventoryIntervalHours = esxi_inventory_interval_hours($connection);
// Global, so it is resolved once: without a usable Ansible host the scheduler
// has nothing to run the pull over and enqueues nothing for any credential.
$ansibleHostSelected = esxi_inventory_ansible_resolution($connection)['credential_id'] !== null;
// The cadence line on this page and the one on System status must name the same
// blocker, so both read the same fourth input: without a live deploy worker the
// inventory pull is enqueued and never executed.
$deployWorkerAlive = integration_deploy_worker_alive_now($connection);
// One clock for the whole table, like the System status snapshot: both badges
// are age-derived, so two rows recorded at the same instant must not land on
// opposite sides of a threshold because their time() calls differed by a second.
$renderedAt = time();
$esxiStates = repo_esxi_inventory_states($connection);
$ansiblePreflightStates = repo_ansible_preflight_states($connection);
layout_header(__t('credentials.title'), $user, 'credentials', 'credentials');
?>
<div class="stack">
    <section class="panel">
        <h2><?php echo h(__t('credentials.create_heading')); ?></h2>
        <p class="muted"><?php echo h(__t('credentials.scope_hint')); ?></p>
        <p class="muted"><?php echo h(__t('credentials.mecm_scope_hint')); ?> <a href="<?php echo h(settings_url(VIRTUSPHERE_SETTINGS_TAB_MACHINE_API)); ?>"><?php echo h(__t('credentials.mecm_scope_link')); ?></a></p>
        <p class="muted"><?php echo h(__t('credentials.ansible_scope_hint')); ?> <a href="<?php echo h(settings_url(VIRTUSPHERE_SETTINGS_TAB_DEPLOY)); ?>"><?php echo h(__t('credentials.ansible_scope_link')); ?></a></p>
        <form class="form-grid" method="post" action="credentials.php" autocomplete="off">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="create">
            <label><?php echo h(__t('credentials.label_type')); ?>
                <select name="type" required>
                    <?php $createType = form_old('create', 'type', VIRTUSPHERE_CREDENTIAL_TYPE_ESXI); ?>
                    <?php foreach (VIRTUSPHERE_CREDENTIAL_LABELS as $typeValue => $typeLabel) { ?>
                        <option value="<?php echo h($typeValue); ?>" <?php echo $createType === $typeValue ? 'selected' : ''; ?>><?php echo h($typeLabel); ?></option>
                    <?php } ?>
                </select>
                <?php echo form_error_html('create', 'type'); ?>
            </label>
            <label><?php echo h(__t('common.name')); ?><input name="name" value="<?php echo h(form_old('create', 'name')); ?>"<?php echo form_input_class('create', 'name'); ?> required><?php echo form_error_html('create', 'name'); ?></label>
            <label><?php echo h(__t('credentials.label_host')); ?><input name="host" value="<?php echo h(form_old('create', 'host')); ?>"<?php echo form_input_class('create', 'host'); ?> required placeholder="<?php echo h(__t('credentials.host_placeholder')); ?>"><?php echo form_error_html('create', 'host'); ?></label>
            <label><?php echo h(__t('credentials.label_port')); ?><input name="port" type="number" min="1" max="65535" value="<?php echo h(form_old('create', 'port')); ?>"<?php echo form_input_class('create', 'port'); ?> placeholder="<?php echo h(__t('credentials.port_placeholder')); ?>"><?php echo form_error_html('create', 'port'); ?></label>
            <label><?php echo h(__t('credentials.label_username')); ?><input name="username" value="<?php echo h(form_old('create', 'username')); ?>"<?php echo form_input_class('create', 'username'); ?> required autocomplete="off"><?php echo form_error_html('create', 'username'); ?></label>
            <label><?php echo h(__t('credentials.label_secret')); ?><input name="secret" type="password" required autocomplete="new-password"><?php echo form_error_html('create', 'secret'); ?></label>
            <div class="actions"><button class="button" type="submit"><?php echo h(__t('common.create')); ?></button></div>
        </form>
    </section>

    <section class="panel">
        <h2><?php echo h(__t('credentials.stored_heading')); ?></h2>
        <p class="muted"><?php echo h(__t('credentials.test_hint')); ?> <a href="system_status.php"><?php echo h(__t('credentials.test_hint_link')); ?></a></p>
        <p class="muted"><?php echo h(__t('credentials.test_checks')); ?></p>
        <div class="table-wrap" tabindex="0"><table>
            <thead><tr><th><?php echo h(__t('credentials.label_type')); ?></th><th><?php echo h(__t('common.name')); ?></th><th><?php echo h(__t('credentials.label_host')); ?></th><th><?php echo h(__t('credentials.label_port')); ?></th><th><?php echo h(__t('credentials.label_username')); ?></th><th><?php echo h(__t('credentials.th_status')); ?></th><th><?php echo h(__t('common.updated')); ?></th><th><?php echo h(__t('common.actions')); ?></th></tr></thead>
            <tbody>
            <?php foreach ($credentials as $row) {
                $rowId = (int) $row['id'];
                $rowKey = 'row-' . $rowId;
                $rowType = form_old($rowKey, 'type', (string) ($row['type'] ?? ''));
                $editorId = 'credential-editor-' . $rowId;
                // A failed save reopens exactly the editor that was submitted, so
                // the field errors and sticky values are visible without a click.
                $editorOpen = form_has_state($rowKey);
                $isEsxi = (string) ($row['type'] ?? '') === VIRTUSPHERE_CREDENTIAL_TYPE_ESXI;
                $isAnsible = (string) ($row['type'] ?? '') === VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE;
            ?>
                <tr>
                    <td><?php echo h(credential_type_label((string) ($row['type'] ?? ''))); ?></td>
                    <td><?php echo h((string) ($row['name'] ?? '')); ?></td>
                    <td><?php echo h((string) ($row['host'] ?? '')); ?></td>
                    <td><?php echo ($row['port'] ?? null) !== null && (string) $row['port'] !== '' ? h((string) $row['port']) : '<span class="muted">&mdash;</span>'; ?></td>
                    <td><?php echo h((string) ($row['username'] ?? '')); ?></td>
                    <td class="status-cell">
                        <?php if ($isEsxi) {
                            $esxiState = $esxiStates[$rowId] ?? null;
                            $esxiAmpel = esxi_credential_state($esxiState, $inventoryIntervalHours, $renderedAt);
                            ?>
                            <a href="<?php echo h(system_status_url('credential-' . $rowId, ['inventory' => $rowId])); ?>" title="<?php echo h(__t('credentials.esxi_state_link_title')); ?>"><?php echo esxi_state_badge($esxiAmpel); ?></a>
                            <small class="status-time"><?php echo $esxiState !== null && !empty($esxiState['last_attempt_at']) ? h(portal_format_timestamp($esxiState['last_attempt_at'])) : h(__t('credentials.status_never')); ?></small>
                            <small class="status-cadence"><?php echo h(credential_cadence_esxi($inventoryIntervalHours, $esxiState, $ansibleHostSelected)); ?></small>
                        <?php } elseif ($isAnsible) {
                            $pfState = $ansiblePreflightStates[$rowId] ?? null;
                            $pfTitle = $pfState !== null && !empty($pfState['last_checked_at'])
                                ? __t('credentials.ansible_state_link_title', ['when' => portal_format_timestamp((string) $pfState['last_checked_at'])])
                                : __t('credentials.ansible_state_untested_title');
                            ?>
                            <a href="<?php echo h(system_status_url('credential-' . $rowId)); ?>" title="<?php echo h($pfTitle); ?>"><?php echo ansible_preflight_badge($pfState, $renderedAt); ?></a>
                            <small class="status-time"><?php echo $pfState !== null && !empty($pfState['last_checked_at']) ? h(portal_format_timestamp($pfState['last_checked_at'])) : h(__t('credentials.status_never')); ?></small>
                            <small class="status-cadence"><?php echo h(credential_cadence_ansible()); ?></small>
                        <?php } else { ?>
                            <span class="muted">&mdash;</span>
                        <?php } ?>
                    </td>
                    <td class="nowrap"><?php echo h(portal_format_timestamp($row['updated_at'] ?? '')); ?></td>
                    <td class="actions">
                        <button class="button button-secondary" type="button" data-row-toggle="<?php echo h($editorId); ?>" aria-expanded="<?php echo $editorOpen ? 'true' : 'false'; ?>"><?php echo h(__t('common.edit')); ?></button>
                        <form class="inline-form" method="post" action="credentials.php">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="credential_id" value="<?php echo h((string) $rowId); ?>">
                            <?php // action in a hidden field, not on the button: the busy handler
                                  // disables the button on submit, which would drop a button-borne
                                  // name/value from the POST. ?>
                            <input type="hidden" name="action" value="test">
                            <button class="button button-secondary" type="submit" data-busy-label="<?php echo h($isEsxi ? __t('credentials.btn_inventory_busy') : __t('credentials.btn_testing')); ?>"><?php echo h($isEsxi ? __t('credentials.btn_inventory') : __t('credentials.btn_test_ansible')); ?></button>
                        </form>
                        <form class="inline-form" method="post" action="credentials.php">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="credential_id" value="<?php echo h((string) $rowId); ?>">
                            <button class="button button-danger" type="submit" name="action" value="delete" data-confirm="<?php echo h(__t('credentials.confirm_delete', ['name' => (string) ($row['name'] ?? '')])); ?>"><?php echo h(__t('common.delete')); ?></button>
                        </form>
                    </td>
                </tr>
                <tr class="row-editor" id="<?php echo h($editorId); ?>"<?php echo $editorOpen ? '' : ' hidden'; ?>>
                    <td colspan="8">
                        <form class="form-grid" method="post" action="credentials.php" autocomplete="off">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="credential_id" value="<?php echo h((string) $rowId); ?>">
                            <label><?php echo h(__t('credentials.label_type')); ?>
                                <select name="type" required>
                                    <?php foreach (VIRTUSPHERE_CREDENTIAL_LABELS as $typeValue => $typeLabel) { ?>
                                        <option value="<?php echo h($typeValue); ?>" <?php echo $rowType === $typeValue ? 'selected' : ''; ?>><?php echo h($typeLabel); ?></option>
                                    <?php } ?>
                                </select>
                                <?php echo form_error_html($rowKey, 'type'); ?>
                            </label>
                            <label><?php echo h(__t('common.name')); ?><input name="name" value="<?php echo h(form_old($rowKey, 'name', (string) ($row['name'] ?? ''))); ?>"<?php echo form_input_class($rowKey, 'name'); ?> required><?php echo form_error_html($rowKey, 'name'); ?></label>
                            <label><?php echo h(__t('credentials.label_host')); ?><input name="host" value="<?php echo h(form_old($rowKey, 'host', (string) ($row['host'] ?? ''))); ?>"<?php echo form_input_class($rowKey, 'host'); ?> required><?php echo form_error_html($rowKey, 'host'); ?></label>
                            <label><?php echo h(__t('credentials.label_port')); ?><input name="port" type="number" min="1" max="65535" value="<?php echo h(form_old($rowKey, 'port', (string) ($row['port'] ?? ''))); ?>"<?php echo form_input_class($rowKey, 'port'); ?>><?php echo form_error_html($rowKey, 'port'); ?></label>
                            <label><?php echo h(__t('credentials.label_username')); ?><input name="username" value="<?php echo h(form_old($rowKey, 'username', (string) ($row['username'] ?? ''))); ?>"<?php echo form_input_class($rowKey, 'username'); ?> required autocomplete="off"><?php echo form_error_html($rowKey, 'username'); ?></label>
                            <label><?php echo h(__t('credentials.label_new_secret')); ?><input name="secret" type="password" placeholder="<?php echo h(__t('credentials.secret_keep_placeholder')); ?>"<?php echo form_input_class($rowKey, 'secret'); ?> autocomplete="new-password"><?php echo form_error_html($rowKey, 'secret'); ?></label>
                            <div class="actions">
                                <button class="button" type="submit"><?php echo h(__t('common.save')); ?></button>
                            </div>
                        </form>
                    </td>
                </tr>
            <?php } ?>
            <?php if ($credentials === []) { ?><tr><td colspan="8"><?php echo h(__t('credentials.empty')); ?></td></tr><?php } ?>
            </tbody>
        </table></div>
    </section>
</div>
<?php layout_footer(); ?>
