<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/ansible_test_config.php';

final class AnsibleTestConfigTest extends TestCase
{
    public function testDisabledAndBoundaryIntervalsRemainDistinct(): void
    {
        self::assertSame(0, ansible_test_parse_interval('0'));
        self::assertSame(1, ansible_test_parse_interval('1'));
        self::assertSame(VIRTUSPHERE_ANSIBLE_TEST_INTERVAL_HOURS_DEFAULT,
            ansible_test_parse_interval((string) VIRTUSPHERE_ANSIBLE_TEST_INTERVAL_HOURS_DEFAULT));
        self::assertSame(VIRTUSPHERE_ANSIBLE_TEST_INTERVAL_HOURS_MAX,
            ansible_test_parse_interval((string) VIRTUSPHERE_ANSIBLE_TEST_INTERVAL_HOURS_MAX));
    }

    public function testInvalidValuesCannotBecomeAnEnabledSchedule(): void
    {
        foreach (['', '-1', '1.5', '1e2', '24h', "24\n", str_repeat('9', 100),
            (string) (VIRTUSPHERE_ANSIBLE_TEST_INTERVAL_HOURS_MAX + 1)] as $value) {
            self::assertNull(ansible_test_parse_interval($value), $value);
        }
    }
}
