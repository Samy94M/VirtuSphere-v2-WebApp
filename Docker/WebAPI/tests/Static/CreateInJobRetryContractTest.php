<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/deploy_create_result.php';

/**
 * The in-job create retry has exactly one re-opening writer (ADR-0041
 * amendment 2026-10-29). The writers are derived from lib/, not listed: a new
 * file that re-opens a unit or clears a launch intent turns this red.
 */
final class CreateInJobRetryContractTest extends TestCase
{
    public function testTheCasTableNeverReopensATerminalUnit(): void
    {
        foreach ([VIRTUSPHERE_CREATE_RESULT_STATUS_SUCCEEDED, VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED, VIRTUSPHERE_CREATE_RESULT_STATUS_SKIPPED] as $terminal) {
            self::assertArrayNotHasKey($terminal, deploy_create_transitions(), $terminal . ' must stay terminal for the generic CAS');
        }
    }

    public function testOnlyTheRequeueWriterAdvancesTheAttempt(): void
    {
        self::assertSame(
            ['lib/deploy_worker_create_retry.php'],
            $this->filesMatching('/attempt\s*=\s*attempt\s*\+\s*1/'),
            'deploy_worker_create_requeue_unit() is the one writer of the in-job failed -> pending edge'
        );
    }

    public function testOnlyTheJournalOwnerAndTheRequeueWriteTheLaunchIntent(): void
    {
        self::assertSame(
            ['lib/deploy_worker_create_retry.php', 'lib/deploy_worker_vm_journal.php'],
            $this->filesMatching('/SET\s+create_launch_intent\s*=/i'),
            'a launch intent is set or cleared only by its journal owner and by the in-job re-open'
        );
        $retry = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/deploy_worker_create_retry.php');
        // The reset and the move of the first job id share one transaction.
        self::assertMatchesRegularExpression(
            '/first_async_jid = COALESCE\(first_async_jid, async_jid\).*?SET create_launch_intent = 0/s',
            $retry
        );
        self::assertStringContainsString('deploy_worker_owned_transaction($db, $claim,', $retry);
    }

    /** @return list<string> repository-relative paths under Docker/WebAPI, sorted */
    private function filesMatching(string $pattern): array
    {
        $root = str_replace('\\', '/', dirname(__DIR__, 2));
        $found = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/lib', FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            if (preg_match($pattern, (string) file_get_contents($file->getPathname())) === 1) {
                $found[] = substr(str_replace('\\', '/', $file->getPathname()), strlen($root) + 1);
            }
        }
        self::assertNotSame([], $found, 'the scan found nothing; this contract would pass on anything');
        sort($found);

        return $found;
    }
}
