<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/constants.php';
require_once dirname(__DIR__, 2) . '/lib/status.php';

/**
 * Which endpoint writes which VM stage. The most-read explanation in the whole
 * portal had no checker at all, and it was wrong in eight sentences.
 *
 * What the help promised, and what the code does:
 *
 *  - "5/5 OS Installed means the client chain is finished." The FIRST client call
 *    (mecm-api.php getDeviceInfos, from client_getinfo.ps1) writes it, before
 *    hostname, IP and disks have run at all.
 *  - "The ResourceID feedback sets 3/5." It sets 4/5. 3/5 comes from the Ansible
 *    MAC callback, with no MECM involvement whatsoever.
 *  - Two sentences described `initializing` as the stage of a newly created VM.
 *    No code path writes that state; the column default is `ready`/2-5.
 *  - Two sentences sent the admin at 3/5 to the client phases, which are empty
 *    there by design.
 *
 * Every one of those is a sentence somebody learns as truth and then debugs
 * against. This test pins the mapping at its source, so the next change to a
 * writer fails the build instead of quietly making the help lie again.
 */
final class StatusWriterContractTest extends TestCase
{
    /**
     * stage => [file that writes it, the lifecycle state it writes]. One writer
     * per stage: two would make the stage meaningless as a diagnosis.
     */
    private const WRITERS = [
        VIRTUSPHERE_STATUS_DEPLOYED => ['db_importMAC.php', VIRTUSPHERE_LIFECYCLE_DEPLOYED],
        VIRTUSPHERE_STATUS_OS_INSTALLING => ['mecm_updateid.php', VIRTUSPHERE_LIFECYCLE_OS_INSTALLING],
        VIRTUSPHERE_STATUS_OS_INSTALLED => ['mecm-api.php', VIRTUSPHERE_LIFECYCLE_OS_INSTALLED],
    ];

    private function source(string $file): string
    {
        $path = dirname(__DIR__, 2) . '/' . $file;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** @return iterable<string, array{0:string, 1:string, 2:string}> */
    public static function writers(): iterable
    {
        foreach (self::WRITERS as $stage => [$file, $lifecycle]) {
            yield $stage . ' <- ' . $file => [$stage, $file, $lifecycle];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('writers')]
    public function testTheNamedEndpointWritesThatStage(string $stage, string $file, string $lifecycle): void
    {
        $source = $this->source($file);
        $constant = array_search($lifecycle, [
            'VIRTUSPHERE_LIFECYCLE_DEPLOYED' => VIRTUSPHERE_LIFECYCLE_DEPLOYED,
            'VIRTUSPHERE_LIFECYCLE_OS_INSTALLING' => VIRTUSPHERE_LIFECYCLE_OS_INSTALLING,
            'VIRTUSPHERE_LIFECYCLE_OS_INSTALLED' => VIRTUSPHERE_LIFECYCLE_OS_INSTALLED,
        ], true);
        self::assertIsString($constant);

        self::assertStringContainsString(
            $constant,
            $source,
            sprintf('%s is documented as the writer of "%s" but does not reference %s.', $file, $stage, $constant)
        );
    }

    /**
     * The stage a VM starts at. The help called it 1/5 Initializing twice, and no
     * code path writes that state: a fresh row is 2/5 by column default.
     */
    public function testInitializingIsWrittenByNobodyAndIsNotTheStartingStage(): void
    {
        $schema = (string) file_get_contents(dirname(__DIR__, 4) . '/Docker/mysql/mysql-init/struktur.sql');
        if ($schema === '') {
            self::markTestSkipped('Repo root not visible; struktur.sql only exists outside the container mount.');
        }

        self::assertMatchesRegularExpression(
            "/vm_status VARCHAR\(64\) NOT NULL DEFAULT '" . preg_quote(VIRTUSPHERE_STATUS_REGISTERED, '/') . "'/",
            $schema,
            'a new VM starts at 2/5; the help must not describe 1/5 as the starting stage'
        );
        self::assertMatchesRegularExpression(
            "/lifecycle_state ENUM\([^)]*\) NOT NULL DEFAULT '" . preg_quote(VIRTUSPHERE_LIFECYCLE_READY, '/') . "'/",
            $schema,
            'the lifecycle default is `ready`, not `initializing`'
        );

        // And nothing writes it. Scanned over the endpoints and lib, not just one
        // file, because "somebody sets it somewhere" is exactly the belief to kill.
        $writers = [];
        foreach ($this->phpSources() as $path => $source) {
            if (str_contains($source, 'VIRTUSPHERE_LIFECYCLE_INITIALIZING') || str_contains($source, 'VIRTUSPHERE_STATUS_INITIALIZING')) {
                $writers[] = basename($path);
            }
        }

        // The constant may be REFERENCED (the value set, the legacy fallback in
        // virtusphere_legacy_status_from_states, the badge map). What it must not
        // have is a state write, i.e. an appearance next to repo_set_vm_state.
        foreach ($this->phpSources() as $path => $source) {
            if (!str_contains($source, 'VIRTUSPHERE_LIFECYCLE_INITIALIZING')) {
                continue;
            }
            self::assertDoesNotMatchRegularExpression(
                '/repo_set_vm_state[a-z_]*\([^;]*VIRTUSPHERE_LIFECYCLE_INITIALIZING/s',
                $source,
                basename($path) . ' writes the initializing state; the help says no code path does'
            );
        }

        self::assertNotSame([], $writers, 'the constant vanished entirely; this scan would then prove nothing');
    }

    /**
     * The stage order the monotonicity guard depends on. If a rank inverts, the
     * guard silently starts blocking real progress instead of fake regressions.
     */
    public function testTheStageOrderMatchesTheLifecycleRanks(): void
    {
        $expected = [
            VIRTUSPHERE_LIFECYCLE_READY,
            VIRTUSPHERE_LIFECYCLE_DEPLOYING,
            VIRTUSPHERE_LIFECYCLE_DEPLOYED,
            VIRTUSPHERE_LIFECYCLE_OS_INSTALLING,
            VIRTUSPHERE_LIFECYCLE_OS_INSTALLED,
        ];

        $previous = -1;
        foreach ($expected as $state) {
            $rank = virtusphere_lifecycle_rank($state);
            self::assertGreaterThan($previous, $rank, $state . ' does not outrank its predecessor');
            $previous = $rank;
        }

        // `failed` shares the bottom: a failed VM registering again is progress.
        self::assertSame(
            virtusphere_lifecycle_rank(VIRTUSPHERE_LIFECYCLE_INITIALIZING),
            virtusphere_lifecycle_rank(VIRTUSPHERE_LIFECYCLE_FAILED),
            'a failed VM must be able to move forward again, or a retry strands it'
        );
    }

    /** @return array<string, string> */
    private function phpSources(): array
    {
        $root = dirname(__DIR__, 2);
        $paths = array_merge(
            glob($root . '/*.php') ?: [],
            glob($root . '/lib/*.php') ?: [],
            glob($root . '/lib/repo/*.php') ?: [],
            glob($root . '/portal/*.php') ?: []
        );
        self::assertNotSame([], $paths);

        $sources = [];
        foreach ($paths as $path) {
            $sources[$path] = (string) file_get_contents($path);
        }

        return $sources;
    }
}
