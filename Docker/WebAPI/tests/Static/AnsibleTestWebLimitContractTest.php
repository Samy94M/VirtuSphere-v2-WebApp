<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// Every file below exists at HEAD. The K21-F symbols are read inside test bodies only, and the
// data provider carries literal values, so one red test cannot abort the suite.
require_once dirname(__DIR__, 2) . '/lib/deploy_remote_secret.php';
require_once dirname(__DIR__, 2) . '/lib/ansible_test_config.php';
require_once dirname(__DIR__, 2) . '/lib/ansible_full_test.php';

/**
 * K21-F: the time limits of the full test, written once where they belong.
 *
 * A manual full test runs in a portal request, and nginx ends every PHP request
 * at its fastcgi_read_timeout. VIRTUSPHERE_ANSIBLE_TEST_WEB_LIMIT_SECONDS is the
 * same value kept in the code, so it may never be LARGER than either nginx file
 * that sets the timeout: the development config and the HTTPS config the portal
 * generates. A larger constant would let the sweep run past the request.
 *
 * The scheduled worker has no second copy either: its alarm and the scheduler's
 * limit are the one constant VIRTUSPHERE_ANSIBLE_TEST_TOTAL_TIMEOUT_SECONDS.
 *
 * A source without its directive is a red test, never a skip.
 */
final class AnsibleTestWebLimitContractTest extends TestCase
{
    private const DIRECTIVE = '/fastcgi_read_timeout\s+(\d+)\s*;/';

    /** @return iterable<string, array{string}> label of the config source */
    public static function nginxSources(): iterable
    {
        yield 'Docker/nginx/default.conf' => ['Docker/nginx/default.conf'];
        yield 'lib/https_config.php (generated HTTPS server block)' => ['lib/https_config.php'];
    }

    #[DataProvider('nginxSources')]
    public function testTheWebLimitIsNotLargerThanTheReadTimeoutOfEveryNginxConfig(string $relative): void
    {
        $path = $relative === 'Docker/nginx/default.conf'
            ? dirname(__DIR__, 3) . '/nginx/default.conf'
            : dirname(__DIR__, 2) . '/' . $relative;
        self::assertFileExists($path, 'nginx source is missing: ' . $relative);

        $found = preg_match_all(self::DIRECTIVE, (string) file_get_contents($path), $matches);
        self::assertGreaterThan(
            0,
            $found,
            'Zero match: no fastcgi_read_timeout in ' . $relative . '; the web limit has no source to be checked against.'
        );
        foreach ($matches[1] as $timeout) {
            self::assertLessThanOrEqual(
                (int) $timeout,
                VIRTUSPHERE_ANSIBLE_TEST_WEB_LIMIT_SECONDS,
                'VIRTUSPHERE_ANSIBLE_TEST_WEB_LIMIT_SECONDS is larger than the ' . $timeout . ' s read timeout of ' . $relative
                    . '; a manual sweep could still run when nginx ends the request.'
            );
        }
    }

    public function testTheWebLimitLeavesRoomForOneFullSweepInsideTheRequest(): void
    {
        $oneSweep = VIRTUSPHERE_REMOTE_SECRET_TEST_SWEEP_BUDGET_SECONDS
            + VIRTUSPHERE_REMOTE_SECRET_CONNECT_SECONDS
            + VIRTUSPHERE_DEPLOY_REMOTE_CLEANUP_TIMEOUT_SECONDS
            + VIRTUSPHERE_REMOTE_SECRET_TEST_SWEEP_MARGIN_SECONDS;

        self::assertLessThan(
            VIRTUSPHERE_ANSIBLE_TEST_WEB_LIMIT_SECONDS,
            $oneSweep,
            'budget, one overrun and the margin must fit into the web limit'
        );
    }

    public function testTheSchedulerLimitIsTheWorkerAlarmAndNoSecondCopyExists(): void
    {
        $worker = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/ansible_test_worker.php');
        $scheduler = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/ansible_test_scheduler.php');

        self::assertSame(
            1,
            preg_match('/pcntl_alarm\(VIRTUSPHERE_ANSIBLE_TEST_TOTAL_TIMEOUT_SECONDS\)/', $worker),
            'Zero match: the scheduled worker does not arm its alarm from VIRTUSPHERE_ANSIBLE_TEST_TOTAL_TIMEOUT_SECONDS.'
        );
        // pcntl_alarm(0) disarms the alarm after the test and is fine; a positive literal is a second copy of the limit.
        self::assertSame(
            0,
            preg_match('/pcntl_alarm\(\s*[1-9]\d*\s*\)/', $worker),
            'The worker alarm must not be a literal copy of the limit.'
        );
        self::assertSame(
            1,
            preg_match("/'time_limit'\s*=>\s*VIRTUSPHERE_ANSIBLE_TEST_TOTAL_TIMEOUT_SECONDS/", $scheduler),
            'Zero match: the scheduler does not pass the worker limit to the sweep budget.'
        );

        $definitions = 0;
        foreach (glob(dirname(__DIR__, 2) . '/lib/*.php') ?: [] as $file) {
            $definitions += preg_match_all('/^const\s+VIRTUSPHERE_ANSIBLE_TEST_TOTAL_TIMEOUT_SECONDS\s*=/m', (string) file_get_contents($file));
        }
        self::assertSame(1, $definitions, 'The total limit must be defined exactly once in lib/.');
    }
}
