<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/repo/deploy_jobs.php';
require_once dirname(__DIR__, 2) . '/lib/ansible_command_modes.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_retry_confirmation.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_display.php';

/**
 * K4b F1 (user decision 29.10.2026): "Erneut ausführen" of a full pipeline
 * that failed at start or autostart after a fully successful MAC export
 * continues at the failed step. The failed step comes only from the stored
 * column, a partial or missing MAC result keeps the export path, and a start
 * that resumes a pipeline writes autostart afterwards only under the mission
 * gate.
 */
final class DeployJobResumePlanTest extends TestCase
{
    private const SUCCESS = ['outcome' => 'success', 'successful_vm_ids' => [3, 4], 'failed_vm_ids' => []];

    private static function full(array $vmIds = [3, 4]): array
    {
        return deploy_job_payload(['mode' => 'full', 'vm_ids' => $vmIds]);
    }

    public function testFailedStepComesFromThePlaybookOfARemoteStepOnly(): void
    {
        foreach (VIRTUSPHERE_DEPLOY_FAILED_STEPS as $step) {
            self::assertSame($step, deploy_job_failed_step_for_playbook(VIRTUSPHERE_PLAYBOOKS[$step]));
        }
        self::assertNull(deploy_job_failed_step_for_playbook(VIRTUSPHERE_PLAYBOOKS['create']));
        self::assertNull(deploy_job_failed_step_for_playbook(null));
        self::assertNull(deploy_job_failed_step_for_playbook('unknown.yml'));
    }

    public function testTheFailedStepsAreThePlaybookKeysWithoutCreate(): void
    {
        self::assertSame(
            array_values(array_diff(array_keys(VIRTUSPHERE_PLAYBOOKS), ['create'])),
            VIRTUSPHERE_DEPLOY_FAILED_STEPS,
        );
    }

    public function testAFullPipelineThatFailedAtStartResumesAtStart(): void
    {
        self::assertSame(
            ['mode' => 'start', 'vm_ids' => [3, 4], 'scope' => 'resume_start', 'then_autostart' => true],
            deploy_job_resume_plan('failed', self::SUCCESS, self::full(), 'start', true),
        );
        self::assertSame(
            ['mode' => 'start', 'vm_ids' => [3, 4], 'scope' => 'resume_start', 'then_autostart' => false],
            deploy_job_resume_plan('failed', self::SUCCESS, self::full(), 'start', false),
        );
    }

    public function testAFullPipelineThatFailedAtAutostartResumesAtAutostart(): void
    {
        self::assertSame(
            ['mode' => 'autostart', 'vm_ids' => [], 'scope' => 'resume_autostart', 'then_autostart' => false],
            deploy_job_resume_plan('failed', self::SUCCESS, self::full([]), 'autostart', true),
        );
    }

    public function testAResumedStartThatFailedAtAutostartResumesAtAutostart(): void
    {
        $payload = deploy_job_payload(['mode' => 'start', 'vm_ids' => [3], 'then_autostart' => true]);

        self::assertSame(
            ['mode' => 'autostart', 'vm_ids' => [3], 'scope' => 'resume_autostart', 'then_autostart' => false],
            deploy_job_resume_plan('failed', null, $payload, 'autostart', true),
        );
        // Failed at its own start step it has no MAC evidence: the general rule applies.
        self::assertNull(deploy_job_resume_plan('failed', null, $payload, 'start', true));
    }

    public function testEverythingElseLeavesThePlanToTheGeneralRule(): void
    {
        $partial = ['outcome' => 'partial', 'successful_vm_ids' => [3], 'failed_vm_ids' => [4]];
        $cases = [
            'partial MAC result' => ['failed', $partial, self::full(), 'start'],
            'no MAC result' => ['failed', null, self::full(), 'start'],
            'no stored step' => ['failed', self::SUCCESS, self::full(), null],
            'failed at export' => ['failed', self::SUCCESS, self::full(), 'export'],
            'failed at powercycle' => ['failed', self::SUCCESS, self::full(), 'powercycle'],
            'outcome unknown' => ['outcome_unknown', self::SUCCESS, self::full(), 'start'],
            'partial job' => ['partial', self::SUCCESS, self::full(), 'start'],
            'plain start job' => ['failed', null, deploy_job_payload(['mode' => 'start']), 'autostart'],
            'export job' => ['failed', self::SUCCESS, deploy_job_payload(['mode' => 'export']), 'start'],
        ];
        foreach ($cases as $label => [$status, $result, $payload, $step]) {
            self::assertNull(deploy_job_resume_plan($status, $result, $payload, $step, true), $label);
        }
    }

    public function testOnlyAStartJobCarriesThenAutostart(): void
    {
        self::assertTrue(deploy_job_payload(['mode' => 'start', 'then_autostart' => '1'])['then_autostart']);
        self::assertFalse(deploy_job_payload(['mode' => 'start'])['then_autostart']);
        foreach (['full', 'create', 'powercycle', 'export', 'autostart'] as $mode) {
            self::assertFalse(deploy_job_payload(['mode' => $mode, 'then_autostart' => true])['then_autostart'], $mode);
        }
    }

    public function testAStartWithThenAutostartRunsAutostartOnlyUnderTheMissionGate(): void
    {
        $start = VIRTUSPHERE_PLAYBOOKS['start'];
        $autostart = VIRTUSPHERE_PLAYBOOKS['autostart'];

        self::assertSame([$start], ansible_playbooks_for_mode('start'));
        self::assertSame([$start], ansible_playbooks_for_mode('start', true));
        self::assertSame([$start], ansible_playbooks_for_mode('start', false, true));
        self::assertSame([$start, $autostart], ansible_playbooks_for_mode('start', true, true));
    }

    public function testTheRetryPayloadCarriesThePlanAndKeepsTheRest(): void
    {
        $source = ['mode' => 'full', 'vm_ids' => [3, 4], 'verbose' => true, 'then_autostart' => false];
        $plan = ['mode' => 'start', 'vm_ids' => [3, 4], 'scope' => 'resume_start', 'then_autostart' => true];

        $payload = deploy_retry_payload($source, ['plan' => $plan, 'effective_mode' => 'start', 'scope_vm_ids' => [3, 4]]);
        self::assertSame(['mode' => 'start', 'vm_ids' => [3, 4], 'verbose' => true, 'then_autostart' => true], $payload);

        $export = ['mode' => 'export', 'vm_ids' => [4], 'scope' => 'failed_vms'];
        $payload = deploy_retry_payload($source, ['plan' => $export, 'effective_mode' => 'export', 'scope_vm_ids' => [4]]);
        self::assertSame('export', $payload['mode']);
        self::assertFalse($payload['then_autostart']);

        $resumed = ['mode' => 'start', 'vm_ids' => [], 'then_autostart' => true];
        $payload = deploy_retry_payload($resumed, ['plan' => null, 'effective_mode' => 'start', 'scope_vm_ids' => []]);
        self::assertTrue($payload['then_autostart'], 'a plain re-queue repeats the source payload');
    }

    public function testTheLogNoteNamesThePlan(): void
    {
        self::assertSame('', deploy_retry_plan_note(null));
        self::assertSame(' (export-only: 2 failed VMs)', deploy_retry_plan_note(['scope' => 'failed_vms', 'vm_ids' => [1, 2]]));
        self::assertSame(' (export-only: original selection)', deploy_retry_plan_note(['scope' => 'original_selection', 'vm_ids' => []]));
        self::assertSame(' (resumed at the start step, then autostart)', deploy_retry_plan_note(['scope' => 'resume_start', 'then_autostart' => true]));
        self::assertSame(' (resumed at the start step)', deploy_retry_plan_note(['scope' => 'resume_start', 'then_autostart' => false]));
        self::assertSame(' (resumed at the autostart step)', deploy_retry_plan_note(['scope' => 'resume_autostart']));
        self::assertSame('', deploy_retry_plan_note(['scope' => 'create_units']));
    }

    public function testTheConfirmationNamesTheResumedStep(): void
    {
        $evaluation = static fn (array $plan): array => ['plan' => $plan, 'effective_mode' => $plan['mode'], 'scope_vm_ids' => []];

        self::assertSame(
            __t('deploy.confirm_retry_resume_start_autostart', ['name' => 'M1']),
            deploy_retry_confirmation($evaluation(['mode' => 'start', 'scope' => 'resume_start', 'then_autostart' => true]), 'M1'),
        );
        self::assertSame(
            __t('deploy.confirm_retry_resume_start', ['name' => 'M1']),
            deploy_retry_confirmation($evaluation(['mode' => 'start', 'scope' => 'resume_start', 'then_autostart' => false]), 'M1'),
        );
        self::assertSame(
            __t('deploy.confirm_retry_resume_autostart', ['name' => 'M1']),
            deploy_retry_confirmation($evaluation(['mode' => 'autostart', 'scope' => 'resume_autostart', 'then_autostart' => false]), 'M1'),
        );
    }

    public function testThePortalAndTheLogNameAStartWithAutostart(): void
    {
        $json = json_encode(deploy_job_payload(['mode' => 'start', 'then_autostart' => true]));
        self::assertStringContainsString(__t('status.mode_then_autostart'), deploy_job_payload_display($json));
        self::assertStringContainsString('then autostart', deploy_job_payload_summary($json));

        $plain = json_encode(deploy_job_payload(['mode' => 'start']));
        self::assertStringNotContainsString(__t('status.mode_then_autostart'), deploy_job_payload_display($plain));
    }
}
