<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/esxi_capabilities.php';

final class EsxiEvidenceAgeTest extends TestCase
{
    private const NOW = 1791201600;

    private function row(int $age): array
    {
        return [
            'last_attempt_at' => gmdate('Y-m-d H:i:s', self::NOW),
            'last_success_at' => gmdate('Y-m-d H:i:s', self::NOW - $age),
            'last_status' => 'ok',
            'license_free' => 1,
        ];
    }

    public function testMonthsOldEvidenceCannotRemainGreenWhenAutomationIsOff(): void
    {
        $row = $this->row(90 * 86400);
        self::assertSame('stale', esxi_inventory_ampel($row, 0, self::NOW));
        self::assertFalse(esxi_capabilities_fresh($row, 0, self::NOW));
        self::assertSame('ok', esxi_autostart_preflight($row, 0, self::NOW)['verdict']);
    }

    public function testFutureAndUnreadableSuccessNeverProveCurrentCapabilities(): void
    {
        foreach (['unreadable', gmdate('Y-m-d H:i:s', self::NOW + VIRTUSPHERE_STATUS_EVIDENCE_FUTURE_SKEW_SECONDS + 1)] as $value) {
            $row = $this->row(0);
            $row['last_success_at'] = $value;
            self::assertSame('unknown', esxi_inventory_ampel($row, 0, self::NOW));
            self::assertFalse(esxi_capabilities_fresh($row, 0, self::NOW));
        }
    }

    public function testBothReadersShareTheInclusiveDeadline(): void
    {
        foreach ([0, 6] as $interval) {
            $limit = esxi_inventory_evidence_window_seconds($interval);
            self::assertSame('ok', esxi_inventory_ampel($this->row($limit), $interval, self::NOW));
            self::assertTrue(esxi_capabilities_fresh($this->row($limit), $interval, self::NOW));
            self::assertSame('stale', esxi_inventory_ampel($this->row($limit + 1), $interval, self::NOW));
            self::assertFalse(esxi_capabilities_fresh($this->row($limit + 1), $interval, self::NOW));
        }
    }

    public function testProvenFailuresRemainWarningsEvenWhenTheirLastSuccessIsOld(): void
    {
        $row = $this->row(90 * 86400);
        $row['last_status'] = 'failed';
        self::assertSame('warning', esxi_inventory_ampel($row, 0, self::NOW));
        $row['paused_until_credential_change'] = 1;
        self::assertSame('danger', esxi_inventory_ampel($row, 0, self::NOW));
    }
}
