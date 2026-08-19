<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/connection_errors.php';
require_once dirname(__DIR__, 2) . '/lib/ssh.php';

/**
 * Connection test plumbing: category classification, secret redaction and the
 * HTTP status of the final hop. Pure functions, no network / no DB.
 */
final class ConnectionErrorTest extends TestCase
{
    public function testDnsFailureIsNotReportedAsUnreachable(): void
    {
        // The exact warning that leaked into the portal flash before ADR-0014
        // mapping: it must classify as dns, not as the generic parse fallback.
        $raw = 'file_get_contents(): php_network_getaddresses: getaddrinfo for assd failed: '
            . 'No address associated with hostname';

        self::assertSame(VIRTUSPHERE_INVENTORY_ERROR_DNS, connection_error_category($raw));
        self::assertSame(
            VIRTUSPHERE_INVENTORY_ERROR_DNS,
            connection_error_category('php_network_getaddresses: getaddrinfo for assd failed: Name or service not known')
        );
    }

    public function testTransportCategories(): void
    {
        self::assertSame(VIRTUSPHERE_INVENTORY_ERROR_UNREACHABLE, connection_error_category('Connection refused'));
        self::assertSame(VIRTUSPHERE_INVENTORY_ERROR_UNREACHABLE, connection_error_category('stream_socket_client(): connection timed out'));
        self::assertSame(VIRTUSPHERE_INVENTORY_ERROR_CERTIFICATE, connection_error_category('SSL operation failed: certificate verify failed'));
        self::assertSame(VIRTUSPHERE_INVENTORY_ERROR_TLS, connection_error_category('SSL handshake failed: wrong version number'));
        self::assertSame(VIRTUSPHERE_INVENTORY_ERROR_AUTH, connection_error_category('Authentication failed for user root'));
        self::assertSame(VIRTUSPHERE_INVENTORY_ERROR_AUTHZ, connection_error_category('The session is not authorized to perform this operation'));
    }

    public function testUnclassifiedTextFallsBackToParse(): void
    {
        self::assertSame(VIRTUSPHERE_INVENTORY_ERROR_PARSE, connection_error_category('something entirely unexpected'));
    }

    /**
     * Etappe 7: ansible_connection_error_category() qualifies every generic
     * finding connection_error_category() can return as Ansible-host-
     * originated. Proven separately from the generic classification above,
     * per the exact table in section 6 of the masterplan: dns/unreachable/
     * auth/authz map one-to-one, certificate/tls/parse all collapse to the
     * one "other Ansible transport problem" code.
     */
    public function testAnsibleConnectionErrorCategoryQualifiesEveryGenericFinding(): void
    {
        self::assertSame(
            VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_DNS,
            ansible_connection_error_category(new RuntimeException('getaddrinfo failed'))
        );
        self::assertSame(
            VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_UNREACHABLE,
            ansible_connection_error_category(new RuntimeException('Connection refused'))
        );
        self::assertSame(
            VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_AUTH,
            ansible_connection_error_category(new RuntimeException('Authentication failed for user root'))
        );
        self::assertSame(
            VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_AUTHZ,
            ansible_connection_error_category(new RuntimeException('The session is not authorized to perform this operation'))
        );
        self::assertSame(
            VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_TRANSPORT,
            ansible_connection_error_category(new RuntimeException('SSL operation failed: certificate verify failed'))
        );
        self::assertSame(
            VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_TRANSPORT,
            ansible_connection_error_category(new RuntimeException('SSL handshake failed: wrong version number'))
        );
        self::assertSame(
            VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_TRANSPORT,
            ansible_connection_error_category(new RuntimeException('something entirely unexpected'))
        );
    }

    /** Etappe 7: exact transport types win over what their own text says. */
    public function testAnsibleConnectionErrorCategoryChecksTypeBeforeText(): void
    {
        self::assertSame(
            VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_TIMEOUT,
            ansible_connection_error_category(new SshTransportBudgetExceeded('permission denied'))
        );
        self::assertSame(
            VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_SFTP,
            ansible_connection_error_category(new SftpTransportFailed('authentication failed'))
        );
    }

    /** Every ansible_* code inventory_error_is_ansible() recognizes pauses nothing but auth. */
    public function testAllAnsibleQualifiedCodesAreRecognizedAndNeverPauseACredential(): void
    {
        foreach ([
            VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_DNS,
            VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_UNREACHABLE,
            VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_AUTH,
            VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_AUTHZ,
            VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_TIMEOUT,
            VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_SFTP,
            VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_TRANSPORT,
        ] as $category) {
            self::assertTrue(inventory_error_is_ansible($category), $category . ' must be recognized as Ansible-origin.');
            self::assertFalse(inventory_error_pauses_credential($category), $category . ' must never pause an ESXi credential.');
        }
    }

    public function testDetailRedactsSecretAndBasicAuthHeader(): void
    {
        $detail = connection_error_detail('GET failed, Authorization: Basic cm9vdDpodW50ZXIy sent with hunter2', 'hunter2');

        self::assertStringNotContainsString('hunter2', $detail);
        self::assertStringNotContainsString('cm9vdDpodW50ZXIy', $detail);
        self::assertStringContainsString('***', $detail);
    }

    public function testDetailKeepsShortSecretsIntactToAvoidShreddingTheMessage(): void
    {
        // A 3 character secret would match half the message; redaction is skipped.
        self::assertSame('connection to abc failed', connection_error_detail('connection to abc failed', 'abc'));
    }

    public function testDetailCollapsesWhitespaceAndTruncates(): void
    {
        self::assertSame('a b c', connection_error_detail("a\n  b\tc "));
        self::assertSame(
            VIRTUSPHERE_CONNECTION_DETAIL_MAX,
            strlen(connection_error_detail(str_repeat('x', VIRTUSPHERE_CONNECTION_DETAIL_MAX + 50)))
        );
    }

    /**
     * PHP never speaks to ESXi (ADR-0023 amendment 3). The old REST probe tested
     * a transport production does not use and reported `tls` / HTTP 404 for
     * perfectly good credentials; the credentials page enqueues an inventory pull
     * instead. Nothing may quietly reintroduce a direct HTTP call.
     */
    public function testEsxiHasNoSynchronousConnectionTest(): void
    {
        self::assertFalse(function_exists('credential_test_esxi'));
        self::assertFalse(function_exists('credential_esxi_version_url'));

        $result = credential_test_connection(['type' => VIRTUSPHERE_CREDENTIAL_TYPE_ESXI, 'host' => 'esxi.local'], 'secret');

        self::assertFalse($result['ok']);
        self::assertSame(VIRTUSPHERE_INVENTORY_ERROR_CONFIG, $result['code']);
    }

    public function testSshLibraryDoesNotOpenHttpStreams(): void
    {
        self::assertNotSame([], VIRTUSPHERE_SSH_TRANSPORT_MODULES);
        foreach (VIRTUSPHERE_SSH_TRANSPORT_MODULES as $file) {
            $source = file_get_contents(__DIR__ . '/../../lib/' . $file);
            self::assertIsString($source);
            self::assertStringNotContainsString('file_get_contents', $source, $file);
            self::assertStringNotContainsString('stream_context_create', $source, $file);
            self::assertStringNotContainsString('/rest/appliance', $source, $file);
        }
    }

    public function testCredentialTestUsesExactTransportTypesNotMatchingText(): void
    {
        $budgetText = 'Remote command produced no output for 30 seconds (idle timeout).';
        self::assertSame(
            VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_TIMEOUT,
            credential_test_ssh_failure(new SshTransportBudgetExceeded($budgetText), 'secret')['code']
        );
        self::assertSame(
            VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_UNREACHABLE,
            credential_test_ssh_failure(new RuntimeException($budgetText), 'secret')['code']
        );
        self::assertSame(
            VIRTUSPHERE_INVENTORY_ERROR_CONFIG,
            credential_test_sftp_failure(new SshTransportConfigurationException('missing local directory'), 'secret')['code']
        );
        self::assertSame(
            VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_TIMEOUT,
            credential_test_sftp_failure(new SshTransportBudgetExceeded('SFTP probe total budget'), 'secret')['code']
        );
        self::assertSame(
            VIRTUSPHERE_CREDENTIAL_TEST_SFTP,
            credential_test_sftp_failure(new RuntimeException('SFTP probe total budget'), 'secret')['code']
        );
    }

    /**
     * Etappe 8, Befund 4: the shared classifier carries the local type itself.
     *
     * Both of today's callers used to check it before calling, so the function
     * answered `ansible_transport` for a purely local cause - missing
     * phpseclib, an empty mandatory field, an unreadable work directory - and
     * blamed the Ansible host for our own misconfiguration. A third caller was
     * one forgotten pre-check away, and Etappe 8 wires exactly such callers.
     */
    public function testTheSharedClassifierCallsALocalMisconfigurationOurs(): void
    {
        self::assertSame(
            VIRTUSPHERE_INVENTORY_ERROR_CONFIG,
            ansible_connection_error_category(new SshTransportConfigurationException('phpseclib SFTP is not available.'))
        );
        // And the caller that no longer pre-checks still answers the same.
        self::assertSame(
            VIRTUSPHERE_INVENTORY_ERROR_CONFIG,
            credential_test_ssh_failure(new SshTransportConfigurationException('Host and username are required.'), 'secret')['code']
        );
    }

    /**
     * Etappe 8, Befund 6: the SFTP probe keeps its own step sentence.
     *
     * `credentials.test_err_sftp` says that login and toolchain are already
     * fine and points at the SFTP subsystem and /tmp; the generic
     * `ansible_sftp` sentence knows none of that. So the probe owns its own
     * failure and any untyped surprise from inside it, and delegates every
     * other transport type - without naming a single category twice.
     */
    public function testTheSftpProbeOwnsItsOwnFailureButNoOtherMapping(): void
    {
        self::assertSame(
            VIRTUSPHERE_CREDENTIAL_TEST_SFTP,
            credential_test_sftp_failure(new SftpTransportFailed('permission denied'), 'secret')['code']
        );
        $source = (string) file_get_contents(__DIR__ . '/../../lib/ssh.php');
        $start = strpos($source, "\nfunction credential_test_sftp_failure(");
        self::assertNotFalse($start, 'credential_test_sftp_failure() not found.');
        $end = strpos($source, "\n}", $start);
        self::assertNotFalse($end);
        $body = substr($source, $start, $end - $start);
        foreach ([
            'VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_TIMEOUT',
            'VIRTUSPHERE_INVENTORY_ERROR_CONFIG',
            'VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_SFTP',
        ] as $category) {
            self::assertStringNotContainsString(
                $category,
                $body,
                'credential_test_sftp_failure() names a category the shared mapping already owns. '
                . 'It may decide WHICH failures it owns, never what they are called.'
            );
        }
    }

    public function testDirectFalseLoginUsesTheAnsibleAuthOriginCode(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../lib/ssh.php');
        self::assertMatchesRegularExpression(
            '/if \(\$ssh->login\(\$username, \$secret\)\).*?VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_AUTH/s',
            $source
        );
        self::assertStringNotContainsString(
            "return credential_test_result(false, VIRTUSPHERE_INVENTORY_ERROR_AUTH, 'SSH login rejected",
            $source
        );
    }

    public function testFailureResultsNeverCarryAReadyMadeMessage(): void
    {
        // portal.md: the portal localizes 'code'; raw text stays in 'detail'.
        $result = credential_test_result(false, VIRTUSPHERE_INVENTORY_ERROR_HTTP, 'raw text', ['status' => 500]);

        self::assertArrayNotHasKey('message', $result);
        self::assertFalse($result['ok']);
        self::assertSame('raw text', $result['detail']);
    }
}
