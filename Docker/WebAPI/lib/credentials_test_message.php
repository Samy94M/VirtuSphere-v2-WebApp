<?php

declare(strict_types=1);

require_once __DIR__ . '/ssh.php';
require_once __DIR__ . '/connection_errors.php';
require_once __DIR__ . '/settings_page.php';

/**
 * A test that passed but found the host's IP missing from the machine-API
 * allowlist. It rides an ok=true result, so "ok" alone is not the question and
 * the code alone is not either.
 *
 * One predicate because three places asked it: the sentence, the link and the
 * status the page records. The recorded status is the one that matters - if the
 * three ever disagree, the flash says "warning" while the row goes green.
 *
 * @param array{ok: bool, code: string, detail: string, context: array<string, string|int>} $result
 */
function credentials_test_is_allowlist_warning(array $result): bool
{
    return $result['ok'] && $result['code'] === VIRTUSPHERE_CREDENTIAL_TEST_ALLOWLIST;
}

/**
 * The preflight step that probes the portal from the Ansible host. It is not a
 * missing tool: it points at the API base URL and the network, which is why it
 * gets its own sentence and its own link, and why the sentence and the link
 * must agree on what it is.
 *
 * @param array{ok: bool, code: string, detail: string, context: array<string, string|int>} $result
 */
function credentials_test_is_portal_probe_failure(array $result): bool
{
    return !$result['ok']
        && $result['code'] === VIRTUSPHERE_CREDENTIAL_TEST_PREFLIGHT
        && trim((string) ($result['context']['component'] ?? '')) === VIRTUSPHERE_ANSIBLE_PREFLIGHT_PORTAL;
}

/**
 * Credentials page: turns a connection-test result into the sentence the flash
 * shows. Lives here rather than in lib/credentials_status.php, which stays free
 * of the SSH stack: requiring that would drag the transport layer and its
 * database dependencies into a module whose unit test needs neither.
 *
 * A result code is the UNION of two sets, not the first one alone. The few
 * test-only codes (`VIRTUSPHERE_CREDENTIAL_TEST_*`, owned by lib/ssh.php) name
 * a step that exists only during a manual test; every other failure carries the
 * same VIRTUSPHERE_INVENTORY_ERROR_* code an inventory pull would have stored,
 * because credential_test_ssh_failure() classifies through the shared
 * ansible_connection_error_category(). The earlier note claimed the first set
 * owned the whole vocabulary, which is why the fallthrough below looks like an
 * afterthought and is in fact the common case.
 *
 * connection_error_message() therefore stays the one SSoT for that second set;
 * this function must not grow a second mapping table beside it.
 *
 * The raw transport text never becomes the message: it travels in
 * $result['detail'] and is rendered behind the alert's details element. It is
 * shown right there, because a manual test writes no job log to link to.
 *
 * @param array{ok: bool, code: string, detail: string, context: array<string, string|int>} $result
 */
function credentials_test_message(array $result): string
{
    // Green chain with an allowlist warning: the credential works, but the
    // host's IP would be rejected by db_importMAC.php. Two keys instead of
    // an ":ip"-with-"?" sentence, because the IP is only known when the
    // legacy 403 echoed one.
    if (credentials_test_is_allowlist_warning($result)) {
        $ip = trim((string) ($result['context']['ip'] ?? ''));

        return $ip !== ''
            ? __t('credentials.test_warn_allowlist', ['ip' => $ip])
            : __t('credentials.test_warn_allowlist_noip');
    }

    if ($result['ok']) {
        return __t('credentials.test_ok_ansible');
    }

    if ($result['code'] === VIRTUSPHERE_CREDENTIAL_TEST_SFTP) {
        return __t('credentials.test_err_sftp');
    }

    if ($result['code'] === VIRTUSPHERE_CREDENTIAL_TEST_PREFLIGHT) {
        $component = trim((string) ($result['context']['component'] ?? ''));
        if (credentials_test_is_portal_probe_failure($result)) {
            return __t('credentials.test_err_portal');
        }
        // A named component (pyvmomi, community.vmware, ...) points the operator
        // straight at what to install; an unnamed failure keeps the exit code.
        if ($component !== '') {
            return __t('credentials.test_err_preflight_component', $result['context']);
        }

        return __t('credentials.test_err_preflight', $result['context']);
    }

    return connection_error_message($result['code'], $result['context']);
}

/**
 * The flash action for a test result whose fix lives on another page.
 *
 * Two of these sentences end in an instruction ("API-Basis-URL unter
 * Einstellungen pruefen", "Die IP unter Einstellungen, Machine-API freigeben")
 * while the ESXi branch of the same handler already ships a link, so the two
 * halves of one page answered the same question differently. Both targets are
 * settings.php tabs, so the fragment is mandatory: without it the page falls
 * back to its first tab.
 *
 * Everything else returns null: a missing pyvmomi is fixed on the Ansible host,
 * not in the portal, and a link would name a page that cannot help.
 *
 * @param array{ok: bool, code: string, detail: string, context: array<string, string|int>} $result
 * @return array{url: string, label: string}|null
 */
function credentials_test_action(array $result): ?array
{
    if (credentials_test_is_allowlist_warning($result)) {
        return [
            'url' => settings_url(VIRTUSPHERE_SETTINGS_TAB_MACHINE_API),
            'label' => __t('credentials.test_action_allowlist'),
        ];
    }

    if (credentials_test_is_portal_probe_failure($result)) {
        return [
            'url' => settings_url(VIRTUSPHERE_SETTINGS_TAB_DEPLOY),
            'label' => __t('credentials.test_action_api_base_url'),
        ];
    }

    return null;
}
