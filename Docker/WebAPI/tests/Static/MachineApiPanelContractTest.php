<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The Machine-API settings panel is inbound only (ADR-0018 amendment): MECM,
 * Ansible and the deploy clients call *into* the portal, gated by the allowlist
 * and the report token. The portal no longer reaches *out* to MECM, so the panel
 * carries no host, port or reachability probe; the reported MECM state lives on
 * System status.
 *
 * The empty allowlist is the sharp case. machine_api_ip_allowed() is
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
        self::assertStringContainsString('settings.allowlist_empty_warning', $source);
    }

    public function testThePanelHasNoOutboundPathTargetOrPort(): void
    {
        // The 445 reachability probe is gone: no probe action, no host/port field,
        // no mode radios, no probe constants.
        $source = $this->settings();
        self::assertStringNotContainsString('save_probe', $source);
        self::assertStringNotContainsString('probe_mode', $source);
        self::assertStringNotContainsString('probe_host', $source);
        self::assertStringNotContainsString('probe_port', $source);
        self::assertStringNotContainsString('VIRTUSPHERE_MECM_PROBE', $source);
        self::assertStringNotContainsString('mecm_probe', $source);
    }

    public function testTheDirectionsAndStatusHintAreNamedInThePanel(): void
    {
        $source = $this->settings();
        self::assertStringContainsString('settings.machine_api_directions', $source);
        // The reported MECM state is pointed at System status, not shown here.
        self::assertStringContainsString('settings.machine_api_status_hint', $source);
        self::assertStringContainsString('settings.machine_api_status_link', $source);
    }

    public function testTheDirectionsSayInboundOnly(): void
    {
        foreach (['de' => 'eingehend', 'en' => 'inbound'] as $locale => $word) {
            $directions = mb_strtolower($this->catalog($locale)['machine_api_directions']);
            self::assertStringContainsString(
                $word,
                $directions,
                $locale . ': the Machine-API directions must say the traffic is inbound'
            );
        }
    }

    public function testTheNewTextsExistInBothLocalesAndSayWhatTheyMust(): void
    {
        foreach (['de', 'en'] as $locale) {
            $catalog = $this->catalog($locale);
            foreach (['machine_api_directions', 'allowlist_empty_warning', 'machine_api_status_hint'] as $key) {
                self::assertArrayHasKey($key, $catalog, $locale . ' is missing ' . $key);
                // Plain-language explanations for rotating admin staff, not labels.
                self::assertGreaterThan(80, mb_strlen($catalog[$key]), $locale . '.' . $key . ' is too terse to explain anything');
            }
            // The probe vocabulary must be gone from both locales.
            self::assertArrayNotHasKey('probe_title', $catalog, $locale . ' still has a probe heading');
            self::assertArrayNotHasKey('probe_mode_auto', $catalog, $locale . ' still has probe mode text');
        }
    }
}
