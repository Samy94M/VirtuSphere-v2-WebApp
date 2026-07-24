<?php

declare(strict_types=1);

require_once __DIR__ . '/ssh.php';
require_once __DIR__ . '/connection_errors.php';

/**
 * Credentials page: turns a connection-test result into the sentence the flash
 * shows. Lives here rather than in lib/credentials_status.php, which stays free
 * of the SSH stack: the result vocabulary (`VIRTUSPHERE_CREDENTIAL_TEST_*`) is
 * owned by lib/ssh.php, and requiring that would drag the transport layer and
 * its database dependencies into a module whose unit test needs neither.
 *
 * The raw transport text never becomes the message: it travels in
 * $result['detail'] and is rendered behind the alert's details element.
 *
 * @param array{ok: bool, code: string, detail: string, context: array<string, string|int>} $result
 */
function credentials_test_message(array $result): string
{
    if ($result['ok']) {
        // Green chain with an allowlist warning: the credential works, but the
        // host's IP would be rejected by db_importMAC.php. Two keys instead of
        // an ":ip"-with-"?" sentence, because the IP is only known when the
        // legacy 403 echoed one.
        if ($result['code'] === VIRTUSPHERE_CREDENTIAL_TEST_ALLOWLIST) {
            $ip = trim((string) ($result['context']['ip'] ?? ''));

            return $ip !== ''
                ? __t('credentials.test_warn_allowlist', ['ip' => $ip])
                : __t('credentials.test_warn_allowlist_noip');
        }

        return __t('credentials.test_ok_ansible');
    }

    if ($result['code'] === VIRTUSPHERE_CREDENTIAL_TEST_SFTP) {
        return __t('credentials.test_err_sftp');
    }

    if ($result['code'] === VIRTUSPHERE_CREDENTIAL_TEST_PREFLIGHT) {
        $component = trim((string) ($result['context']['component'] ?? ''));
        // The portal-reachability probe is not a "missing tool"; it points at the
        // API base URL and the network, so it gets its own sentence.
        if ($component === VIRTUSPHERE_ANSIBLE_PREFLIGHT_PORTAL) {
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
