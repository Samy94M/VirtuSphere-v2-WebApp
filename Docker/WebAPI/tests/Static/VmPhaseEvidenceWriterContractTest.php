<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/constants.php';
require_once dirname(__DIR__, 2) . '/lib/vm_phase_evidence.php';

/**
 * VB4: behind a worker mark the phase reads the stored x/5 value as the last
 * evidence (lib/vm_phase_evidence.php). That reading is true only while two
 * things hold for every statement that updates a VM row:
 *
 *  - the stored x/5 value is written only together with the lifecycle, by a
 *    writer that delivers evidence, and
 *  - a writer that sets the lifecycle without the x/5 value is one of the two
 *    worker owners that set a mark or take it back (the journal).
 *
 * The statements are derived from every PHP source of the application, so a
 * new writer fails here until it is one of the two kinds. Only the two owners
 * of the marks are named, because naming them is the contract.
 */
final class VmPhaseEvidenceWriterContractTest extends TestCase
{
    /** The owners of the worker marks: they set `deploying`/`failed` and restore the journal's prestate. */
    private const MARK_OWNERS = [
        'lib/deploy_worker_vm_journal.php',
        'lib/deploy_worker_vm_state.php',
    ];

    /**
     * Every `UPDATE deploy_vms ... SET ... WHERE` in the application sources:
     * list of [file, assigned columns].
     *
     * @return list<array{0:string,1:list<string>}>
     */
    private static function vmUpdates(): array
    {
        $root = dirname(__DIR__, 2);
        $updates = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if ($file->getExtension() !== 'php' || preg_match('#^(tests|vendor)/#', $relative) === 1) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            // The optional alias covers `UPDATE deploy_vms v SET` and a joined update.
            if (preg_match_all('/UPDATE\s+deploy_vms\b(.*?)\bSET\b(.*?)\bWHERE\b/s', $source, $matches, PREG_SET_ORDER) === false) {
                self::fail('The statement scan failed for ' . $relative);
            }
            foreach ($matches as $match) {
                preg_match_all('/(?:\b[a-z]+\.)?\b([a-z_]+)\s*=(?!=)/', $match[2], $columns);
                $updates[] = [$relative, array_values(array_unique($columns[1]))];
            }
        }

        return $updates;
    }

    public function testTheStoredStageIsWrittenOnlyTogetherWithTheLifecycle(): void
    {
        $stageWriters = 0;
        foreach (self::vmUpdates() as [$file, $columns]) {
            if (!in_array('vm_status', $columns, true)) {
                continue;
            }
            $stageWriters++;
            self::assertContains('lifecycle_state', $columns, $file . ' writes the x/5 value without a lifecycle');
            self::assertNotContains($file, self::MARK_OWNERS, $file . ' sets a worker mark and must leave the x/5 value alone');
        }

        // Zero matches would prove nothing: MAC import, reset and the three forms of repo_set_vm_state().
        self::assertGreaterThanOrEqual(5, $stageWriters, 'the scan no longer finds the evidence writers');
    }

    public function testALifecycleWriteWithoutTheStoredStageBelongsToAMarkOwner(): void
    {
        $markWrites = [];
        foreach (self::vmUpdates() as [$file, $columns]) {
            if (!in_array('lifecycle_state', $columns, true) || in_array('vm_status', $columns, true)) {
                continue;
            }
            $markWrites[$file] = true;
            self::assertContains(
                $file,
                self::MARK_OWNERS,
                $file . ' writes a lifecycle without the x/5 value. Behind `deploying` and `failed` the phase reads that value as the last evidence, so '
                . 'an evidence writer must store both, and a new mark needs a decision in lib/vm_phase_evidence.php.'
            );
        }

        $found = array_keys($markWrites);
        sort($found);
        self::assertSame(self::MARK_OWNERS, $found, 'both mark owners still write the lifecycle; a moved owner must be renamed here');
    }

    public function testTheMarksAreExactlyTheLifecycleValuesTheMarkOwnersWrite(): void
    {
        self::assertSame(
            [VIRTUSPHERE_LIFECYCLE_DEPLOYING, VIRTUSPHERE_LIFECYCLE_FAILED],
            VIRTUSPHERE_VM_WORKER_MARK_LIFECYCLES
        );
        $constants = [
            'VIRTUSPHERE_LIFECYCLE_DEPLOYING' => 'lib/deploy_worker_vm_state.php',
            'VIRTUSPHERE_LIFECYCLE_FAILED' => 'lib/deploy_worker_vm_journal.php',
        ];
        foreach ($constants as $constant => $file) {
            self::assertStringContainsString($constant, (string) file_get_contents(dirname(__DIR__, 2) . '/' . $file), $file);
        }
        // The stages that count are the ones a writer stores with evidence; 2/5 is the starting value of a new row.
        self::assertSame(
            [VIRTUSPHERE_STATUS_DEPLOYED, VIRTUSPHERE_STATUS_OS_INSTALLING, VIRTUSPHERE_STATUS_OS_INSTALLED],
            VIRTUSPHERE_VM_EVIDENCE_STAGES
        );
    }
}
