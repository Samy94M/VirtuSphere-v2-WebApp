<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Proves the mechanism behind zend.exception_ignore_args, not the .ini file.
 *
 * tests/Static/ExceptionArgsContractTest.php pins that the setting ships. This
 * one pins what the setting does, by throwing through a frame that takes a
 * secret as a positional argument and reading the rendered trace - the exact
 * shape of ansible_prepare_job_artifacts() (lib/ansible.php) and
 * ssh_execute_command() (lib/ssh.php), both of which throw on routine
 * conditions with a decrypted password in their argument list.
 *
 * Two runs, because a test that only asserts the good case cannot tell "the
 * setting works" from "the trace never contained the value anyway": at 0 the
 * plaintext must be present, at 1 it must be gone. If a future PHP version
 * changes the semantics, the first assertion is what notices.
 *
 * zend.exception_ignore_args is ZEND_INI_ALL, so both states are reachable from
 * a test without a second process.
 */
final class ExceptionTraceRedactionTest extends TestCase
{
    private const SECRET = 'Uns3rGeheim!Passwort';

    private string $previous = '';

    protected function setUp(): void
    {
        $current = ini_get('zend.exception_ignore_args');
        if ($current === false) {
            self::markTestSkipped('zend.exception_ignore_args is not readable in this runtime');
        }
        $this->previous = (string) $current;
    }

    protected function tearDown(): void
    {
        ini_set('zend.exception_ignore_args', $this->previous);
    }

    /** The shape of every secret-bearing frame in the deploy path. */
    private static function throwWithSecret(string $host, string $secret, int $port): never
    {
        throw new RuntimeException('Mission not found.');
    }

    private function renderedTrace(): string
    {
        // No guard after the catch: throwWithSecret() is declared `never`, so
        // PHP itself enforces that the fixture frame cannot return.
        try {
            self::throwWithSecret('esxi-01.lan', self::SECRET, 443);
        } catch (RuntimeException $exception) {
            return $exception->getTraceAsString();
        }
    }

    public function testTheSecretIsRenderedWhenArgumentsAreNotIgnored(): void
    {
        // The unguarded state, so the assertion below cannot pass vacuously.
        // PHP renders the first zend.exception_string_param_max_len characters
        // (default 15), which for most passwords is the whole value.
        ini_set('zend.exception_ignore_args', '0');

        self::assertStringContainsString(
            substr(self::SECRET, 0, 15),
            $this->renderedTrace(),
            'without the setting PHP really does render argument values; if this fails the mechanism changed '
            . 'and the assertion below no longer proves anything'
        );
    }

    public function testTheSecretIsAbsentWhenArgumentsAreIgnored(): void
    {
        ini_set('zend.exception_ignore_args', '1');

        $trace = $this->renderedTrace();
        self::assertStringNotContainsString(self::SECRET, $trace);
        self::assertStringNotContainsString(substr(self::SECRET, 0, 15), $trace);
        // The frame itself must survive: file, line and function are what an
        // operator needs, and removing them would trade one blind spot for another.
        self::assertStringContainsString('throwWithSecret', $trace);
    }
}
