<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/errors.php';

/**
 * LG-01: the error log names a CLI request by its script and its argument count
 * only. virtusphere_error_context() used to write argv verbatim, and the legacy
 * `seed.php <user> <password>` form put the admin password into error.log there.
 */
final class ErrorContextCliRequestTest extends TestCase
{
    public function testACliRequestNamesTheScriptAndCountsItsArgumentsWithoutTheirValues(): void
    {
        self::assertSame('cli', PHP_SAPI, 'The request part under test is the CLI branch.');

        $hadArgv = array_key_exists('argv', $_SERVER);
        $savedArgv = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = ['lib/seed.php', 'admin', 'Probe-Passwort-7x', 'admin@example.lan'];
        try {
            $context = virtusphere_error_context(new RuntimeException('probe'), 'ref-test', false);
        } finally {
            if ($hadArgv) {
                $_SERVER['argv'] = $savedArgv;
            } else {
                unset($_SERVER['argv']);
            }
        }

        self::assertStringContainsString('request=cli seed.php (3 args) ', $context);
        self::assertStringNotContainsString('Probe-Passwort-7x', $context);
        self::assertStringNotContainsString('admin@example.lan', $context);
        self::assertStringNotContainsString('lib/seed.php', $context, 'The script path is not logged; its base name is.');
    }
}
