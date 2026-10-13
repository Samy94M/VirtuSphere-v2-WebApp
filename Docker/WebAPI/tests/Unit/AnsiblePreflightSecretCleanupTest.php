<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/ansible_command_preflight.php';

/**
 * K21-F: the host component `secret-cleanup` of the Ansible preflight and the
 * reader of its timer verdict. The component tells whether the systemd timer
 * that removes stale accounts.yml files is enabled on the Ansible host. It is a
 * hint and never a gate: it exits 0 by construction, no failure reader can name
 * it, and the strip reader keeps its verdict line out of operator text.
 */
final class AnsiblePreflightSecretCleanupTest extends TestCase
{
    private const COMPONENT = 'secret-cleanup';
    private const TIMER = 'virtusphere-secret-cleanup.timer';

    public function testTheComponentIsTheLastStepAfterTheAllowlistProbe(): void
    {
        $command = ansible_preflight_command('http://portal.lan:8021/', true);
        $stage = strrpos($command, VIRTUSPHERE_ANSIBLE_PREFLIGHT_MARKER . ' ' . self::COMPONENT);
        self::assertNotFalse($stage, 'secret-cleanup has no stage marker');
        self::assertGreaterThan(
            strrpos($command, VIRTUSPHERE_ANSIBLE_PREFLIGHT_MARKER . ' ' . VIRTUSPHERE_ANSIBLE_PREFLIGHT_ALLOWLIST),
            $stage,
            'secret-cleanup must follow the allowlist probe'
        );
        // Nothing after its own probe: its verdict is the last stage the chain prints.
        $afterComponent = substr($command, $stage + strlen(VIRTUSPHERE_ANSIBLE_PREFLIGHT_MARKER . ' ' . self::COMPONENT));
        self::assertFalse(str_contains($afterComponent, VIRTUSPHERE_ANSIBLE_PREFLIGHT_MARKER), 'a stage marker follows the component');
    }

    public function testTheComponentRunsWithoutAPortalUrlAfterEveryStaticCheck(): void
    {
        // Host-local: the timer lives on the Ansible host, no portal is involved.
        $command = ansible_preflight_command('');
        $stage = strrpos($command, VIRTUSPHERE_ANSIBLE_PREFLIGHT_MARKER . ' ' . self::COMPONENT);
        self::assertNotFalse($stage, 'the component must not depend on an API base URL');
        self::assertGreaterThan(
            strrpos($command, VIRTUSPHERE_ANSIBLE_PREFLIGHT_MARKER . ' async-workspace'),
            $stage
        );
    }

    public function testTheProbeAsksSystemctlUnprivilegedAboutTheTimerAndPrintsTheVerdictMarker(): void
    {
        // assertTrue(str_contains()) instead of assertStringContainsString(): a
        // failure must not print the complete preflight command.
        $command = ansible_preflight_command('');
        self::assertTrue(str_contains($command, 'systemctl is-enabled'), 'the probe must ask systemctl is-enabled');
        self::assertTrue(str_contains($command, self::TIMER), 'the probe must name ' . self::TIMER);
        self::assertFalse(str_contains($command, 'sudo'), 'the hint runs without privilege');
        self::assertTrue(
            str_contains($command, VIRTUSPHERE_ANSIBLE_SECRET_CLEANUP_MARKER),
            'the probe must print its verdict behind the marker'
        );
        self::assertTrue(
            str_contains($command, 'command -v systemctl'),
            'a host without systemctl must answer unknown instead of failing'
        );
    }

    public function testTheProbeCanNeverFailTheChain(): void
    {
        // Exit 0 by construction: the probe is grouped with `|| true`, so only
        // its own failure is absorbed and every earlier check still stops the chain.
        $command = ansible_preflight_command('');
        $stage = strrpos($command, VIRTUSPHERE_ANSIBLE_PREFLIGHT_MARKER . ' ' . self::COMPONENT);
        self::assertNotFalse($stage);
        self::assertStringContainsString('|| true', substr($command, $stage));
    }

    public function testTheComponentIsNoCheckedComponentSoNoFailureReaderCanNameIt(): void
    {
        self::assertArrayNotHasKey(self::COMPONENT, ansible_preflight_checks());
        self::assertArrayNotHasKey(self::COMPONENT, ansible_preflight_checks(true));

        self::assertNull(
            ansible_preflight_failed_component(
                self::stage(self::COMPONENT) . "\n" . VIRTUSPHERE_ANSIBLE_SECRET_CLEANUP_MARKER . ' unknown'
            ),
            'a reader must not name the hint as a broken component'
        );
    }

    public function testAFailureReaderReachingTheComponentNamesNothingBecauseEveryCheckPassed(): void
    {
        // The allowlist probe exits 0 by design, so a chain that printed the
        // component's marker had passed every check before it. No check failed,
        // and the reader must not blame the allowlist step that preceded it.
        $output = self::stage(VIRTUSPHERE_ANSIBLE_PREFLIGHT_PORTAL) . "\n"
            . self::stage(VIRTUSPHERE_ANSIBLE_PREFLIGHT_ALLOWLIST) . "\n"
            . self::stage(self::COMPONENT) . "\n"
            . VIRTUSPHERE_ANSIBLE_SECRET_CLEANUP_MARKER . " enabled\n";
        self::assertNull(ansible_preflight_failed_component($output));
    }

    public function testStripRemovesTheVerdictLineAndTheStageMarkerAndKeepsTheRemoteOutput(): void
    {
        $output = self::stage(self::COMPONENT) . "\n"
            . VIRTUSPHERE_ANSIBLE_SECRET_CLEANUP_MARKER . " missing\n"
            . 'real remote output';
        self::assertSame('real remote output', ansible_preflight_strip_markers($output));
    }

    public function testTimerStateReadsEnabledMissingAndUnknownVerdicts(): void
    {
        self::assertSame('enabled', ansible_secret_cleanup_timer_state(self::verdict('enabled')));
        self::assertSame('missing', ansible_secret_cleanup_timer_state(self::verdict('missing')));
        self::assertSame('unknown', ansible_secret_cleanup_timer_state(self::verdict('unknown')));
    }

    public function testTimerStateIsUnknownWithoutAVerdictLine(): void
    {
        self::assertSame('unknown', ansible_secret_cleanup_timer_state(''));
        self::assertSame('unknown', ansible_secret_cleanup_timer_state("bash: systemctl: command not found\n"));
        // The stage marker of the component is not a verdict.
        self::assertSame('unknown', ansible_secret_cleanup_timer_state(self::stage(self::COMPONENT)));
    }

    public function testTimerStateTakesTheLastVerdictLine(): void
    {
        self::assertSame(
            'missing',
            ansible_secret_cleanup_timer_state(self::verdict('enabled') . "\n" . self::verdict('missing'))
        );
        self::assertSame(
            'enabled',
            ansible_secret_cleanup_timer_state(self::verdict('missing') . "\n" . self::verdict('enabled'))
        );
    }

    public function testTimerStateAcceptsOnlyTheExactWordsAndCarriageReturnsOfARemoteLine(): void
    {
        self::assertSame('enabled', ansible_secret_cleanup_timer_state(self::verdict('enabled') . "\r\n"));
        // Raw systemctl words are not verdicts: the probe maps them, the reader
        // does not guess. Anything else is unknown.
        self::assertSame('unknown', ansible_secret_cleanup_timer_state(self::verdict('disabled')));
        self::assertSame('unknown', ansible_secret_cleanup_timer_state(self::verdict('ENABLED')));
        self::assertSame('unknown', ansible_secret_cleanup_timer_state(self::verdict('enabled extra')));
    }

    public function testTimerStateIgnoresTextBeforeTheMarker(): void
    {
        self::assertSame(
            'unknown',
            ansible_secret_cleanup_timer_state('banner ' . self::verdict('enabled'))
        );
    }

    public function testTheProbeMapsEverySystemctlAnswerToItsVerdict(): void
    {
        // The probe runs for real under /bin/sh with a fake systemctl on PATH.
        // systemd is not available in the test containers, so the systemctl
        // answers are the ones the manual and older builds print (see the
        // docblock of ansible_secret_cleanup_probe_source()).
        $cases = [
            'enabled' => ["enabled\n", 0, 'enabled'],
            'disabled' => ["disabled\n", 1, 'missing'],
            'not-found' => ["not-found\n", 1, 'missing'],
            'absent unit file (No such file)' => [
                "Failed to get unit file state for virtusphere-secret-cleanup.timer: No such file or directory\n",
                1,
                'missing',
            ],
            'absent unit (does not exist)' => ["Unit virtusphere-secret-cleanup.timer does not exist.\n", 1, 'missing'],
            'masked' => ["masked\n", 1, 'unknown'],
            'enabled-runtime' => ["enabled-runtime\n", 0, 'unknown'],
            'static' => ["static\n", 0, 'unknown'],
            'empty output' => ['', 1, 'unknown'],
            'no systemd as init' => [
                "System has not been booted with systemd as init system (PID 1). Can't operate.\n",
                1,
                'unknown',
            ],
        ];
        foreach ($cases as $label => [$output, $exit, $expected]) {
            $stdout = self::runProbe($output, $exit);
            self::assertSame(1, substr_count($stdout, VIRTUSPHERE_ANSIBLE_SECRET_CLEANUP_MARKER), $label . ': exactly one verdict line');
            self::assertSame($expected, ansible_secret_cleanup_timer_state($stdout), $label);
        }
    }

    public function testTheProbeAnswersUnknownOnAHostWithoutSystemctl(): void
    {
        $stdout = self::runProbe(null, 0);
        self::assertSame(1, substr_count($stdout, VIRTUSPHERE_ANSIBLE_SECRET_CLEANUP_MARKER));
        self::assertSame('unknown', ansible_secret_cleanup_timer_state($stdout));
    }

    /**
     * Runs the probe source under /bin/sh with PATH set to a temporary directory
     * that holds a fake systemctl printing $output and exiting $exit, or holds
     * nothing when $output is null. Asserts the exit status is 0 in every case.
     */
    private static function runProbe(?string $output, int $exit): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vs-k21f-probe-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        $fake = $dir . DIRECTORY_SEPARATOR . 'systemctl';
        try {
            if ($output !== null) {
                file_put_contents($fake, "#!/bin/sh\nprintf '%s' \"\$FAKE_SYSTEMCTL_OUT\"\nexit " . $exit . "\n");
                chmod($fake, 0755);
            }
            $process = proc_open(
                ['/bin/sh', '-c', ansible_secret_cleanup_probe_source()],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                ['PATH' => $dir, 'FAKE_SYSTEMCTL_OUT' => (string) $output]
            );
            self::assertIsResource($process, 'the probe shell could not be started');
            $stdout = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($process);
            self::assertSame(0, $status, 'the probe must exit 0 whatever systemctl answers');
        } finally {
            if (is_file($fake)) {
                unlink($fake);
            }
            rmdir($dir);
        }

        return $stdout;
    }

    private static function stage(string $component): string
    {
        return VIRTUSPHERE_ANSIBLE_PREFLIGHT_MARKER . ' ' . $component;
    }

    private static function verdict(string $value): string
    {
        return VIRTUSPHERE_ANSIBLE_SECRET_CLEANUP_MARKER . ' ' . $value;
    }
}
