<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/esxi_capabilities.php';

final class EsxiWriteCapabilityTest extends TestCase
{
    private const NOW = 1791201600;

    public function testAFreshReadOnlyLicenseBlocksEveryWritingModeButAllowsReads(): void
    {
        $row = ['last_success_at' => gmdate('Y-m-d H:i:s', self::NOW), 'license_free' => 1];
        foreach (['full', 'create', 'powercycle', 'start', 'autostart'] as $mode) {
            self::assertSame('block', esxi_write_preflight($row, 0, $mode, self::NOW)['verdict'], $mode);
        }
        foreach (['export', 'inventory'] as $mode) {
            self::assertSame('ok', esxi_write_preflight($row, 0, $mode, self::NOW)['verdict'], $mode);
        }
    }

    public function testUnconfirmedCapabilitiesCannotRefuseAWrite(): void
    {
        foreach ([null, ['license_free' => 1], ['last_success_at' => '2025-01-01 00:00:00', 'license_free' => 1], ['last_success_at' => gmdate('Y-m-d H:i:s', self::NOW), 'license_free' => null]] as $row) {
            self::assertSame('ok', esxi_write_preflight($row, 0, 'full', self::NOW)['verdict']);
        }
    }

    public function testHAOnlyChangesTheAutostartDecision(): void
    {
        $row = ['last_success_at' => gmdate('Y-m-d H:i:s', self::NOW), 'license_free' => 0, 'in_ha_cluster' => 1];
        self::assertSame('ok', esxi_write_preflight($row, 0, 'full', self::NOW)['verdict']);
        self::assertSame('skip', esxi_autostart_preflight($row, 0, self::NOW)['verdict']);
    }
}
