<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/ansible.php';

/**
 * The power-cycle playbook may only touch what it itself moved (campaign B1).
 *
 * The old shape selected targets over `needs_mac` with a FALLBACK OF TRUE,
 * powered on, paused, hard powered off (force: true) - without ever reading
 * the initial power state and without a cleanup path. Two real consequences:
 * a running VM whose portal MAC was unknown got hard powered off after the
 * pause, and an abort between power-on and power-off left freshly started VMs
 * running with nobody responsible.
 *
 * The contract now:
 *  - a VM without a `needs_mac` statement is NOT a target (no default(true)),
 *  - `vmware_guest_info` captures the initial state before any change,
 *  - a reply without `instance.hw_power_status` is a hard error, not a skip
 *    (a changed module answer must not read as "nothing to do"),
 *  - only VMs this run moved from poweredOff are powered on, and exactly that
 *    derived list is powered off again in an `always:` block (force: true is
 *    reserved for that cleanup; the power-on must not force anything),
 *  - a failed or unclear power-on is never repeated blindly (K4a W2): the run
 *    reads the state back, counts poweredOn as its own start, retries only
 *    after a proven poweredOff and ends hard on an unreadable state,
 *  - the selection chains are proven against fixtures without an ESXi host in
 *    Docker/qa-ansible/powercycle-selection-fixtures.yml (gate
 *    ansible-powercycle-selection), so both files must carry the chains
 *    CHARACTER-IDENTICAL or the fixture proves a different playbook.
 */
final class PowercyclePlaybookContractTest extends TestCase
{
    private const PLAYBOOK = 'powercycleVMs-ESXi_playbook.yml';
    private const FIXTURE_RELATIVE = 'Docker/qa-ansible/powercycle-selection-fixtures.yml';

    private const CHAIN_FACTS = ['powercycle_targets', 'powercycle_to_start'];

    private function playbook(): string
    {
        $path = ansible_source_dir() . DIRECTORY_SEPARATOR . self::PLAYBOOK;
        self::assertFileExists($path);

        return (string) file_get_contents($path) . "\n"
            . file_get_contents(ansible_source_dir() . '/powercycle_vm_tasks.yml') . "\n"
            . $this->source(VIRTUSPHERE_POWERCYCLE_POWER_ON_TASKS) . "\n"
            . $this->source(VIRTUSPHERE_POWERCYCLE_POWER_OFF_TASKS);
    }

    private function source(string $file): string
    {
        $path = ansible_source_dir() . DIRECTORY_SEPARATOR . $file;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * The Jinja chain a set_fact assigns, keyed by fact name and normalized to
     * single spaces: the yaml line-length limit forces the longer chain into a
     * folded scalar, and "identical" means the expression, not the wrapping.
     */
    private function chains(string $source, string $file): array
    {
        $chains = [];
        foreach (self::CHAIN_FACTS as $fact) {
            $quoted = preg_quote($fact, '/');
            $found = preg_match('/^\s*' . $quoted . ':\s*"(\{\{.+?\}\})"\s*$/m', $source, $match) === 1
                || preg_match('/^(\s*)' . $quoted . ':\s*>-\R((?:\1\s+.+\R?)+)/m', $source, $match) === 1;
            self::assertTrue($found, sprintf('%s must derive %s in exactly one set_fact expression.', $file, $fact));
            $expression = trim((string) preg_replace('/\s+/', ' ', $match[2] ?? $match[1]));
            $chains[$fact] = $expression;
        }

        return $chains;
    }

    public function testAVmWithoutANeedsMacStatementIsNotATarget(): void
    {
        $source = $this->playbook();

        // The old fallback shape: rejectattr('needs_mac','defined') unioned the
        // undeclared VMs into the target list; default(true) on the loop
        // condition did the same per task. Both must stay gone.
        self::assertStringNotContainsString(
            "rejectattr('needs_mac', 'defined')",
            $source,
            'a VM without a needs_mac statement must not be selected'
        );
        self::assertStringNotContainsString(
            'needs_mac | default(true)',
            $source,
            'the needs_mac fallback must not default to touching the VM'
        );

        $chains = $this->chains($source, self::PLAYBOOK);
        self::assertStringContainsString(
            "selectattr('needs_mac', 'defined') | selectattr('needs_mac')",
            $chains['powercycle_targets'],
            'targets are exactly the VMs that positively declare needs_mac'
        );
    }

    public function testInitialStateIsCapturedAndGuardedBeforeAnyChange(): void
    {
        $source = $this->playbook();

        // K15: the capture runs per VM in powercycle_vm_info.yml, the switching
        // per VM in powercycle_vm_tasks.yml; the playbook orders the two loops.
        // K4a: the read itself is the shared, repeated esxi_vm_read_tasks.yml.
        $infoTasks = $this->source(VIRTUSPHERE_POWERCYCLE_VM_INFO_TASKS);
        $readTasks = $this->source(VIRTUSPHERE_ESXI_VM_READ_TASKS);
        self::assertStringContainsString('include_tasks: ./' . VIRTUSPHERE_ESXI_VM_READ_TASKS, $infoTasks, 'the initial power state is captured via the shared read');
        self::assertStringContainsString('community.vmware.vmware_guest_info:', $readTasks);
        self::assertStringNotContainsString('vmware_guest_powerstate', $infoTasks . $readTasks, 'the capture never changes power state');
        self::assertStringContainsString('community.vmware.vmware_guest_powerstate:', $source);
        $infoPos = strpos($source, 'include_tasks: ./' . VIRTUSPHERE_POWERCYCLE_VM_INFO_TASKS);
        $powerPos = strpos($source, 'include_tasks: ./powercycle_vm_tasks.yml');
        self::assertIsInt($infoPos);
        self::assertIsInt($powerPos);
        self::assertLessThan($powerPos, $infoPos, 'the state capture must run before the first power change');

        // A reply without hw_power_status must fail loudly: silently selecting
        // nothing would surface as "0 MACs exported" with no error anywhere.
        // The watchdog uses map+default because a dotted selectattr THROWS on a
        // reply missing the attribute (proven in the fixture playbook), and it
        // must run BEFORE the selection so the chain only sees vetted data.
        $watchdog = "map(attribute='instance.hw_power_status', default='') | select('equalto', '')";
        $watchdogPos = strpos($source, $watchdog);
        self::assertIsInt($watchdogPos, 'a guest_info reply without instance.hw_power_status must be a hard error, not an empty selection');

        $chains = $this->chains($source, self::PLAYBOOK);
        self::assertStringContainsString(
            "selectattr('instance.hw_power_status', 'equalto', 'poweredOff')",
            $chains['powercycle_to_start'],
            'only VMs that were poweredOff at capture time may be started (running and suspended stay untouched)'
        );
        $selectionPos = strpos($source, 'powercycle_to_start:');
        self::assertIsInt($selectionPos);
        self::assertLessThan($selectionPos, $watchdogPos, 'the watchdog assert must run before the selection derives from the replies');
    }

    public function testEachPowercycleInfoCallHasItsOwnConfirmedTouchAndPreservesLoopResultShape(): void
    {
        $playbook = $this->source(self::PLAYBOOK);
        $infoTasks = $this->source(VIRTUSPHERE_POWERCYCLE_VM_INFO_TASKS);
        self::assertStringContainsString('include_tasks: ./powercycle_vm_info.yml', $playbook);
        self::assertStringContainsString('loop: "{{ powercycle_targets }}"', $playbook);
        self::assertStringContainsString('loop_var: powercycle_target', $playbook);

        $touchPos = strpos($infoTasks, './report_vm_touch.py');
        $queryPos = strpos($infoTasks, 'include_tasks: ./' . VIRTUSPHERE_ESXI_VM_READ_TASKS);
        self::assertIsInt($touchPos);
        self::assertIsInt($queryPos);
        self::assertLessThan($queryPos, $touchPos, 'the per-VM touch must be confirmed before that VM first reaches ESXi');
        self::assertStringContainsString("powercycle_target['portal_vm_id']", $infoTasks);
        self::assertStringContainsString("job_execution['attempt']", $infoTasks);
        self::assertStringContainsString("job_execution['generation']", $infoTasks);
        self::assertStringContainsString('- --phase', $infoTasks);
        self::assertStringContainsString('- powercycle', $infoTasks);
        self::assertStringContainsString('powercycle_current_info: "{{ vs_vm_read }}"', $infoTasks, 'the last read attempt is the result of this VM');
        self::assertStringContainsString("combine({'item': powercycle_target})", $infoTasks);
        self::assertStringContainsString('powercycle_initial.results', $infoTasks);
        self::assertStringContainsString("['failed']", $infoTasks);
        self::assertStringContainsString("['msg']", $infoTasks, 'the final failure names the module message');
    }

    public function testPowerOnAndCleanupSitInsideOneBlockAlways(): void
    {
        $tasks = $this->source('powercycle_vm_tasks.yml');
        $powerOn = $this->source(VIRTUSPHERE_POWERCYCLE_POWER_ON_TASKS);
        $powerOff = $this->source(VIRTUSPHERE_POWERCYCLE_POWER_OFF_TASKS);

        self::assertMatchesRegularExpression(
            '/block:.*include_tasks: \.\/' . preg_quote(VIRTUSPHERE_POWERCYCLE_POWER_ON_TASKS, '/') . '.*always:.*include_tasks: \.\/' . preg_quote(VIRTUSPHERE_POWERCYCLE_POWER_OFF_TASKS, '/') . '/s',
            $tasks,
            'the power-on must sit in a block whose always: powers the started VM off again'
        );
        self::assertSame(1, substr_count($this->playbook(), 'loop: "{{ powercycle_to_start }}"'), 'power-on uses the vetted candidate list exactly once');
        self::assertStringContainsString('loop_var: powercycle_vm', $this->playbook());
        self::assertMatchesRegularExpression(
            '/include_tasks: \.\/' . preg_quote(VIRTUSPHERE_POWERCYCLE_POWER_OFF_TASKS, '/') . '\s*\R\s*when: powercycle_started_by_run \| default\(\[\]\) \| length > 0/',
            $tasks,
            'cleanup runs only for a start this run proved'
        );

        // force: true is the cleanup's hard power-off (fresh VMs have no guest
        // OS to shut down); the power-on must not carry it.
        self::assertSame(1, preg_match_all('/force: true/', $this->playbook()), 'exactly one task (the cleanup power-off) may force');
        self::assertMatchesRegularExpression('/state: powered-on\s*\R\s*force: false/', $powerOn, 'the power-on carries force: false');
        self::assertMatchesRegularExpression('/state: powered-off\s*\R\s*force: true/', $powerOff);
        self::assertStringNotContainsString('state: powered-off', $powerOn);
        self::assertStringNotContainsString('state: powered-on', $powerOff);
    }

    /**
     * W2 (K4a): a failed or unclear power-on is never repeated blindly. The run
     * reads the state; a VM that was off at capture and is on now was moved by
     * this run and enters the cleanup list. Only a VM read as poweredOff is
     * powered on again, and an unchanged answer on such a later attempt also
     * counts as this run's start. A first-attempt unchanged answer stays
     * "external": somebody else started it, and the cleanup leaves it alone.
     */
    public function testAnUnclearPowerOnIsReadBackAndNeverRepeatedBlindly(): void
    {
        $tasks = $this->source('powercycle_vm_tasks.yml');
        $powerOn = $this->source(VIRTUSPHERE_POWERCYCLE_POWER_ON_TASKS);

        self::assertStringContainsString('register: powercycle_start_result', $powerOn);
        self::assertStringContainsString(
            "if ((powercycle_start_result['changed'] | default(false) | bool) or (powercycle_on_attempt | int) > 1)",
            $powerOn,
            'only a changed answer or an answer after a failed attempt of this run counts as started'
        );
        $readPos = strpos($powerOn, 'include_tasks: ./' . VIRTUSPHERE_ESXI_VM_READ_TASKS);
        $againPos = strpos($powerOn, 'include_tasks: ./' . VIRTUSPHERE_POWERCYCLE_POWER_ON_TASKS);
        self::assertIsInt($readPos, 'a failed power-on must read the state back');
        self::assertIsInt($againPos);
        self::assertLessThan($againPos, $readPos, 'the state read comes before any further power-on');
        self::assertStringContainsString("'started' if powercycle_on_state == 'poweredOn'", $powerOn, 'a VM read as on after a failed power-on is a start of this run');
        self::assertStringContainsString("powercycle_on_state == 'poweredOff'", $powerOn, 'only a VM read as off is powered on again');
        self::assertMatchesRegularExpression(
            '/include_tasks: \.\/' . preg_quote(VIRTUSPHERE_POWERCYCLE_POWER_ON_TASKS, '/') . '\s*\R\s*vars:\s*\R\s*powercycle_on_is_retry: true\s*\R\s*when: powercycle_on_outcome == \'retry\'/',
            $powerOn
        );
        self::assertStringContainsString('powercycle_started_by_run: "{{ [powercycle_vm] }}"', $tasks);
        self::assertStringContainsString("when: powercycle_on_outcome | default('') == 'started'", $tasks, 'only a proven start enters the cleanup list');
        self::assertStringContainsString("powercycle_on_outcome | default('unknown') in ['started', 'external']", $tasks, 'failed and unknown outcomes stay a hard playbook error');
        self::assertStringContainsString('ansible_failed_result', $tasks, 'the stop names the message of the failed task');
    }

    /**
     * The fixture playbook proves the selection against an/aus/suspendiert/
     * kaputt/leer without a host - which only proves anything while it runs the
     * SAME chains. Character-identical, both of them.
     *
     * Skipped where the repo root is not mounted (the dev stack container only
     * mounts Docker/WebAPI and Ansible/); the QA lanes mount the full repo and
     * run it.
     */
    public function testFixturePlaybookRunsTheIdenticalChains(): void
    {
        $fixturePath = dirname(__DIR__, 4) . '/' . self::FIXTURE_RELATIVE;
        if (!is_file($fixturePath)) {
            self::markTestSkipped('Repo root not visible; the fixture playbook only exists outside the container mount.');
        }

        $fixture = (string) file_get_contents($fixturePath);
        $playbookChains = $this->chains($this->playbook(), self::PLAYBOOK);
        $fixtureChains = $this->chains($fixture, self::FIXTURE_RELATIVE);

        foreach (self::CHAIN_FACTS as $fact) {
            self::assertSame(
                $playbookChains[$fact],
                $fixtureChains[$fact],
                sprintf('%s: the fixture must run the character-identical %s chain, or its proof covers a different playbook.', self::FIXTURE_RELATIVE, $fact)
            );
        }
    }
}
