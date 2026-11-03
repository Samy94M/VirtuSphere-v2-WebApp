<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/credentials_test_message.php';

/**
 * K21-F (F-Sweep): the sentences a passed Volltest adds after its verdict, the
 * sweep's outcome and the timer hint of the Ansible host.
 *
 * Information only: the traffic light comes from the test alone, so every notice
 * is an 'info' item, and an empty outcome adds no sentence at all. The timer hint
 * names a MISSING timer only: an installed timer and an unreadable state say
 * nothing, so the page does not repeat a healthy fact on every test.
 */
final class AnsibleFullTestNoticeTest extends TestCase
{
    public function testAPassedTestWithNothingClosedAddsNoSweepNotice(): void
    {
        self::assertNull(credentials_test_secret_sweep_notice(['closed' => 0, 'aborted' => false]));
    }

    public function testClosedRowsNameTheirCountInOneInfoNotice(): void
    {
        $notice = credentials_test_secret_sweep_notice(['closed' => 1, 'aborted' => false]);

        self::assertNotNull($notice);
        self::assertSame('info', $notice['type']);
        self::assertSame(__t('ansible_test.sweep_closed', ['count' => 1]), $notice['message']);
    }

    public function testAbortedSweepSaysHowManyWereClosedBeforeTheStop(): void
    {
        $notice = credentials_test_secret_sweep_notice(['closed' => 2, 'aborted' => true]);

        self::assertNotNull($notice);
        self::assertSame('info', $notice['type']);
        self::assertSame(__t('ansible_test.sweep_aborted', ['count' => 2]), $notice['message']);
    }

    public function testAnAbortedSweepWithNothingClosedStillSaysItStopped(): void
    {
        $notice = credentials_test_secret_sweep_notice(['closed' => 0, 'aborted' => true]);

        self::assertNotNull($notice, 'a stopped sweep is never silent, even when it closed nothing');
        self::assertSame(__t('ansible_test.sweep_aborted', ['count' => 0]), $notice['message']);
    }

    public function testOnlyAMissingTimerIsNamed(): void
    {
        $notice = credentials_test_secret_cleanup_notice('missing');

        self::assertNotNull($notice);
        self::assertSame('info', $notice['type']);
        self::assertSame(__t('ansible_test.secret_cleanup_missing'), $notice['message']);
        self::assertNull(credentials_test_secret_cleanup_notice('enabled'), 'an installed timer adds no sentence');
        self::assertNull(credentials_test_secret_cleanup_notice('unknown'), 'an unreadable state adds no sentence');
        self::assertNull(credentials_test_secret_cleanup_notice('bogus'), 'a state the preflight never prints names nothing');
    }

    public function testFollowupNoticesAreEmptyForAResultWithoutSweepOrTimerState(): void
    {
        self::assertSame([], credentials_test_followup_notices(credential_test_result(true, VIRTUSPHERE_CREDENTIAL_TEST_OK)));
    }

    public function testFollowupNoticesCollectTheSweepLineAndTheMissingTimerInOrder(): void
    {
        $result = credential_test_result(true, VIRTUSPHERE_CREDENTIAL_TEST_OK, '', ['secret_cleanup' => 'missing']);
        $result['secret_sweep'] = ['closed' => 1, 'aborted' => false];

        self::assertSame(
            [
                __t('ansible_test.sweep_closed', ['count' => 1]),
                __t('ansible_test.secret_cleanup_missing'),
            ],
            credentials_test_followup_notices($result)
        );
    }

    public function testFollowupNoticesOfAnEnabledTimerLeaveOnlyTheSweepLine(): void
    {
        $result = credential_test_result(true, VIRTUSPHERE_CREDENTIAL_TEST_OK, '', ['secret_cleanup' => 'enabled']);
        $result['secret_sweep'] = ['closed' => 0, 'aborted' => false];

        self::assertSame([], credentials_test_followup_notices($result));
    }
}
