<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/lang.php';
require_once dirname(__DIR__, 2) . '/lib/status.php';
require_once dirname(__DIR__, 2) . '/lib/credentials_status.php';

/**
 * Finding K5 of the text review (P2a-1, P2a-2; TS1-K3): one owner for how long
 * a passed full test stays evidence, read by the Ampel and by every sentence
 * that names the window. The cadence line used to say the fixed minimum while
 * the Ampel waited up to twice the interval, and the System status printed the
 * window as "8.3333333333333" days.
 */
final class AnsibleEvidenceWindowTest extends TestCase
{
    private string $previousLocale;

    protected function setUp(): void
    {
        $this->previousLocale = Lang::locale();
    }

    protected function tearDown(): void
    {
        Lang::load($this->previousLocale);
    }

    /** @return array<string, array{0:int,1:int}> interval hours => whole days */
    public static function windows(): array
    {
        $min = VIRTUSPHERE_ANSIBLE_PREFLIGHT_STALE_AFTER_DAYS;

        return [
            'off (0 h) keeps the fixed days' => [0, $min],
            'the default interval' => [VIRTUSPHERE_ANSIBLE_TEST_INTERVAL_HOURS_DEFAULT, max($min, intdiv(2 * VIRTUSPHERE_ANSIBLE_TEST_INTERVAL_HOURS_DEFAULT, 24))],
            'exactly half the fixed days' => [$min * 12, $min],
            'one hour past it, not a multiple of 12 h' => [$min * 12 + 1, $min],
            '100 h is 8 days 8 hours, rounded down' => [100, 8],
            'a multiple of 12 h' => [108, 9],
            'the maximum interval' => [VIRTUSPHERE_ANSIBLE_TEST_INTERVAL_HOURS_MAX, intdiv(2 * VIRTUSPHERE_ANSIBLE_TEST_INTERVAL_HOURS_MAX, 24)],
        ];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('windows')]
    public function testTheWindowInWholeDaysIsRoundedDown(int $hours, int $days): void
    {
        self::assertSame($days, ansible_preflight_evidence_valid_days($hours));
        self::assertSame(
            max(VIRTUSPHERE_ANSIBLE_PREFLIGHT_STALE_AFTER_DAYS * 86400, 2 * $hours * 3600),
            ansible_preflight_evidence_valid_seconds($hours)
        );
        // The stated days never exceed the real window: "valid for N days"
        // and "more than N days old" both stay true.
        self::assertLessThanOrEqual(ansible_preflight_evidence_valid_seconds($hours), $days * 86400);
    }

    #[PHPUnit\Framework\Attributes\DataProvider('windows')]
    public function testTheAmpelTurnsStaleExactlyAfterTheWindow(int $hours, int $days): void
    {
        $now = 1_800_000_000;
        $window = ansible_preflight_evidence_valid_seconds($hours);
        $state = static fn (int $age): array => [
            'last_status' => 'ok',
            'last_checked_at' => gmdate('Y-m-d H:i:s', $now - $age),
            'evidence_current' => true,
        ];

        self::assertSame('ok', ansible_preflight_ampel($state($window), $now, $hours));
        self::assertSame('stale', ansible_preflight_ampel($state($window + 1), $now, $hours));
        // "More than :days days old" holds at the first stale second.
        self::assertGreaterThan($days * 86400, $window + 1);
    }

    public function testTheCadenceLineNamesTheWindowTheAmpelApplies(): void
    {
        foreach (Lang::LOCALES as $locale) {
            Lang::load($locale);
            self::assertSame(
                __t('ansible_test.cadence', ['hours' => 100, 'days' => 8]),
                credential_cadence_ansible(100),
                $locale
            );
            self::assertSame(
                __t('ansible_test.cadence_off', ['hours' => 0, 'days' => VIRTUSPHERE_ANSIBLE_PREFLIGHT_STALE_AFTER_DAYS]),
                credential_cadence_ansible(0),
                $locale
            );
            self::assertStringContainsString('14', credential_cadence_ansible(VIRTUSPHERE_ANSIBLE_TEST_INTERVAL_HOURS_MAX), $locale);
        }
    }

    public function testNoCallerComputesTheWindowItself(): void
    {
        // The two former copies: the cadence passed the fixed constant, the
        // stale detail divided by 24 into a float.
        $root = dirname(__DIR__, 2);
        foreach (['lib/credentials_status.php', 'lib/system_status_ansible_panels.php'] as $file) {
            $source = (string) file_get_contents($root . '/' . $file);
            self::assertStringContainsString('ansible_preflight_evidence_valid_days(', $source, $file);
            self::assertStringNotContainsString("'days' => VIRTUSPHERE_ANSIBLE_PREFLIGHT_STALE_AFTER_DAYS", $source, $file);
            self::assertDoesNotMatchRegularExpression('#interval_hours\'\]\s*/\s*24#', $source, $file);
        }
    }
}
