<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The enqueue dedupe of repo_create_system_job() and the portal's pending view
 * repo_esxi_inventory_pending_jobs() must share one definition of an open
 * mission-less system job: repo_open_system_job_condition(). If either body
 * carries its own copy of the predicate again, the greyed-out "Inventarabruf
 * starten" button and the enqueue dedupe can drift apart (ADR-0033).
 *
 * A body is cut from its signature to the next top-level function, or to the
 * end of the file. A function that cannot be found fails the contract instead
 * of letting it pass on nothing.
 */
final class OpenSystemJobConditionContractTest extends TestCase
{
    /** @var array<string,string> function name => source file relative to Docker/WebAPI */
    private const SHARED_CONSUMERS = [
        'repo_create_system_job' => 'lib/repo/deploy_job_queue.php',
        'repo_esxi_inventory_pending_jobs' => 'lib/repo/esxi_inventory_state.php',
    ];

    public function testBothConsumersReadTheSharedHelperAndNeitherKeepsACopy(): void
    {
        foreach (self::SHARED_CONSUMERS as $function => $file) {
            $body = self::functionBody($function, $file);

            self::assertStringContainsString(
                'repo_open_system_job_condition()',
                $body,
                $function . ' in ' . $file . ' does not call repo_open_system_job_condition(); the enqueue dedupe and the pending view must read the same definition of an open system job.'
            );
            self::assertStringNotContainsString(
                'mission_id IS NULL',
                $body,
                $function . ' in ' . $file . ' carries its own copy of the open-job predicate; that copy must come from repo_open_system_job_condition() so the two cannot drift apart.'
            );
            self::assertStringNotContainsString(
                'VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES',
                $body,
                $function . ' in ' . $file . ' builds the active-status list itself; the list belongs inside repo_open_system_job_condition() only, or the dedupe and the pending view can disagree.'
            );
        }
    }

    private static function functionBody(string $function, string $file): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $file);
        self::assertIsString($source, $file . ' is not readable, so ' . $function . ' cannot be checked.');

        $start = strpos($source, 'function ' . $function . '(');
        self::assertIsInt($start, $function . ' is not defined in ' . $file . ', so the shared open-job contract has nothing to check.');

        $next = strpos($source, "\nfunction ", $start + 1);
        $length = $next === false ? strlen($source) - $start : $next - $start;

        return substr($source, $start, $length);
    }
}
