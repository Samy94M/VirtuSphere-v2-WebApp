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

    /**
     * Every stage number a catalog sentence teaches, pinned as `module.key` =>
     * sorted unique stages. The writer map above is the truth these claims are
     * checked against by a human once, here; this table then keeps the catalogs
     * honest mechanically. Derivations that made sentences wrong before:
     *
     *  - the ResourceID feedback (mecm_updateid.php) writes 4/5, not 3/5,
     *  - a dead MECM strands a VM at 3/5 (Ansible still delivers the MAC),
     *    never at 2/5,
     *  - "the VM works on its own" and "stuck in the Windows installation"
     *    start at 4/5; at 3/5 the client phases are empty by design.
     *
     * A key that newly mentions a stage fails here until it is classified; a
     * classified key that stops mentioning stages fails too (dead pin). Both
     * locales must teach the same numbers, or one of them lies alone.
     */
    private const STAGE_CLAIMS = [
        'help.status_1' => [1, 2],
        'help.status_2' => [2],
        'help.status_3' => [3],
        'help.status_4' => [4],
        'help.status_5' => [5],
        'help.status_stuck_1' => [2],
        'help.status_stuck_2' => [3],
        'help.status_stuck_3' => [4, 5],
        'help.stack_a6_p3' => [4],
        'help.stack_a7_p3' => [5],
        'help.stack_a11_li1' => [3],
        'help.settings_api_p1' => [2],
        'help_system_status.firstaid_step3' => [4, 5],
        'help_system_status.system_status_source_1' => [3],
        'help_system_status.clientphases_p1' => [4],
    ];

    private const LOCALES = ['de', 'en'];

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

    /**
     * The stage numbers a catalog string teaches, as sorted unique ints.
     * Word-boundary guarded so a plain fraction elsewhere cannot match.
     *
     * @return list<int>
     */
    private static function claimedStages(string $text): array
    {
        preg_match_all('#(?<![\d.,])([1-5])/5(?!\d)#', $text, $matches);
        $stages = array_values(array_unique(array_map('intval', $matches[1])));
        sort($stages);

        return $stages;
    }

    /**
     * @return array<string, array<string, string>> locale => "module.key" => text
     */
    private function catalogStrings(): array
    {
        $byLocale = [];
        foreach (self::LOCALES as $locale) {
            // Glob, never a filename list: a catalog split must not silently
            // take its keys out of this contract (i18n rule, health-matrix
            // lesson). The stage vocabulary may spread to any catalog.
            $paths = glob(dirname(__DIR__, 2) . '/lang/' . $locale . '/*.php') ?: [];
            self::assertNotSame([], $paths, 'locale ' . $locale . ' has no catalogs; this scan would then prove nothing');
            foreach ($paths as $path) {
                $module = basename($path, '.php');
                $strings = require $path;
                self::assertIsArray($strings);
                foreach ($strings as $key => $text) {
                    if (is_string($text)) {
                        $byLocale[$locale][$module . '.' . $key] = $text;
                    }
                }
            }
        }

        return $byLocale;
    }

    /**
     * Every stage number in every catalog matches its classified claim, in both
     * locales; unclassified stage mentions and dead pins fail the build.
     */
    public function testCatalogStageMentionsMatchTheClassifiedClaims(): void
    {
        $byLocale = $this->catalogStrings();

        foreach (self::LOCALES as $locale) {
            $seen = [];
            foreach ($byLocale[$locale] as $qualifiedKey => $text) {
                $stages = self::claimedStages($text);
                if ($stages === []) {
                    continue;
                }
                $seen[$qualifiedKey] = $stages;
                self::assertArrayHasKey(
                    $qualifiedKey,
                    self::STAGE_CLAIMS,
                    sprintf('[%s] %s mentions stage(s) %s but is not classified in STAGE_CLAIMS; decide which stages the sentence must teach (writer map above is the truth).', $locale, $qualifiedKey, implode(',', $stages))
                );
                self::assertSame(
                    self::STAGE_CLAIMS[$qualifiedKey],
                    $stages,
                    sprintf('[%s] %s teaches stage(s) %s but the writers put this sentence at %s.', $locale, $qualifiedKey, implode(',', $stages), implode(',', self::STAGE_CLAIMS[$qualifiedKey]))
                );
            }

            foreach (self::STAGE_CLAIMS as $qualifiedKey => $stages) {
                self::assertArrayHasKey(
                    $qualifiedKey,
                    $seen,
                    sprintf('[%s] %s is classified with stage(s) %s but the catalog no longer mentions any stage; drop the dead pin or restore the sentence.', $locale, $qualifiedKey, implode(',', $stages))
                );
            }
        }
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
