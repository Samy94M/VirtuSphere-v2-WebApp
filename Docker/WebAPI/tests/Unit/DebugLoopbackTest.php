<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/errors.php';

/** SEC2 (C-13): debug details reach only a loopback client (or the CLI). */
final class DebugLoopbackTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: bool}> */
    public static function addresses(): iterable
    {
        yield 'IPv4 loopback' => ['127.0.0.1', true];
        yield 'anywhere in 127/8' => ['127.12.0.9', true];
        yield 'IPv6 loopback' => ['::1', true];
        yield 'IPv4-mapped loopback' => ['::ffff:127.0.0.1', true];
        yield 'a LAN client' => ['192.0.2.10', false];
        yield 'the Docker bridge gateway' => ['172.17.0.1', false];
        yield 'an IPv6 client' => ['2001:db8::1', false];
        yield 'an IPv4-mapped LAN client' => ['::ffff:192.0.2.10', false];
        yield 'empty' => ['', false];
        yield 'garbage' => ['127.0.0.1, 192.0.2.10', false];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('addresses')]
    public function testLoopbackClassification(string $address, bool $expected): void
    {
        self::assertSame($expected, virtusphere_is_loopback_address($address));
    }

    public function testTheDebugGateAsksForLoopbackOutsideTheCli(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/errors.php');
        self::assertStringContainsString(
            "return PHP_SAPI === 'cli' || virtusphere_is_loopback_address((string) (\$_SERVER['REMOTE_ADDR'] ?? ''));",
            $source
        );
    }
}
