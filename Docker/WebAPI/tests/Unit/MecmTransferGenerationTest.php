<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/mecm_rollout_fence.php';

/** AV-P0: the transfer generation an updateDevice callback may report. */
final class MecmTransferGenerationTest extends TestCase
{
    public function testAbsentOrEmptyMeansACallerFromBeforeTheCounter(): void
    {
        self::assertNull(mecm_transfer_generation_reported(null));
        self::assertNull(mecm_transfer_generation_reported(''));
    }

    public function testNonNegativeIntegersAndDigitStringsAreAccepted(): void
    {
        self::assertSame(0, mecm_transfer_generation_reported(0));
        self::assertSame(7, mecm_transfer_generation_reported(7));
        self::assertSame(12, mecm_transfer_generation_reported('12'));
    }

    #[DataProvider('malformedGenerations')]
    public function testEveryOtherValueIsMalformed(mixed $raw): void
    {
        $this->expectException(InvalidArgumentException::class);
        mecm_transfer_generation_reported($raw);
    }

    /** @return array<string, array{0:mixed}> */
    public static function malformedGenerations(): array
    {
        return [
            'negative integer' => [-1],
            'fraction' => [1.5],
            'non-digit string' => ['x'],
            'negative string' => ['-1'],
            'leading whitespace' => [' 1'],
            'boolean' => [true],
            'array' => [[1]],
            'overflow' => ['1234567890123456789'],
        ];
    }

    public function testOnlyTheReadGenerationOrALegacyCallerAnswersTheQueuedTransfer(): void
    {
        self::assertTrue(mecm_transfer_generation_applied(null, 3));
        self::assertTrue(mecm_transfer_generation_applied(3, 3));
        self::assertFalse(mecm_transfer_generation_applied(2, 3), 'a transfer queued after the read stays queued');
        self::assertFalse(mecm_transfer_generation_applied(4, 3), 'a future generation answers nothing');
    }
}
