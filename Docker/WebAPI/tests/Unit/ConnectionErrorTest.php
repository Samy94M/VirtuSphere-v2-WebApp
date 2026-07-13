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
        self::assertSame(VIRTUSPHERE_INVENTORY_ERROR_TLS, connection_error_category('SSL operation failed: certificate verify failed'));
        self::assertSame(VIRTUSPHERE_INVENTORY_ERROR_AUTH, connection_error_category('Authentication failed for user root'));
        self::assertSame(VIRTUSPHERE_INVENTORY_ERROR_AUTHZ, connection_error_category('The session is not authorized to perform this operation'));
    }

    public function testUnclassifiedTextFallsBackToParse(): void
    {
        self::assertSame(VIRTUSPHERE_INVENTORY_ERROR_PARSE, connection_error_category('something entirely unexpected'));
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
        $source = file_get_contents(__DIR__ . '/../../lib/ssh.php');

        self::assertIsString($source);
        self::assertStringNotContainsString('file_get_contents', $source);
        self::assertStringNotContainsString('stream_context_create', $source);
        self::assertStringNotContainsString('/rest/appliance', $source);
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
