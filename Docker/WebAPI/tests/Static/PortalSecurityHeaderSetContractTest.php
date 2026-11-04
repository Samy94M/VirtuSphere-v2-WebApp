<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * SEC1 (C-12): the portal header set lives in one function (A50). This pins
 * the three additions and that no machine endpoint loads the portal headers,
 * where Cross-Origin-Resource-Policy would have no business.
 */
final class PortalSecurityHeaderSetContractTest extends TestCase
{
    public function testThePortalSendsTheCrossOriginAndFrameHeaders(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/headers.php');
        foreach ([
            "header('X-Frame-Options: DENY');",
            "header('Cross-Origin-Opener-Policy: same-origin');",
            "header('Cross-Origin-Resource-Policy: same-origin');",
        ] as $line) {
            self::assertSame(1, substr_count($source, $line), $line);
        }
    }

    public function testNoMachineEndpointSendsThePortalHeaders(): void
    {
        $endpoints = glob(dirname(__DIR__, 2) . '/*.php') ?: [];
        self::assertNotEmpty($endpoints, 'Zero match: no endpoint files found');
        foreach ($endpoints as $file) {
            $source = (string) file_get_contents($file);
            self::assertStringNotContainsString('virtusphere_send_security_headers', $source, basename($file));
            self::assertStringNotContainsString('lib/bootstrap.php', $source, basename($file));
        }
    }
}
