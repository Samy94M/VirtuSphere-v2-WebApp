<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/directory_status.php';
require_once dirname(__DIR__, 2) . '/lib/status.php';

/**
 * Traffic-light matrix for directory_controller_ampel()/directory_health_snapshot()
 * (plan section 15.2). Like AnsiblePreflightAmpelTest, $now is injected so the
 * staleness and certificate-expiry branches are deterministic, and every state
 * the functions can return is checked against the constant that drives the
 * legend/badge labels.
 */
final class DirectoryHealthSnapshotTest extends TestCase
{
    private const NOW = '2026-08-17 12:00:00';

    private function now(): int
    {
        return (int) strtotime(self::NOW . ' UTC');
    }

    private function daysAgo(float $days): string
    {
        return gmdate('Y-m-d H:i:s', $this->now() - (int) round($days * 86400));
    }

    private function daysFromNow(float $days): string
    {
        return gmdate('Y-m-d H:i:s', $this->now() + (int) round($days * 86400));
    }

    /** @param array<string,mixed> $overrides */
    private function controller(array $overrides = []): array
    {
        return $overrides + [
            'id' => 1,
            'priority' => 1,
            'host' => 'dc1.example.test',
            'port' => 636,
            'enabled' => 1,
            'validated_revision' => 3,
            'last_outcome' => VIRTUSPHERE_DIRECTORY_OUTCOME_OK,
            'last_success_at' => self::NOW,
            'certificate_not_after' => $this->daysFromNow(180),
        ];
    }

    // --- directory_controller_ampel() ---------------------------------------

    public function testDisabledOrUntestedForTheCurrentRevisionIsUnknown(): void
    {
        self::assertSame('unknown', directory_controller_ampel($this->controller(['enabled' => 0]), 3, $this->now()));
        self::assertSame('unknown', directory_controller_ampel($this->controller(['validated_revision' => 2]), 3, $this->now()));
        self::assertSame('unknown', directory_controller_ampel($this->controller(['validated_revision' => null]), 3, $this->now()));
    }

    public function testAFreshSuccessIsOk(): void
    {
        self::assertSame('ok', directory_controller_ampel($this->controller(), 3, $this->now()));
    }

    /** @return array<string,array{0:string}> */
    public static function nonOkOutcomes(): array
    {
        return [
            'unavailable' => [VIRTUSPHERE_DIRECTORY_OUTCOME_UNAVAILABLE],
            'service bind rejected' => [VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_BIND_REJECTED],
            'timeout' => [VIRTUSPHERE_DIRECTORY_OUTCOME_TIMEOUT],
            'invalid response' => [VIRTUSPHERE_DIRECTORY_OUTCOME_INVALID_RESPONSE],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonOkOutcomes')]
    public function testAnyNonOkLastOutcomeIsDangerRegardlessOfHowRecentTheSuccessWas(string $outcome): void
    {
        // The gap a schema-only "enabled + validated_revision" check misses:
        // automatic observations never clear validated_revision, only a
        // failed *manual* re-test does. A controller can stay admitted while
        // real traffic keeps failing against it.
        self::assertSame('danger', directory_controller_ampel($this->controller(['last_outcome' => $outcome]), 3, $this->now()));
    }

    public function testACertificateExpiringSoonIsWarningEvenWithAFreshSuccess(): void
    {
        $soon = $this->daysFromNow(VIRTUSPHERE_DIRECTORY_CERTIFICATE_EXPIRY_WARNING_DAYS - 1);
        self::assertSame('warning', directory_controller_ampel($this->controller(['certificate_not_after' => $soon]), 3, $this->now()));
    }

    public function testTheCertificateExpiryWindowIsInclusiveOfItsLastSecond(): void
    {
        $window = VIRTUSPHERE_DIRECTORY_CERTIFICATE_EXPIRY_WARNING_DAYS * 86400;
        $onTheEdge = gmdate('Y-m-d H:i:s', $this->now() + $window);
        $oneSecondInside = gmdate('Y-m-d H:i:s', $this->now() + $window - 1);

        self::assertSame('ok', directory_controller_ampel($this->controller(['certificate_not_after' => $onTheEdge]), 3, $this->now()));
        self::assertSame('warning', directory_controller_ampel($this->controller(['certificate_not_after' => $oneSecondInside]), 3, $this->now()));
    }

    public function testAPassingResultAgesOutIntoStale(): void
    {
        $old = $this->daysAgo(VIRTUSPHERE_DIRECTORY_OBSERVATION_STALE_AFTER_DAYS + 1);
        self::assertSame('stale', directory_controller_ampel($this->controller(['last_success_at' => $old]), 3, $this->now()));
    }

    public function testAFailureNeverAgesIntoStaleOrGrey(): void
    {
        // Greying out a known break would hide it, same rule as
        // ansible_preflight_ampel(). A stale last_success_at must not rescue
        // a controller whose most recent outcome was a failure.
        self::assertSame(
            'danger',
            directory_controller_ampel([
                'enabled' => 1,
                'validated_revision' => 3,
                'last_outcome' => VIRTUSPHERE_DIRECTORY_OUTCOME_UNAVAILABLE,
                'last_success_at' => $this->daysAgo(400),
                'certificate_not_after' => $this->daysFromNow(180),
            ], 3, $this->now())
        );
    }

    public function testCertificateExpiryIsCheckedBeforeStaleness(): void
    {
        // A controller can be both "success was a while ago" and "cert about
        // to expire"; the more actionable warning (rotate the certificate)
        // must win over the softer "no longer current evidence" state.
        self::assertSame(
            'warning',
            directory_controller_ampel([
                'enabled' => 1,
                'validated_revision' => 3,
                'last_outcome' => VIRTUSPHERE_DIRECTORY_OUTCOME_OK,
                'last_success_at' => $this->daysAgo(VIRTUSPHERE_DIRECTORY_OBSERVATION_STALE_AFTER_DAYS + 1),
                'certificate_not_after' => $this->daysFromNow(1),
            ], 3, $this->now())
        );
    }

    public function testEveryStateTheControllerFunctionCanReturnIsInItsAmpelConstant(): void
    {
        $returned = [
            directory_controller_ampel($this->controller(['enabled' => 0]), 3, $this->now()),
            directory_controller_ampel($this->controller(), 3, $this->now()),
            directory_controller_ampel($this->controller(['certificate_not_after' => $this->daysFromNow(1)]), 3, $this->now()),
            directory_controller_ampel($this->controller(['last_outcome' => VIRTUSPHERE_DIRECTORY_OUTCOME_UNAVAILABLE]), 3, $this->now()),
            directory_controller_ampel($this->controller(['last_success_at' => $this->daysAgo(400)]), 3, $this->now()),
        ];
        foreach ($returned as $state) {
            self::assertContains($state, VIRTUSPHERE_DIRECTORY_CONTROLLER_AMPEL_STATES, 'unlisted directory controller Ampel state: ' . $state);
        }
        $reachable = array_unique($returned);
        $listed = VIRTUSPHERE_DIRECTORY_CONTROLLER_AMPEL_STATES;
        sort($reachable);
        sort($listed);
        self::assertSame($listed, $reachable, 'the constant lists a state the function never returns, or misses one it does');
    }

    // --- directory_health_snapshot() ----------------------------------------

    public function testNoConfigurationIsUnknown(): void
    {
        $snapshot = directory_health_snapshot(null, [], $this->now());
        self::assertSame('unknown', $snapshot['overall']);
        self::assertSame([], $snapshot['controllers']);
    }

    /** @param array<string,mixed> $overrides */
    private function config(array $overrides = []): array
    {
        return $overrides + ['enabled' => 1, 'revision' => 3, 'automatic_bind_blocked_revision' => null];
    }

    public function testADisabledDraftIsUnknownEvenWithUsableControllers(): void
    {
        $snapshot = directory_health_snapshot($this->config(['enabled' => 0]), [$this->controller()], $this->now());
        self::assertSame('unknown', $snapshot['overall']);
    }

    public function testARevisionWideBindBlockIsDangerRegardlessOfControllerState(): void
    {
        $snapshot = directory_health_snapshot(
            $this->config(['automatic_bind_blocked_revision' => 3]),
            [$this->controller()],
            $this->now()
        );
        self::assertSame('danger', $snapshot['overall']);
    }

    public function testZeroUsableControllersIsDanger(): void
    {
        self::assertSame('danger', directory_health_snapshot($this->config(), [], $this->now())['overall']);
        self::assertSame(
            'danger',
            directory_health_snapshot($this->config(), [$this->controller(['enabled' => 0])], $this->now())['overall']
        );
    }

    public function testEveryUsableControllerOkIsOk(): void
    {
        $snapshot = directory_health_snapshot(
            $this->config(),
            [$this->controller(['id' => 1]), $this->controller(['id' => 2, 'host' => 'dc2.example.test'])],
            $this->now()
        );
        self::assertSame('ok', $snapshot['overall']);
    }

    public function testOneWorkingAndOneDownIsWarningNeverDanger(): void
    {
        // The plan's own wording: "mindestens ein Controller funktioniert,
        // aber ein anderer ist ausgefallen" is a degraded pool, not an outage.
        $snapshot = directory_health_snapshot(
            $this->config(),
            [
                $this->controller(['id' => 1]),
                $this->controller(['id' => 2, 'host' => 'dc2.example.test', 'last_outcome' => VIRTUSPHERE_DIRECTORY_OUTCOME_UNAVAILABLE]),
            ],
            $this->now()
        );
        self::assertSame('warning', $snapshot['overall']);
    }

    public function testAllUsableControllersCurrentlyFailingIsDangerEvenThoughSchemaCallsThemUsable(): void
    {
        $snapshot = directory_health_snapshot(
            $this->config(),
            [$this->controller(['last_outcome' => VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_BIND_REJECTED])],
            $this->now()
        );
        self::assertSame('danger', $snapshot['overall']);
    }

    public function testAStaleOrExpiringControllerAloneIsWarningNotOk(): void
    {
        $stale = directory_health_snapshot(
            $this->config(),
            [$this->controller(['last_success_at' => $this->daysAgo(VIRTUSPHERE_DIRECTORY_OBSERVATION_STALE_AFTER_DAYS + 1)])],
            $this->now()
        );
        self::assertSame('warning', $stale['overall'], 'the sole controller is stale, so the pool cannot claim it is fully green');

        $expiring = directory_health_snapshot(
            $this->config(),
            [$this->controller(['certificate_not_after' => $this->daysFromNow(1)])],
            $this->now()
        );
        self::assertSame('warning', $expiring['overall']);
    }

    public function testEveryStateTheOverallFunctionCanReturnIsInItsAmpelConstant(): void
    {
        $returned = [
            directory_health_snapshot(null, [], $this->now())['overall'],
            directory_health_snapshot($this->config(['automatic_bind_blocked_revision' => 3]), [$this->controller()], $this->now())['overall'],
            directory_health_snapshot($this->config(), [], $this->now())['overall'],
            directory_health_snapshot($this->config(), [$this->controller()], $this->now())['overall'],
            directory_health_snapshot(
                $this->config(),
                [$this->controller(['id' => 1]), $this->controller(['id' => 2, 'last_outcome' => VIRTUSPHERE_DIRECTORY_OUTCOME_UNAVAILABLE])],
                $this->now()
            )['overall'],
        ];
        foreach ($returned as $state) {
            self::assertContains($state, VIRTUSPHERE_DIRECTORY_AMPEL_STATES, 'unlisted directory overall Ampel state: ' . $state);
        }
        $reachable = array_unique($returned);
        $listed = VIRTUSPHERE_DIRECTORY_AMPEL_STATES;
        sort($reachable);
        sort($listed);
        self::assertSame($listed, $reachable, 'the constant lists a state the function never returns, or misses one it does');
    }
}
