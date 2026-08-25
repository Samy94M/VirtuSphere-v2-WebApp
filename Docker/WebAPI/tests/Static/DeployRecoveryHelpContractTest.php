<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Keeps retry guidance tied to the evidence the deploy path actually owns. */
final class DeployRecoveryHelpContractTest extends TestCase
{
    private const REQUIRED = [
        'de' => [
            'Nur wenn ein Abbruch oder ein Zeitbudget während oder nach dem Create-Schritt eintritt',
            'bestätigtem MAC-Import',
            'bereitgestellten Zustand',
            'vor einem Wiederholungslauf',
            'Bestand auf ESXi',
            'gespeicherte Identität',
        ],
        'en' => [
            'Only a cancellation or a time budget reached during or after the create step',
            'confirmed MAC import',
            'provisioned state',
            'Before a retry',
            'inventory on ESXi',
            'stored identity',
        ],
    ];

    public function testBothRealCatalogsCarryTheWholeRecoveryBoundary(): void
    {
        foreach (self::REQUIRED as $locale => $_) {
            self::assertSame([], $this->validate($this->catalogText($locale), $locale));
        }
    }

    public function testEveryClauseAndAZeroMatchAreEffective(): void
    {
        foreach (self::REQUIRED as $locale => $needles) {
            $text = $this->catalogText($locale);
            self::assertNotSame([], $this->validate('', $locale), $locale . ' zero-match fixture unexpectedly passed.');
            foreach ($needles as $needle) {
                $mutated = str_replace($needle, '', $text);
                self::assertNotSame($text, $mutated, $locale . ' fixture did not remove ' . $needle);
                self::assertNotSame([], $this->validate($mutated, $locale), $locale . ' passed without ' . $needle);
            }
        }
    }

    /** @return array<int, string> */
    private function validate(string $text, string $locale): array
    {
        $errors = [];
        foreach (self::REQUIRED[$locale] ?? [] as $needle) {
            if (!str_contains($text, $needle)) {
                $errors[] = $locale . ' recovery guidance is missing: ' . $needle;
            }
        }

        return $errors;
    }

    private function catalogText(string $locale): string
    {
        $catalog = require dirname(__DIR__, 2) . '/lang/' . $locale . '/help_deploy.php';
        self::assertIsArray($catalog);
        self::assertArrayHasKey('deploy_identity_p2', $catalog);
        self::assertIsString($catalog['deploy_identity_p2']);

        return $catalog['deploy_identity_p2'];
    }
}
