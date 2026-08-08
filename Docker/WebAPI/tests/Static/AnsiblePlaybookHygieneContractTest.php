<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/ansible.php';

/**
 * Playbook hygiene contract (Plan v2, AP5): error tolerance and secret
 * handling in the Ansible layer are deliberate, classified decisions, not
 * habits. Every rule here pins an existing, commented decision; a new
 * violation fails the build until it is classified the same way.
 *
 *  - `ignore_errors: true` is a scalpel, never a blanket: each use must
 *    register its result (so something evaluates the failure) and appear in
 *    the per-file allowlist below. The export playbook's per-item tolerance
 *    (AP1.3/L1) and the inventory's optional info modules (ADR-0023) are the
 *    only documented cases.
 *  - Every `command`/`shell` task declares changed_when or failed_when: an
 *    unclassified command reports "changed" forever and hides real failures
 *    in the second (idempotence) run.
 *  - The ESXi password reaches playbooks only as the `password:` module
 *    argument, which the community.vmware argument spec marks no_log. Any
 *    other appearance (debug msg, file content, command line) would leak it
 *    into logs or artifacts, so none is allowed.
 *  - Credential files stay private: the exported vm_infos.json keeps its
 *    0600 mode and both remote command builders chmod accounts.yml to 600
 *    before any playbook runs.
 */
final class AnsiblePlaybookHygieneContractTest extends TestCase
{
    /**
     * File => allowed number of `ignore_errors: true` tasks. Every allowed
     * use is commented in the playbook itself; raising a number here without
     * that justification is the review conversation this test exists to force.
     */
    private const IGNORE_ERRORS_ALLOWLIST = [
        'exportVMs-Informations-ESXi_playbook.yml' => 1,
        'inventoryESXi_playbook.yml' => 7,
    ];

    public function testIgnoreErrorsOnlyWhereClassifiedAndAlwaysRegistered(): void
    {
        foreach ($this->playbooks() as $name => $source) {
            $allowed = self::IGNORE_ERRORS_ALLOWLIST[$name] ?? 0;
            $count = preg_match_all('/^\s*ignore_errors:\s*true\b/m', $source);
            self::assertSame(
                $allowed,
                $count,
                sprintf(
                    '%s has %d ignore_errors task(s), allowlist says %d. A new tolerance must be commented in the playbook and classified here.',
                    $name,
                    $count,
                    $allowed
                )
            );

            if ($count === 0) {
                continue;
            }

            // Tolerated failures must be evaluated: every task that ignores
            // errors registers its result. Tasks are separated by "- name:".
            foreach ($this->tasks($source) as $task) {
                if (preg_match('/^\s*ignore_errors:\s*true\b/m', $task) === 1) {
                    self::assertMatchesRegularExpression(
                        '/^\s*register:\s*\w+/m',
                        $task,
                        sprintf('%s: an ignore_errors task must register its result for evaluation.', $name)
                    );
                }
            }
        }
    }

    /**
     * The blind spot `ignore_errors` opens: a task whose arguments the module
     * rejects fails before it ever reaches ESXi, and the pull still counts as a
     * success with an empty list. `vmware_portgroup_info` requires one of
     * cluster_name/esxi_hostname, `vmware_dvs_portgroup_info` requires
     * datacenter (`ansible-playbook` answers "one of the following is required"
     * / "missing required arguments" against the pinned collection). Neither
     * was passed, so the portal showed 0 portgroups for a host with thirteen
     * and nothing anywhere reported a problem.
     *
     * The match must be anchored to the argument line: every task in this
     * playbook already carries `hostname: "{{ esxi_hostname }}"`, so a
     * substring search for the argument name passes on the broken shape too.
     *
     * @return iterable<string, array{0:string, 1:string}>
     */
    public static function inventoryRequiredArguments(): iterable
    {
        yield 'standard portgroups' => ['vmware_portgroup_info', '(esxi_hostname|cluster_name)'];
        yield 'distributed portgroups' => ['vmware_dvs_portgroup_info', 'datacenter'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('inventoryRequiredArguments')]
    public function testInventoryInfoTasksPassTheArgumentsTheirModuleRequires(string $module, string $argument): void
    {
        $source = $this->playbooks()['inventoryESXi_playbook.yml'] ?? '';
        self::assertNotSame('', $source);

        $found = false;
        foreach ($this->tasks($source) as $task) {
            if (!str_contains($task, $module . ':')) {
                continue;
            }
            $found = true;
            self::assertMatchesRegularExpression(
                '/^\s+' . $argument . ':\s*\S/m',
                $task,
                sprintf('%s is called without the argument its module requires; the task can only ever fail.', $module)
            );
        }

        self::assertTrue($found, sprintf('No task calls %s (zero-match must not pass).', $module));
    }

    /**
     * Every query the inventory pull makes must report its own outcome. The
     * `queries` block of the marker is what lets the job log say why a count is
     * 0; a task added later that is not listed there would be exactly as silent
     * as the portgroup task was, and its emptiness would again read as an
     * answer from the host.
     */
    public function testEveryInventoryQueryReportsItsOutcome(): void
    {
        $source = $this->playbooks()['inventoryESXi_playbook.yml'] ?? '';
        self::assertNotSame('', $source);

        self::assertSame(
            1,
            preg_match('/^\s*queries:\s*>-\R(.+?)^\s*- name:/ms', $source, $block),
            'The inventory playbook must carry a queries block reporting each query outcome.'
        );

        preg_match_all('/^\s*register:\s*(\w+)/m', $source, $registered);
        self::assertGreaterThanOrEqual(7, count($registered[1]), 'Register scan found fewer tasks than the playbook contains.');
        foreach ($registered[1] as $name) {
            self::assertStringContainsString(
                $name . '.failed',
                $block[1],
                sprintf('Task result %s is not reported in the queries block, so its empty result would be indistinguishable from an answer.', $name)
            );
        }
    }

    /**
     * Decision 6: the deployment collision gate can only distinguish an owned
     * VM from a foreign namesake when the read-only inventory supplies the
     * host's VM names, MOIDs and instance UUIDs. The product UUID is a separate
     * field and must never substitute for the durable instance identity.
     */
    public function testInventoryQueriesVirtualMachinesForTheCollisionGate(): void
    {
        $source = $this->playbooks()['inventoryESXi_playbook.yml'] ?? '';
        self::assertNotSame('', $source);
        self::assertStringContainsString('community.vmware.vmware_vm_info:', $source);
        self::assertMatchesRegularExpression('/^\s*register:\s*vs_vms\s*$/m', $source);
        self::assertStringContainsString('vs_vms.virtual_machines', $source);
        self::assertMatchesRegularExpression("/^\s*'vms':\s*\{/m", $source);
    }

    public function testEveryCommandTaskIsClassified(): void
    {
        $commandTasks = 0;
        foreach ($this->playbooks() as $name => $source) {
            foreach ($this->tasks($source) as $task) {
                if (preg_match('/^\s*(ansible\.builtin\.)?(command|shell):/m', $task) !== 1) {
                    continue;
                }
                $commandTasks++;
                self::assertMatchesRegularExpression(
                    '/^\s*(changed_when|failed_when):/m',
                    $task,
                    sprintf('%s: a command/shell task must declare changed_when and/or failed_when.', $name)
                );
            }
        }

        // Zero-match guard: the export upload and the linux smoke command exist.
        self::assertGreaterThanOrEqual(2, $commandTasks, 'Command-task scan found fewer tasks than the repo contains.');
    }

    public function testEsxiPasswordOnlyAppearsAsTheNoLogModuleArgument(): void
    {
        $seen = 0;
        foreach ($this->playbooks() as $name => $source) {
            foreach (preg_split('/\R/', $source) ?: [] as $lineNo => $line) {
                if (!str_contains($line, 'esxi_password')) {
                    continue;
                }
                $seen++;
                self::assertMatchesRegularExpression(
                    '/^\s*password:\s*"\{\{\s*esxi_password\s*\}\}"\s*$/',
                    $line,
                    sprintf(
                        '%s:%d carries esxi_password outside the no_log `password:` module argument: %s',
                        $name,
                        $lineNo + 1,
                        trim($line)
                    )
                );
            }
        }

        self::assertGreaterThan(0, $seen, 'No playbook references esxi_password (zero-match must not pass).');
    }

    public function testExportedVmInfoFileKeepsPrivateMode(): void
    {
        $playbook = $this->playbooks()['exportVMs-Informations-ESXi_playbook.yml'] ?? '';
        self::assertNotSame('', $playbook);
        self::assertMatchesRegularExpression(
            '/dest:\s*"\.\/vm_infos\.json".*?mode:\s*"0600"/s',
            $playbook,
            'vm_infos.json (VM names + MACs) must be written 0600.'
        );
    }

    public function testRemoteCommandBuildersChmodAccountsBeforeAnyPlaybook(): void
    {
        foreach (['ansible_command.php', 'ansible_inventory.php'] as $file) {
            $source = file_get_contents(dirname(__DIR__, 2) . '/lib/' . $file);
            self::assertIsString($source);
            self::assertStringContainsString(
                "'chmod 600 accounts.yml'",
                $source,
                sprintf('%s must chmod accounts.yml (contains the ESXi password) to 600 before running playbooks.', $file)
            );
        }
    }

    /** @return array<string, string> playbook file name => source */
    private function playbooks(): array
    {
        $paths = glob(ansible_source_dir() . DIRECTORY_SEPARATOR . '*_playbook.yml');
        self::assertIsArray($paths);
        self::assertNotEmpty($paths, 'No playbooks found (zero-match must not pass).');

        $playbooks = [];
        foreach ($paths as $path) {
            $source = file_get_contents($path);
            self::assertIsString($source);
            $playbooks[basename($path)] = $source;
        }

        return $playbooks;
    }

    /**
     * Task blocks of one playbook: split at each "- name:" list entry. Coarse
     * but sufficient for per-task attribute checks; comments stay attached to
     * the task they precede.
     *
     * @return string[]
     */
    private function tasks(string $source): array
    {
        $parts = preg_split('/^(?=\s+- name:)/m', $source);
        self::assertIsArray($parts);

        return array_slice($parts, 1);
    }
}
