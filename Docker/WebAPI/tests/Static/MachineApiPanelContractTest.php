<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The Machine-API settings panel holds two things that both say "MECM" and mean
 * opposite directions: the allowlist is who may call *into* the portal, the
 * probe is whether the portal reaches *out* to MECM. An operator who does not
 * know that reads a failing probe next to a working sync as a contradiction.
 *
 * The empty allowlist is the sharper problem. machine_api_ip_allowed() is
 * deny-by-default, so an empty list means every machine endpoint answers 403:
 * no device sync, no package sync, no autoimporter, no MAC upload. It used to
 * render as a grey table row reading "no entries yet", which looks like an
 * unset option rather than a total outage.
 */
final class MachineApiPanelContractTest extends TestCase
{
    private function settings(): string
    {
        $path = str_replace('\\', '/', dirname(__DIR__, 2)) . '/portal/settings.php';
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** @return array<string, string> */
    private function catalog(string $locale): array
    {
        return require str_replace('\\', '/', dirname(__DIR__, 2)) . '/lang/' . $locale . '/settings.php';
    }

    public function testTheDenyByDefaultGateStillWorksThatWay(): void
    {
        // The warning below is only true while the gate is deny-by-default. If
        // someone ever makes an empty list mean "allow all", this test fails
        // and the text has to change with it.
        $source = (string) file_get_contents(
            str_replace('\\', '/', dirname(__DIR__, 2)) . '/lib/machine_api.php'
        );
        self::assertStringContainsString('function machine_api_ip_allowed', $source);
        self::assertStringContainsString('num_rows > 0', $source);
    }

    public function testAnEmptyAllowlistIsWarnedAboutAndNotJustListedAsEmpty(): void
    {
        $source = $this->settings();
        self::assertMatchesRegularExpression(
            '/if \(\$allowlistEntries === \[\]\) \{ \?>\s*<div class="alert alert-warning">/',
            $source,
            'an empty allowlist must render a warning, not only a table row'
        );
        self::assertStringContainsString("settings.allowlist_empty_warning", $source);
    }

    public function testBothDirectionsAreNamedBeforeThePanels(): void
    {
        self::assertStringContainsString('settings.machine_api_directions', $this->settings());
        self::assertStringContainsString('settings.probe_blocks_nothing', $this->settings());
    }

    public function testTheHeadingsCarryTheirDirection(): void
    {
        // Two headings that both start with "MECM" are why the panel read as
        // one topic; each must now say which way the traffic goes.
        foreach (['de' => ['eingehend', 'ausgehend'], 'en' => ['inbound', 'outbound']] as $locale => $words) {
            $catalog = $this->catalog($locale);
            $headings = mb_strtolower($catalog['allowlist_title'] . '|' . $catalog['probe_title']);
            foreach ($words as $word) {
                self::assertStringContainsString(
                    $word,
                    $headings,
                    $locale . ': neither Machine-API heading says "' . $word . '"'
                );
            }
        }
    }

    public function testTheNewTextsExistInBothLocalesAndSayWhatTheyMust(): void
    {
        foreach (['de', 'en'] as $locale) {
            $catalog = $this->catalog($locale);
            foreach (['machine_api_directions', 'allowlist_empty_warning', 'probe_blocks_nothing'] as $key) {
                self::assertArrayHasKey($key, $catalog, $locale . ' is missing ' . $key);
                // Plain-language explanations for rotating admin staff, not labels.
                self::assertGreaterThan(80, mb_strlen($catalog[$key]), $locale . '.' . $key . ' is too terse to explain anything');
            }
        }
    }

    public function testTheAutoModeHintWarnsAboutAddressTranslation(): void
    {
        // The auto target is $_SERVER['REMOTE_ADDR'] of the last device sync,
        // so a proxy or NAT in the path makes the portal probe that device
        // instead of MECM. Silence about it turns a config trap into an
        // apparent product bug.
        $de = mb_strtolower($this->catalog('de')['probe_mode_auto_hint']);
        self::assertTrue(
            str_contains($de, 'proxy') || str_contains($de, 'router') || str_contains($de, 'adressumsetzung'),
            'the German auto-mode hint must name what makes the remembered address wrong'
        );
        $en = mb_strtolower($this->catalog('en')['probe_mode_auto_hint']);
        self::assertTrue(
            str_contains($en, 'proxy') || str_contains($en, 'router') || str_contains($en, 'translation'),
            'the English auto-mode hint must name what makes the remembered address wrong'
        );
    }
}
