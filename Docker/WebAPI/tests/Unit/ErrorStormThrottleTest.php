<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/log_rotation.php';

/**
 * LG-16: an unhandled error is admitted to error.log and the audit trail once per
 * error class and hour. The state is a file, so the throttle still works while the
 * database is down, which is exactly when every request fails in the same way.
 */
final class ErrorStormThrottleTest extends TestCase
{
    private string $statePath;

    protected function setUp(): void
    {
        $this->statePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vs-error-storm-' . bin2hex(random_bytes(6)) . '.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->statePath)) {
            unlink($this->statePath);
        }
    }

    public function testTheFirstOccurrenceOfAClassInAnHourIsAdmitted(): void
    {
        self::assertTrue(virtusphere_error_storm_admits('RuntimeException', $this->statePath, '2026101812'));
    }

    public function testLaterOccurrencesOfTheSameClassInThatHourAreNotAdmittedAndAreCounted(): void
    {
        virtusphere_error_storm_admits('RuntimeException', $this->statePath, '2026101812');

        self::assertFalse(virtusphere_error_storm_admits('RuntimeException', $this->statePath, '2026101812'));
        self::assertFalse(virtusphere_error_storm_admits('RuntimeException', $this->statePath, '2026101812'));

        $state = json_decode((string) file_get_contents($this->statePath), true);
        self::assertIsArray($state);
        self::assertSame(2, $state['RuntimeException']['suppressed']);
    }

    public function testAnotherClassIsAdmittedInTheSameHour(): void
    {
        virtusphere_error_storm_admits('RuntimeException', $this->statePath, '2026101812');

        self::assertTrue(virtusphere_error_storm_admits('LogicException', $this->statePath, '2026101812'));
    }

    public function testTheNextHourAdmitsTheClassAgain(): void
    {
        virtusphere_error_storm_admits('RuntimeException', $this->statePath, '2026101812');

        self::assertTrue(virtusphere_error_storm_admits('RuntimeException', $this->statePath, '2026101813'));
    }

    public function testTheStateKeepsOnlyTheCurrentHour(): void
    {
        virtusphere_error_storm_admits('RuntimeException', $this->statePath, '2026101812');
        virtusphere_error_storm_admits('LogicException', $this->statePath, '2026101813');

        $state = json_decode((string) file_get_contents($this->statePath), true);
        self::assertIsArray($state);
        self::assertSame(['LogicException'], array_keys($state));
    }

    /** Without a writable state the occurrence is admitted: the diagnosis beats the throttle. */
    public function testAnUnwritableStateFailsOpen(): void
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vs-missing-' . bin2hex(random_bytes(6))
            . DIRECTORY_SEPARATOR . 'error-storm.json';

        self::assertTrue(virtusphere_error_storm_admits('RuntimeException', $path, '2026101812'));
        self::assertTrue(virtusphere_error_storm_admits('RuntimeException', $path, '2026101812'));
    }
}
