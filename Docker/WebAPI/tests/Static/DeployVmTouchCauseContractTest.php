<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * MA-08: the refusal causes of deploy_vm_touch.php form one closed set. The
 * repository returns them, the constant names them and the Ansible reporter
 * prints them. A cause returned but not named, or named but never returned, is
 * contract drift that no wire test would notice, because the 409 body stays valid.
 */
final class DeployVmTouchCauseContractTest extends TestCase
{
    public function testEveryCauseTheAdmissionReturnsIsNamedAndEveryNamedCauseIsReturned(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/repo/deploy_job_vm_states.php');
        $start = strpos($source, 'function repo_deploy_job_vm_touch_refusal(');
        $end = strpos($source, 'function repo_deploy_job_vm_admit_touch(');
        self::assertIsInt($start);
        self::assertIsInt($end);
        self::assertLessThan($end, $start);

        preg_match_all("/return '([a-z_]+)';/", substr($source, $start, $end - $start), $matches);
        $returned = array_values(array_unique($matches[1]));
        sort($returned);

        self::assertSame(1, preg_match('/const VIRTUSPHERE_DEPLOY_TOUCH_REFUSAL_CAUSES = \[(.*?)\];/s', $source, $block));
        preg_match_all("/'([a-z_]+)'/", $block[1], $namedMatches);
        $named = array_values(array_unique($namedMatches[1]));
        sort($named);

        self::assertNotSame([], $returned, 'no refusal returns a cause; this contract would pass on anything');
        self::assertSame($named, $returned, 'the closed cause set and the causes the admission returns have drifted apart');
        self::assertSame(count($namedMatches[1]), count(array_unique($namedMatches[1])), 'a cause is named twice');
    }

    public function testTheBooleanWrapperKeepsTheVerdictOfTheCause(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/repo/deploy_job_vm_states.php');
        self::assertStringContainsString(
            'return repo_deploy_job_vm_touch_refusal($db, $jobId, $vmId, $attempt, $generation, $phase) === null;',
            $source,
            'the boolean callers and the cause must decide from one function'
        );
        self::assertStringContainsString('UPDATE deploy_job_vm_states SET esxi_touched = 1, touch_phase = COALESCE(touch_phase, ?) WHERE job_id = ? AND vm_id = ?', $source);
    }
}
