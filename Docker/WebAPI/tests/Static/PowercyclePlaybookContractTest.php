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

        $infoPos = strpos($source, 'community.vmware.vmware_guest_info:');
        $powerPos = strpos($source, 'community.vmware.vmware_guest_powerstate:');
        self::assertIsInt($infoPos, 'the playbook must capture the initial power state via vmware_guest_info');
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

    public function testPowerOnAndCleanupLoopOverTheSameDerivedListInsideBlockAlways(): void
    {
        $source = $this->playbook();

        self::assertMatchesRegularExpression(
            '/block:.*state: powered-on.*always:.*state: powered-off/s',
            $source,
            'the power-on must sit in a block whose always: powers the started VMs off again'
        );

        preg_match_all('/^\s*loop:\s*"\{\{\s*(\w+)\s*\}\}"\s*$/m', $source, $loops);
        $powerLoops = array_filter($loops[1], static fn (string $var): bool => $var !== 'powercycle_targets');
        self::assertSame(
            ['powercycle_to_start', 'powercycle_to_start'],
            array_values($powerLoops),
            'power-on and cleanup must loop over the same derived list; anything else can touch a VM this run did not start'
        );

        // force: true is the cleanup's hard power-off (fresh VMs have no guest
        // OS to shut down); the power-on must not carry it.
        self::assertSame(
            1,
            preg_match_all('/force: true/', $source),
            'exactly one task (the cleanup power-off) may force'
        );
        self::assertMatchesRegularExpression(
            '/state: powered-on.*?force: false/s',
            $source,
            'the power-on carries force: false'
        );
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
