<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/ansible.php';

/**
 * ADR-0023 amendment 7, the wiring half. The incident of 15.10.2026 was a
 * literal `version: 21` in the create playbook on a 7.0.3 host, while the
 * portal knew the host release all along. The verdict itself is unit-tested
 * (EsxiCreateCompatibilityTest); this pins that nothing hardwires a version
 * again, that an existing VM never gets one, and that every enqueue path asks.
 */
final class EsxiCreateHardwareContractTest extends TestCase
{
    private static function source(string $relative): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $relative);
        self::assertIsString($source, $relative);

        return str_replace("\r\n", "\n", $source);
    }

    private static function launchPlaybook(): string
    {
        // The same resolver the worker uploads from, so the test reads what runs.
        $source = file_get_contents(ansible_source_dir() . DIRECTORY_SEPARATOR . VIRTUSPHERE_PLAYBOOKS['create']);
        self::assertIsString($source);

        return str_replace("\r\n", "\n", $source);
    }

    public function testTheLaunchPlaybookHardwiresNoVersionAndNeverGivesAnExistingVmOne(): void
    {
        $playbook = self::launchPlaybook();

        self::assertDoesNotMatchRegularExpression('/^\s*version:\s*\d+\s*$/m', $playbook, 'a literal hardware version is back');
        self::assertSame(1, preg_match('/^\s*version: >-\n(?<expr>(?:\s{12,}.*\n)+)/m', $playbook, $match), 'the version expression is missing');
        $expression = $match['expr'];
        self::assertStringContainsString('create_hardware_version', $expression);
        // Without this an existing VM is upgraded by UpgradeVM_Task, irreversibly.
        self::assertStringContainsString('not (vs_existed_before | bool)', $expression);
        self::assertStringContainsString('else omit', $expression);
    }

    public function testTheVersionReachesTheArtifactOnBothWrites(): void
    {
        $ansible = self::source('lib/ansible.php');

        self::assertSame(2, substr_count($ansible, '. ansible_create_hardware_yml('), 'initial write and post-create rewrite');
        self::assertStringContainsString("'create_hardware_version' => \$createHardwareVersion", $ansible);

        $worker = self::source('lib/deploy_worker_mission.php');
        self::assertStringContainsString('$createHardwareVersion = deploy_worker_esxi_write_preflight(', $worker);
        self::assertStringContainsString('$apiBaseUrl, $createHardwareVersion)', $worker);
    }

    /**
     * Derived, not listed: every repository function that runs the mission
     * gate at enqueue must also run the create verdict over its scope. A new
     * enqueue path that copies the first and forgets the second fails here.
     */
    public function testEveryEnqueuePathThatChecksTheMissionAlsoAsksTheHost(): void
    {
        $checked = 0;
        foreach (glob(dirname(__DIR__, 2) . '/lib/repo/*.php') ?: [] as $path) {
            $source = str_replace("\r\n", "\n", (string) file_get_contents($path));
            if (preg_match_all('/^function (\w+)\(.*?^}/ms', $source, $functions, PREG_SET_ORDER) === false) {
                continue;
            }
            foreach ($functions as $function) {
                if (!str_contains($function[0], 'repo_deploy_assert_mission_ready(') || $function[1] === 'repo_deploy_assert_mission_ready') {
                    continue;
                }
                $checked++;
                self::assertStringContainsString(
                    'deploy_assert_esxi_create_compatibility(',
                    $function[0],
                    $function[1] . ' queues a job without the create verdict (ADR-0023 amendment 7).'
                );
            }
        }
        self::assertGreaterThanOrEqual(2, $checked, 'zero-match: the derivation found no enqueue path');
    }

    public function testTheWorkerAndThePageUseTheSameOwner(): void
    {
        self::assertStringContainsString('deploy_esxi_create_compatibility($db, $credentialId, $mode, $missionId, $vmIds)', self::source('lib/deploy_worker_esxi_preflight.php'));
        self::assertStringContainsString('deploy_esxi_create_compatibility($db, $credentialId, $mode, $missionId, $eligibleVmIds)', self::source('lib/deploy_capability_blockers.php'));
        self::assertStringContainsString('...deploy_esxi_capability_blockers(', self::source('lib/deploy_blockers.php'));
    }

    public function testTheCreateSectionIsAPhaseOfTheJobLog(): void
    {
        $create = self::source('lib/deploy_worker_create.php');

        self::assertStringContainsString("ansible_step_marker_line(VIRTUSPHERE_ANSIBLE_STEP_BEGIN, VIRTUSPHERE_PLAYBOOKS['create'])", $create);
        // The end marker only after every unit succeeded: a missing end is the
        // timeline's word for "did not finish".
        self::assertSame(1, preg_match('/if \(\$allSuccessful\) \{\n\s*\$channel->log\(VIRTUSPHERE_DEPLOY_LOG_SYSTEM, ansible_step_marker_line\(VIRTUSPHERE_ANSIBLE_STEP_END/', $create));
    }
}
