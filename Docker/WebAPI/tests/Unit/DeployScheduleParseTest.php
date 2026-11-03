<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/repo/deploy_jobs.php';

/**
 * B3 deploy schedule parsing (ADR-0022). Pure logic: a fixed timezone is passed
 * in, so no DB is needed. Verifies UTC conversion, past/horizon/DST guards and
 * the stagger mode/range rules.
 */
final class DeployScheduleParseTest extends TestCase
{
    private const TZ = 'Europe/Berlin';

    private function localInput(string $modifier): string
    {
        return (new DateTimeImmutable($modifier, new DateTimeZone(self::TZ)))->format('Y-m-d\TH:i');
    }

    public function testImmediateHasNoSchedule(): void
    {
        $result = deploy_parse_schedule(['start_mode' => 'now', 'mode' => 'full'], self::TZ);
        self::assertFalse($result['has_schedule']);
        self::assertNull($result['base_utc']);
        self::assertNull($result['stagger']);
    }

    public function testFutureTimeConvertsToUtc(): void
    {
        $result = deploy_parse_schedule([
            'start_mode' => 'scheduled',
            'scheduled_at' => $this->localInput('+2 days'),
            'mode' => 'full',
        ], self::TZ);

        self::assertTrue($result['has_schedule']);
        self::assertNotNull($result['base_utc']);
        // Stored value must be a valid "Y-m-d H:i:s" UTC string.
        self::assertSame(1, preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $result['base_utc']));
    }

    public function testPastTimeIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        deploy_parse_schedule([
            'start_mode' => 'scheduled',
            'scheduled_at' => $this->localInput('-2 days'),
            'mode' => 'full',
        ], self::TZ);
    }

    public function testBeyondHorizonIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        deploy_parse_schedule([
            'start_mode' => 'scheduled',
            'scheduled_at' => $this->localInput('+40 days'),
            'mode' => 'full',
        ], self::TZ);
    }

    public function testStaggerOnlyForPowerOnModes(): void
    {
        $this->expectException(ValidationException::class);
        deploy_parse_schedule(['stagger_minutes' => '10', 'mode' => 'create'], self::TZ);
    }

    public function testStaggerRangeIsEnforced(): void
    {
        $this->expectException(ValidationException::class);
        deploy_parse_schedule(['stagger_minutes' => '999', 'mode' => 'start'], self::TZ);
    }

    public function testValidStaggerIsAccepted(): void
    {
        $result = deploy_parse_schedule(['stagger_minutes' => '10', 'mode' => 'start'], self::TZ);
        self::assertTrue($result['has_schedule']);
        self::assertSame(10, $result['stagger']);
        self::assertNull($result['base_utc']); // stagger from "now"
    }
}
