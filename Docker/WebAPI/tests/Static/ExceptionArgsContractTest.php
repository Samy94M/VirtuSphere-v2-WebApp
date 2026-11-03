<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Pins that stack traces carry no argument values.
 *
 * The decrypted ESXi password is a positional argument of
 * ansible_prepare_job_artifacts() (lib/ansible.php), of the inventory twin
 * (lib/ansible_inventory.php) and of ssh_execute_command() (lib/ssh.php), and
 * those frames throw on routine conditions: mission not found, mission is a
 * template, no VMs, foreign VMs. With PHP's compiled default
 * zend.exception_ignore_args=0, every trace through them renders the plaintext,
 * and lib/errors.php writes getTraceAsString() to logs/error.log, to worker
 * STDERR and, under VIRTUSPHERE_DEBUG, into the page.
 *
 * That no trace with a plaintext secret exists today is an accident of style:
 * every one of those call sites happens to sit in a catch(Throwable) that
 * forwards only getMessage(). One getTraceAsString() added to a catch, or one
 * uncaught path, brings the secret back. This test makes the setting the
 * contract instead of the discipline.
 *
 * The mechanism itself is proven in tests/Unit/ExceptionTraceRedactionTest.php,
 * which does not read this file at all. Both exist on purpose: one proves the
 * setting ships, the other proves what the setting does.
 */
final class ExceptionArgsContractTest extends TestCase
{
    /** @return array<string, string> */
    private function settings(): array
    {
        // Docker/php is outside the container mount (only Docker/WebAPI is
        // mounted as /var/www/html), so the test skips there and runs from a
        // repo checkout, exactly like SessionHardeningContractTest.
        $path = dirname(__DIR__, 3) . '/php/conf.d/zz-virtusphere.ini';
        if (!is_file($path)) {
            self::markTestSkipped('Docker/php/conf.d/zz-virtusphere.ini is not visible from this runtime');
        }

        $parsed = parse_ini_file($path, false, INI_SCANNER_RAW);
        self::assertIsArray($parsed, 'zz-virtusphere.ini is not parseable');

        /** @var array<string, string> $parsed */
        return $parsed;
    }

    public function testTracesCarryNoArgumentValues(): void
    {
        $settings = $this->settings();

        self::assertArrayHasKey(
            'zend.exception_ignore_args',
            $settings,
            'zz-virtusphere.ini does not set zend.exception_ignore_args, so PHP\'s default 0 applies and every '
            . 'stack trace renders argument values. lib/ansible.php and lib/ssh.php take a decrypted secret as a '
            . 'positional argument and throw on routine conditions. Raise the .ini, not this test.'
        );
        self::assertSame(
            '1',
            $settings['zend.exception_ignore_args'],
            'zend.exception_ignore_args must be 1. A trace is written to logs/error.log in production, where '
            . 'VIRTUSPHERE_DEBUG is off, so this cannot be gated on debug.'
        );
    }

    public function testTheRedundantSecondSettingStaysAbsent(): void
    {
        // zend.exception_string_param_max_len = 0 is the alternative, not an
        // addition: it keeps arity and types and empties strings. Setting both
        // says two things about one decision, and the next reader cannot tell
        // which one is load-bearing.
        self::assertArrayNotHasKey(
            'zend.exception_string_param_max_len',
            $this->settings(),
            'Pick one of the two settings. ignore_args=1 already removes the whole argument list.'
        );
    }
}
