<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DeployNoticeSemanticsContractTest extends TestCase
{
    public function testInfoVariantAddsNoSecondAlertBoxModel(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/portal/assets/css/components.css');
        self::assertSame(1, preg_match('/\.alert-info\s*\{([^}]*)\}/s', $css, $match));
        self::assertStringContainsString('border-color:', $match[1]);
        foreach (['padding:', 'background:', 'border-radius:', 'border:'] as $duplicate) {
            self::assertStringNotContainsString($duplicate, $match[1], '.alert owns ' . $duplicate);
        }
    }

    public function testEveryNonBlockingDeployWarningSaysItDoesNotBlock(): void
    {
        foreach (['de', 'en'] as $locale) {
            $catalog = require dirname(__DIR__, 2) . '/lang/' . $locale . '/deploy.php';
            $needles = $locale === 'de' ? ['nicht blockiert', 'trotzdem'] : ['not blocked', 'still'];
            self::assertStringContainsString($needles[0], $catalog['inventory_deviation_warn']);
            self::assertStringContainsString($needles[0], $catalog['host_missing_warn']);
            self::assertStringContainsString($needles[1], $catalog['capability_warn']);
            self::assertStringContainsString($locale === 'de' ? 'blockiert den Deploy nicht' : 'never blocks the deploy', $catalog['storage_hint']);
        }
    }
}
