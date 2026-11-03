<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/CssRules.php';

final class DeployNoticeSemanticsContractTest extends TestCase
{
    public function testInfoVariantAddsNoSecondAlertBoxModel(): void
    {
        // Globbed, not named: this read components.css until Etappe 16 split it
        // into four domain sheets, and a guard that names one file goes quiet
        // the moment the rule it watches moves next door.
        $sheets = CssRules::stylesheets(dirname(__DIR__, 2));
        self::assertNotSame([], $sheets, 'no portal stylesheet found; the scan root moved');
        $css = implode("\n", $sheets);
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
