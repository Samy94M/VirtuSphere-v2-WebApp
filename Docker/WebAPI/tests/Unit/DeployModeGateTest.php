<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/repo/deploy_jobs.php';
require_once dirname(__DIR__, 2) . '/lib/ansible.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_worker_runtime.php';

/**
 * Two separate questions about a deploy mode, previously conflated:
 *
 *  - which modes may a stored payload carry (system modes included, because the
 *    worker reads back an inventory job's payload);
 *  - which modes may an operator ask for (only what the form offers).
 *
 * `deploy_job_normalize_mode()` answered the first for both, so a crafted POST
 * could queue a MISSION job with mode `inventory`, which the worker then routed
 * into the mission-less inventory branch.
 *
 * And whether a mode needs a location at all: autostart writes the host's boot
 * configuration and reads neither datacenter nor datastore.
 */
final class DeployModeGateTest extends TestCase
{
    public function testTheLabelMapIsTheSourceOfTruthForPostableModes(): void
    {
        // A mode without a label cannot be posted; a postable mode is labelled.
        self::assertSame(array_keys(virtusphere_deploy_mode_labels()), virtusphere_user_deploy_modes());
    }

    public function testSystemModesAreNotOfferedAndNotPostable(): void
    {
        foreach (array_keys(VIRTUSPHERE_SYSTEM_PLAYBOOKS) as $systemMode) {
            self::assertNotContains($systemMode, virtusphere_user_deploy_modes(), $systemMode);
            // Still readable, because a queued inventory job carries it.
            self::assertContains($systemMode, virtusphere_deploy_modes(), $systemMode);
        }
    }

    public function testEveryPostableModeHasAPlaybookOrIsTheFullPipeline(): void
    {
        foreach (virtusphere_user_deploy_modes() as $mode) {
            if ($mode === VIRTUSPHERE_DEPLOY_MODE_FULL) {
                continue;
            }
            self::assertArrayHasKey($mode, VIRTUSPHERE_PLAYBOOKS, $mode);
        }
    }

    public function testAMissionJobRejectsASystemMode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        deploy_job_normalize_mission_mode(VIRTUSPHERE_DEPLOY_MODE_INVENTORY);
    }

    public function testAMissionJobAcceptsEveryOfferedMode(): void
    {
        foreach (virtusphere_user_deploy_modes() as $mode) {
            self::assertSame($mode, deploy_job_normalize_mission_mode(strtoupper($mode)));
        }
    }

    public function testTheScheduleParserRefusesASystemMode(): void
    {
        // deploy_parse_schedule() reads $_POST directly.
        $this->expectException(InvalidArgumentException::class);
        deploy_parse_schedule(['mode' => VIRTUSPHERE_DEPLOY_MODE_INVENTORY], 'UTC');
    }

    public function testTheWorkerStillReadsBackASystemModePayload(): void
    {
        self::assertSame(
            VIRTUSPHERE_DEPLOY_MODE_INVENTORY,
            deploy_job_normalize_mode(VIRTUSPHERE_DEPLOY_MODE_INVENTORY)
        );
    }

    public function testOnlyAutostartSkipsTheLocationRequirement(): void
    {
        self::assertFalse(virtusphere_deploy_mode_needs_location(VIRTUSPHERE_DEPLOY_MODE_AUTOSTART));
        foreach (['create', 'powercycle', 'export', 'start', VIRTUSPHERE_DEPLOY_MODE_FULL] as $mode) {
            self::assertTrue(virtusphere_deploy_mode_needs_location($mode), $mode);
        }
    }

    public function testTheLocationPredicateReadsAModeTheWayTheValidatorsWriteIt(): void
    {
        // Three gates ask this question and only two of them received a
        // normalized mode. The predicate was a bare inequality, so `AUTOSTART`
        // answered "needs a location" and the page refused a job the enqueue
        // path would have taken: a disagreeing twin that no gate could see.
        foreach (virtusphere_user_deploy_modes() as $mode) {
            self::assertSame(
                virtusphere_deploy_mode_needs_location($mode),
                virtusphere_deploy_mode_needs_location(' ' . strtoupper($mode) . ' '),
                $mode
            );
        }
    }

    public function testAnUnknownModeThrowsInsteadOfInheritingTheLocationAnswer(): void
    {
        // The default arm a bare inequality had: every unrecognised string was
        // silently told it needs a location, so a location-free mode added later
        // would have been refused by all three gates with nothing breaking.
        $this->expectException(LogicException::class);
        virtusphere_deploy_mode_needs_location('teleport');
    }

    public function testTheWorkerSideGateAgreesWithTheEnqueueGate(): void
    {
        $missionWithoutLocation = ['hypervisor_datacenter' => '', 'hypervisor_datastorage' => ''];

        // Autostart reads no location, so the worker gate stays silent.
        ansible_assert_mission_ready($missionWithoutLocation, '', VIRTUSPHERE_DEPLOY_MODE_AUTOSTART);
        self::assertTrue(true);

        $this->expectException(RuntimeException::class);
        ansible_assert_mission_ready($missionWithoutLocation, '', VIRTUSPHERE_DEPLOY_MODE_FULL);
    }

    public function testOnlyPowerModesCanBeStaggered(): void
    {
        // A config write has nothing to spread over time. Staggering `autostart`
        // would queue one job per VM, each rewriting the host's defaults.
        self::assertNotContains(VIRTUSPHERE_DEPLOY_MODE_AUTOSTART, VIRTUSPHERE_DEPLOY_STAGGER_MODES);
        foreach (VIRTUSPHERE_DEPLOY_STAGGER_MODES as $mode) {
            self::assertContains($mode, virtusphere_user_deploy_modes(), $mode);
        }
    }

    public function testTheSchedulerRefusesToStaggerAConfigWrite(): void
    {
        $this->expectException(ValidationException::class);
        deploy_parse_schedule(['mode' => VIRTUSPHERE_DEPLOY_MODE_AUTOSTART, 'stagger_minutes' => '10'], 'UTC');
    }

    public function testAnUnreadableOrUnknownModeIsNeverRepairedIntoAnotherSequence(): void
    {
        // AN-04: ansible_job_payload() must never hand a mode nobody validated to
        // the gates, and a missing mode must not become the full pipeline. The
        // decoder reports '' and the sequence builder refuses it.
        self::assertSame('', ansible_job_payload(['payload_json' => 'not json'])['mode']);
        self::assertSame('', ansible_job_payload(['payload_json' => '{"mode":"nonsense"}'])['mode']);
        self::assertSame('', ansible_job_payload(['payload_json' => '{"vm_ids":[1]}'])['mode'], 'a missing mode is not the full pipeline');
        self::assertSame('', ansible_job_payload(['payload_json' => '{"mode":"inventory"}'])['mode'], 'a system mode is not a mission mode');
        self::assertSame('', ansible_job_payload(['payload_json' => '{"mode":42}'])['mode']);
        self::assertSame(VIRTUSPHERE_DEPLOY_MODE_AUTOSTART, ansible_job_payload(['payload_json' => '{"mode":"autostart"}'])['mode']);
        self::assertSame(VIRTUSPHERE_DEPLOY_MODE_FULL, ansible_job_payload(['payload_json' => '{"mode":" FULL "}'])['mode']);
    }

    public function testTheStoredModePredicateNamesOnlyAReadableMode(): void
    {
        // deploy_job_stored_mode() is the one predicate for "does this stored payload name a mode".
        self::assertNull(deploy_job_stored_mode(['vm_ids' => [1]]), 'a missing mode');
        self::assertNull(deploy_job_stored_mode(['mode' => null]), 'a null mode');
        self::assertNull(deploy_job_stored_mode(['mode' => 42]), 'a non-string mode');
        self::assertNull(deploy_job_stored_mode(['mode' => ['full']]), 'an array mode');
        self::assertNull(deploy_job_stored_mode(['mode' => 'nonsense']), 'an unknown mode');
        self::assertNull(deploy_job_stored_mode('full'), 'a payload that is not an array');
        self::assertNull(deploy_job_stored_mode(null), 'an unreadable payload');
        self::assertSame('create', deploy_job_stored_mode(['mode' => '  CREATE ']));
        self::assertSame(VIRTUSPHERE_DEPLOY_MODE_FULL, deploy_job_stored_mode(['mode' => 'full']));
        // A system mode is still a stored mode: the worker reads back an inventory job.
        self::assertSame(VIRTUSPHERE_DEPLOY_MODE_INVENTORY, deploy_job_stored_mode(['mode' => VIRTUSPHERE_DEPLOY_MODE_INVENTORY]));
    }

    public function testTheWorkerReadsAJobWithoutAReadableModeAsNoMode(): void
    {
        // AN-04: an unreadable mode is '' and never the full pipeline; the worker refuses such a job.
        self::assertSame('', deploy_worker_payload(['payload_json' => '{"vm_ids":[1]}'])['mode']);
        self::assertSame('', deploy_worker_payload(['payload_json' => 'not json'])['mode']);
        self::assertSame('', deploy_worker_payload(['payload_json' => '{"mode":"nonsense"}'])['mode']);
        self::assertSame(VIRTUSPHERE_DEPLOY_MODE_FULL, deploy_worker_payload(['payload_json' => '{"mode":"full"}'])['mode']);
    }

    public function testTheSequenceBuilderRefusesAPayloadWithoutAReadableMissionMode(): void
    {
        $refused = 0;
        foreach ([[], ['mode' => 'nonsense'], ['mode' => 'inventory'], ['mode' => null]] as $payload) {
            try {
                ansible_remote_steps('/tmp/deploy', $payload);
                self::fail('A payload without a readable mission mode derived a playbook sequence.');
            } catch (AnsibleDeployModeInvalid) {
                $refused++;
            }
        }
        self::assertSame(4, $refused, 'every unreadable mode must be refused, none repaired into the full pipeline');
        // The valid modes still derive their sequence.
        self::assertCount(1, ansible_remote_steps('/tmp/deploy', ['mode' => 'export']));
    }

    public function testOnlyPowerCycleModesUseTheWaitTime(): void
    {
        // The deploy form locks the wait time to exactly the modes whose playbook
        // sequence runs the power-cycle playbook. Derived, so it cannot drift from
        // ansible_playbooks_for_mode(); pinned here so a sequence change that drops
        // (or adds) the power-cycle playbook must update the lock too.
        self::assertSame([VIRTUSPHERE_DEPLOY_MODE_FULL, 'powercycle'], ansible_modes_using_powercycle());

        foreach (ansible_modes_using_powercycle() as $mode) {
            self::assertContains(VIRTUSPHERE_PLAYBOOKS['powercycle'], ansible_playbooks_for_mode($mode), $mode);
            self::assertContains($mode, virtusphere_user_deploy_modes(), $mode);
        }
        foreach (['create', 'export', 'start', VIRTUSPHERE_DEPLOY_MODE_AUTOSTART] as $mode) {
            self::assertNotContains($mode, ansible_modes_using_powercycle(), $mode);
        }
    }
}
