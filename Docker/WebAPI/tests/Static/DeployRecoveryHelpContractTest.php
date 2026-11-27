<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Keeps retry guidance tied to the evidence the deploy path actually owns. */
final class DeployRecoveryHelpContractTest extends TestCase
{
    private const REQUIRED = [
        'de' => [
            'vorhandene VM weder als nicht angelegt freigeben noch als allgemeine Reparatur löschen',
            'erfolgreicher VM-Inventarabruf',
            'neuer als Auftrag und Einheit',
            'Recent Tasks',
        ],
        'en' => [
            'neither release an existing VM as not created nor delete it as a general repair',
            'successful VM inventory pull',
            'newer than both the job and the unit',
            'Recent Tasks',
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
        // PR-R6 split both paragraphs at a sentence boundary; the help renders
        // each paragraph as its key followed by the `_b` part, so the guidance
        // is read from the whole paragraph.
        $parts = [];
        foreach (['deploy_identity_p2', 'deploy_identity_p2_b', 'create_progress_p3', 'create_progress_p3_b'] as $key) {
            self::assertArrayHasKey($key, $catalog);
            self::assertIsString($catalog[$key]);
            $parts[] = $catalog[$key];
        }

        return implode(' ', $parts);
    }
}
