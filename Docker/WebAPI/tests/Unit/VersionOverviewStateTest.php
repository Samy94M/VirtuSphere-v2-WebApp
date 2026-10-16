<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/version_overview.php';

final class VersionOverviewStateTest extends TestCase
{
    private const SEEN = '2026-10-16 08:00:00';
    private const CHECKED = '2026-10-16 07:30:00';

    /** @return array<string,mixed> */
    private function heartbeat(string $identifier, ?string $seenAt = self::SEEN): array
    {
        return ['last_script_version' => $identifier, 'last_seen_at' => $seenAt];
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function preflight(array $overrides = []): array
    {
        return $overrides + [
            'last_status' => VIRTUSPHERE_ANSIBLE_PREFLIGHT_STATUS_OK,
            'last_component' => null,
            'last_checked_at' => self::CHECKED,
            'evidence_current' => true,
        ];
    }

    /**
     * @param array<string,mixed> $result either result shape of the state functions
     * @return array{string, ?string}
     */
    private function stateAndReason(array $result): array
    {
        return [(string) $result['state'], $result['reason'] === null ? null : (string) $result['reason']];
    }

    public function testSourceWithoutHeartbeatHasNoReport(): void
    {
        $result = version_mecm_source_state('2026.10.1', null);

        self::assertSame([VIRTUSPHERE_VERSION_STATE_UNKNOWN, 'no_report'], $this->stateAndReason($result));
        self::assertNull($result['identifier']);
        self::assertNull($result['reported_at']);
    }

    /** @return array<string, array{string}> */
    public static function emptyIdentifiers(): array
    {
        return ['empty' => [''], 'blank' => ['   ']];
    }

    #[DataProvider('emptyIdentifiers')]
    public function testEmptyIdentifierIsNoRelease(string $identifier): void
    {
        $result = version_mecm_source_state('2026.10.1', $this->heartbeat($identifier));

        self::assertSame([VIRTUSPHERE_VERSION_STATE_UNKNOWN, 'no_release'], $this->stateAndReason($result));
        self::assertNull($result['identifier']);
        self::assertSame(self::SEEN, $result['reported_at'], 'the report time is kept even without an identifier');
    }

    /** @return array<string, array{string}> */
    public static function identifiersWithoutRelease(): array
    {
        return [
            'old commit form' => ['device-sync/2.1+53ba24a'],
            'unknown placeholder' => ['device-sync/2.1+unbekannt'],
            'no manifest placeholder' => ['device-sync/2.1+ohne-manifest'],
            'impossible month' => ['device-sync/2.1+2026.13.1'],
            'no plus sign' => ['device-sync/2.1'],
        ];
    }

    #[DataProvider('identifiersWithoutRelease')]
    public function testIdentifierWithoutReleaseIsNoRelease(string $identifier): void
    {
        $result = version_mecm_source_state('2026.10.1', $this->heartbeat($identifier));

        self::assertSame([VIRTUSPHERE_VERSION_STATE_UNKNOWN, 'no_release'], $this->stateAndReason($result));
        self::assertNull($result['release']);
        self::assertSame($identifier, $result['identifier']);
    }

    public function testDeviatingFileIsReportedBeforeAnyReleaseComparison(): void
    {
        // The release differs from the portal, but the deviating file is the finding that comes first.
        $result = version_mecm_source_state('2026.10.1', $this->heartbeat('packages-sync/2.0+2026.09.1-abw'));

        self::assertSame([VIRTUSPHERE_VERSION_STATE_DEVIATES, 'files'], $this->stateAndReason($result));
        self::assertSame('2026.09.1', $result['release']);
    }

    public function testDeviatingFileWithAMatchingReleaseIsStillFiles(): void
    {
        $result = version_mecm_source_state('2026.10.1', $this->heartbeat('packages-sync/2.0+2026.10.1-abw'));

        self::assertSame([VIRTUSPHERE_VERSION_STATE_DEVIATES, 'files'], $this->stateAndReason($result));
    }

    public function testDeviatingFileWithAnOldFormIsFilesWithoutRelease(): void
    {
        $result = version_mecm_source_state('2026.10.1', $this->heartbeat('device-sync/2.1+53ba24a-abw'));

        self::assertSame([VIRTUSPHERE_VERSION_STATE_DEVIATES, 'files'], $this->stateAndReason($result));
        self::assertNull($result['release']);
    }

    public function testMissingPortalReleaseIsPortalUnknown(): void
    {
        $result = version_mecm_source_state(null, $this->heartbeat('packages-sync/2.0+2026.10.1'));

        self::assertSame([VIRTUSPHERE_VERSION_STATE_UNKNOWN, 'portal_unknown'], $this->stateAndReason($result));
        self::assertSame('2026.10.1', $result['release'], 'the component release is still shown');
    }

    public function testDifferentReleaseDeviates(): void
    {
        $result = version_mecm_source_state('2026.10.1', $this->heartbeat('packages-sync/2.0+2026.10.2'));

        self::assertSame([VIRTUSPHERE_VERSION_STATE_DEVIATES, 'release'], $this->stateAndReason($result));
        self::assertSame('2026.10.2', $result['release']);
    }

    public function testMatchingReleaseIsOkWithoutReason(): void
    {
        $result = version_mecm_source_state('2026.10.1', $this->heartbeat('packages-sync/2.0+2026.10.1'));

        self::assertSame([VIRTUSPHERE_VERSION_STATE_OK, null], $this->stateAndReason($result));
        self::assertSame('2026.10.1', $result['release']);
        self::assertSame('packages-sync/2.0+2026.10.1', $result['identifier']);
        self::assertSame(self::SEEN, $result['reported_at']);
    }

    public function testIdentifierIsStoredTrimmed(): void
    {
        $result = version_mecm_source_state('2026.10.1', $this->heartbeat("  packages-sync/2.0+2026.10.1 \n"));

        self::assertSame([VIRTUSPHERE_VERSION_STATE_OK, null], $this->stateAndReason($result));
        self::assertSame('packages-sync/2.0+2026.10.1', $result['identifier']);
    }

    public function testMissingReportTimeIsNull(): void
    {
        $result = version_mecm_source_state('2026.10.1', $this->heartbeat('packages-sync/2.0+2026.10.1', null));

        self::assertSame([VIRTUSPHERE_VERSION_STATE_OK, null], $this->stateAndReason($result));
        self::assertNull($result['reported_at']);
    }

    public function testEveryMigrationAppliedIsOk(): void
    {
        self::assertSame(
            ['state' => VIRTUSPHERE_VERSION_STATE_OK, 'last_applied' => '0002_b', 'pending' => 0],
            version_database_state(['0001_a', '0002_b'], ['0002_b', '0001_a'])
        );
    }

    public function testMissingMigrationDeviates(): void
    {
        self::assertSame(
            ['state' => VIRTUSPHERE_VERSION_STATE_DEVIATES, 'last_applied' => '0003_c', 'pending' => 1],
            version_database_state(['0001_a', '0002_b', '0003_c'], ['0001_a', '0003_c'])
        );
    }

    public function testNothingAppliedIsEverythingPending(): void
    {
        self::assertSame(
            ['state' => VIRTUSPHERE_VERSION_STATE_DEVIATES, 'last_applied' => null, 'pending' => 2],
            version_database_state(['0001_a', '0002_b'], [])
        );
    }

    public function testLastAppliedFollowsTheCodeOrderNotTheRecordOrder(): void
    {
        self::assertSame(
            ['state' => VIRTUSPHERE_VERSION_STATE_DEVIATES, 'last_applied' => '0003_c', 'pending' => 1],
            version_database_state(['0001_a', '0002_b', '0003_c'], ['0003_c', '0002_b'])
        );
    }

    public function testAppliedNamesOutsideTheCodeAreIgnored(): void
    {
        self::assertSame(
            ['state' => VIRTUSPHERE_VERSION_STATE_OK, 'last_applied' => '0001_a', 'pending' => 0],
            version_database_state(['0001_a'], ['0001_a', '9999_removed'])
        );
        self::assertSame(
            ['state' => VIRTUSPHERE_VERSION_STATE_DEVIATES, 'last_applied' => null, 'pending' => 2],
            version_database_state(['0001_a', '0002_b'], ['9999_removed'])
        );
    }

    public function testEmptyCodeListIsUnknown(): void
    {
        self::assertSame(
            ['state' => VIRTUSPHERE_VERSION_STATE_UNKNOWN, 'last_applied' => null, 'pending' => 0],
            version_database_state([], ['0001_a'])
        );
    }

    public function testHostThatWasNeverTestedIsNotTested(): void
    {
        self::assertSame(
            ['state' => VIRTUSPHERE_VERSION_STATE_UNKNOWN, 'reason' => 'not_tested', 'checked_at' => null],
            version_ansible_host_state(null)
        );
    }

    public function testEvidenceForAnEarlierConfigurationIsStaleEvidence(): void
    {
        $result = version_ansible_host_state($this->preflight(['evidence_current' => false]));

        self::assertSame(
            ['state' => VIRTUSPHERE_VERSION_STATE_UNKNOWN, 'reason' => 'stale_evidence', 'checked_at' => self::CHECKED],
            $result
        );
    }

    public function testMissingEvidenceFlagIsStaleEvidence(): void
    {
        $row = $this->preflight();
        unset($row['evidence_current']);

        self::assertSame([VIRTUSPHERE_VERSION_STATE_UNKNOWN, 'stale_evidence'], $this->stateAndReason(version_ansible_host_state($row)));
    }

    public function testStaleEvidenceBeatsAFailedVersionProbe(): void
    {
        $row = $this->preflight([
            'evidence_current' => false,
            'last_status' => VIRTUSPHERE_ANSIBLE_PREFLIGHT_STATUS_FAILED,
            'last_component' => 'runtime-versions',
        ]);

        self::assertSame([VIRTUSPHERE_VERSION_STATE_UNKNOWN, 'stale_evidence'], $this->stateAndReason(version_ansible_host_state($row)));
    }

    public function testGreenChainIsOk(): void
    {
        self::assertSame(
            ['state' => VIRTUSPHERE_VERSION_STATE_OK, 'reason' => null, 'checked_at' => self::CHECKED],
            version_ansible_host_state($this->preflight())
        );
    }

    public function testWarningStillMeansTheChainWasGreen(): void
    {
        $row = $this->preflight([
            'last_status' => VIRTUSPHERE_ANSIBLE_PREFLIGHT_STATUS_WARNING,
            'last_component' => 'allowlist',
        ]);

        self::assertSame([VIRTUSPHERE_VERSION_STATE_OK, null], $this->stateAndReason(version_ansible_host_state($row)));
    }

    public function testFailedVersionProbeDeviates(): void
    {
        $row = $this->preflight([
            'last_status' => VIRTUSPHERE_ANSIBLE_PREFLIGHT_STATUS_FAILED,
            'last_component' => 'runtime-versions',
        ]);

        self::assertSame(
            ['state' => VIRTUSPHERE_VERSION_STATE_DEVIATES, 'reason' => 'runtime_versions', 'checked_at' => self::CHECKED],
            version_ansible_host_state($row)
        );
    }

    /** @return array<string, array{?string}> */
    public static function otherFailedLinks(): array
    {
        return [
            'portal' => ['portal'],
            'ssh' => ['ssh'],
            'no component named' => [null],
        ];
    }

    #[DataProvider('otherFailedLinks')]
    public function testAnyOtherFailedLinkIsChainFailed(?string $component): void
    {
        $row = $this->preflight([
            'last_status' => VIRTUSPHERE_ANSIBLE_PREFLIGHT_STATUS_FAILED,
            'last_component' => $component,
        ]);

        self::assertSame([VIRTUSPHERE_VERSION_STATE_UNKNOWN, 'chain_failed'], $this->stateAndReason(version_ansible_host_state($row)));
    }

    public function testUnrecognisedStatusIsNotTestedButKeepsItsTime(): void
    {
        $row = $this->preflight(['last_status' => 'pending']);

        self::assertSame(
            ['state' => VIRTUSPHERE_VERSION_STATE_UNKNOWN, 'reason' => 'not_tested', 'checked_at' => self::CHECKED],
            version_ansible_host_state($row)
        );
    }
}
