<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/log_rotation.php';

/**
 * LG-15: the health endpoint writes one line per outage, not one per poll. The
 * outage lives in a flag file because the endpoint keeps no state between requests.
 */
final class OutageFlagTest extends TestCase
{
    private string $flagPath;

    protected function setUp(): void
    {
        $this->flagPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vs-outage-' . bin2hex(random_bytes(6)) . '.flag';
    }

    protected function tearDown(): void
    {
        if (is_file($this->flagPath)) {
            unlink($this->flagPath);
        }
    }

    public function testTheFirstFailedPollBeginsAnOutageAndLaterPollsDoNot(): void
    {
        self::assertTrue(virtusphere_outage_begins($this->flagPath));
        self::assertFalse(virtusphere_outage_begins($this->flagPath));
        self::assertFalse(virtusphere_outage_begins($this->flagPath));
    }

    public function testAHealthyAnswerEndsTheOutageSoTheNextOneIsAnnouncedAgain(): void
    {
        virtusphere_outage_begins($this->flagPath);
        virtusphere_outage_ends($this->flagPath);

        self::assertFileDoesNotExist($this->flagPath);
        self::assertTrue(virtusphere_outage_begins($this->flagPath));
    }

    public function testEndingWithoutAnOutageIsNothingToDo(): void
    {
        virtusphere_outage_ends($this->flagPath);

        self::assertFileDoesNotExist($this->flagPath);
    }

    /** A flag that cannot be created keeps the diagnosis: every poll is announced. */
    public function testAFlagThatCannotBeCreatedAnnouncesEveryPoll(): void
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vs-missing-' . bin2hex(random_bytes(6))
            . DIRECTORY_SEPARATOR . 'health-db.flag';

        self::assertTrue(virtusphere_outage_begins($path));
        self::assertTrue(virtusphere_outage_begins($path));
    }
}
