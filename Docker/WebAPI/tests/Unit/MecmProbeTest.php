<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/mecm_probe.php';

final class MecmProbeTest extends TestCase
{
    public function testStoredHostSelectsExplicitMode(): void
    {
        self::assertSame(VIRTUSPHERE_PROBE_MODE_AUTO, mecm_probe_mode(''));
        self::assertSame(VIRTUSPHERE_PROBE_MODE_AUTO, mecm_probe_mode('  '));
        self::assertSame(VIRTUSPHERE_PROBE_MODE_MANUAL, mecm_probe_mode('1.1.1.4'));
    }

    public function testHostValidationCoversDnsIpv4AndIpv6(): void
    {
        foreach (['mecm.example.internal', '10.20.30.40', '2001:db8::4', '[2001:db8::4]'] as $host) {
            self::assertTrue(mecm_probe_host_is_valid($host), $host);
        }
        foreach (['', '-mecm.local', 'mecm_.local', 'mecm local', 'mecm..local'] as $host) {
            self::assertFalse(mecm_probe_host_is_valid($host), $host);
        }
    }

    public function testPortBoundariesAreStrict(): void
    {
        self::assertTrue(mecm_probe_port_is_valid('1'));
        self::assertTrue(mecm_probe_port_is_valid('445'));
        self::assertTrue(mecm_probe_port_is_valid('65535'));
        foreach (['', '0', '-1', '65536', '44.5', 'abc'] as $port) {
            self::assertFalse(mecm_probe_port_is_valid($port), $port);
        }
    }

    public function testTcpUriBracketsOnlyIpv6(): void
    {
        self::assertSame('tcp://mecm.local:445', mecm_probe_tcp_uri('mecm.local', 445));
        self::assertSame('tcp://10.20.30.40:445', mecm_probe_tcp_uri('10.20.30.40', 445));
        self::assertSame('tcp://[2001:db8::4]:445', mecm_probe_tcp_uri('[2001:db8::4]', 445));
    }

    public function testExpectedSocketErrorsAreCategorized(): void
    {
        self::assertSame(VIRTUSPHERE_PROBE_ERROR_DNS, mecm_probe_error_category(11001, 'No such host'));
        self::assertSame(VIRTUSPHERE_PROBE_ERROR_TIMEOUT, mecm_probe_error_category(10060, 'timed out'));
        self::assertSame(VIRTUSPHERE_PROBE_ERROR_REFUSED, mecm_probe_error_category(10061, 'Connection refused'));
        self::assertSame(VIRTUSPHERE_PROBE_ERROR_NETWORK, mecm_probe_error_category(10051, 'Network is unreachable'));
        self::assertSame(VIRTUSPHERE_PROBE_ERROR_UNKNOWN, mecm_probe_error_category(999, 'unexpected'));
    }

    public function testVersionedDetailAndLegacyFallback(): void
    {
        $stored = mecm_probe_encode_detail([
            'target' => 'mecm.local',
            'port' => 445,
            'status' => 'fail',
            'error_category' => VIRTUSPHERE_PROBE_ERROR_REFUSED,
            'detail' => 'Connection refused',
            'mode' => VIRTUSPHERE_PROBE_MODE_MANUAL,
        ]);
        $decoded = mecm_probe_decode_detail($stored);
        self::assertFalse($decoded['legacy']);
        self::assertSame('mecm.local', $decoded['target']);
        self::assertSame(445, $decoded['port']);
        self::assertSame(VIRTUSPHERE_PROBE_ERROR_REFUSED, $decoded['error_category']);

        $legacy = mecm_probe_decode_detail('old socket error');
        self::assertTrue($legacy['legacy']);
        self::assertSame('old socket error', $legacy['detail']);
    }

    public function testStoredTechnicalDetailIsBounded(): void
    {
        $decoded = mecm_probe_decode_detail(mecm_probe_encode_detail([
            'target' => 'mecm.local',
            'port' => 445,
            'status' => 'fail',
            'detail' => str_repeat('x', 500),
        ]));
        self::assertSame(VIRTUSPHERE_MECM_PROBE_DETAIL_TEXT_MAX, strlen($decoded['detail']));
    }
}
