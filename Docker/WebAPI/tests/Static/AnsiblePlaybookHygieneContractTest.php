<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/ansible.php';
require_once dirname(__DIR__, 2) . '/lib/ansible_inventory_modules.php';

/**
 * Playbook hygiene contract (Plan v2, AP5): error tolerance and secret
 * handling in the Ansible layer are deliberate, classified decisions, not
 * habits. Every rule here pins an existing, commented decision; a new
 * violation fails the build until it is classified the same way.
 *
     *  - `ignore_errors: true` is a scalpel, never a blanket: each use must
     *    register its result (so something evaluates the failure) and appear in
     *    the per-file allowlist below. Export's per-VM result include and the
     *    inventory's optional info modules (ADR-0023) are documented cases.
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
        // 2: the async status read and, since DF-L9, the informational power
        // state read after a proven success (reported as unknown on error).
        'createVMStatus-ESXi_playbook.yml' => 2,
        'exportVMs-Informations-ESXi_playbook.yml' => 0,
        'export_vm_info.yml' => 1,
        'inventoryESXi_playbook.yml' => 7,
        'powercycle_vm_info.yml' => 1,
        'powercycle_vm_tasks.yml' => 1,
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

    /**
     * Every read of a host's VM list asks for every VM and only for the fields
     * the readers keep: name, MOID, instance UUID and power state, which
     * vmware_vm_info always reports. Each of its show_* switches costs extra
     * ESXi requests per VM, and eight are on by default; with them the create
     * identity read took about 20 seconds on Prod (15.10.2026), three times per
     * VM. The gate ansible-create-async derives the switches from the pinned
     * module for the identity read; this test holds every other call site to
     * the same switches, so the derivation covers them as well.
     *
     * A name or folder filter is refused: IDR-P02 finds a renamed bound VM by
     * looking its stored UUID up among ALL VMs of the host.
     */
    public function testEveryVmListReadSkipsTheDetailLookupsAndFiltersNothing(): void
    {
        $switchSets = [];
        foreach ($this->playbooks() as $name => $source) {
            foreach ($this->moduleArguments($source, 'community.vmware.vmware_vm_info') as $arguments) {
                foreach (['vm_name', 'folder'] as $filter) {
                    self::assertArrayNotHasKey($filter, $arguments, sprintf('%s narrows the VM list by %s; a renamed bound VM would be invisible.', $name, $filter));
                }
                $switches = [];
                foreach ($arguments as $key => $value) {
                    if (str_starts_with($key, 'show_')) {
                        self::assertSame('false', $value, sprintf('%s: %s must be false.', $name, $key));
                        $switches[] = $key;
                    }
                }
                sort($switches);
                $switchSets[] = [$name, $switches];
            }
        }

        self::assertGreaterThanOrEqual(2, count($switchSets), 'VM list scan found fewer reads than the repo contains (zero-match must not pass).');
        $reference = [];
        foreach ($switchSets as [$name, $switches]) {
            if ($name === 'create_identity_check_tasks.yml') {
                $reference = $switches;
            }
        }
        self::assertNotEmpty($reference, 'The create identity read must switch the detail lookups off.');
        foreach ($switchSets as [$name, $switches]) {
            self::assertSame($reference, $switches, sprintf('%s reads the VM list with other switches than the create identity check.', $name));
        }
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

        // Zero-match guard: upload, smoke and both per-VM touch clients exist.
        self::assertGreaterThanOrEqual(4, $commandTasks, 'Command-task scan found fewer tasks than the repo contains.');
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

    /**
     * AN-01/AN-02: every read of a live VM that precedes a mutation or an export
     * addresses the VM by its bound instance UUID. A name is shared by namesakes,
     * and a read by name can return the foreign VM before the identity check
     * compares it. The read is one call per playbook, and it sets
     * use_instance_uuid so the module treats the value as an instance UUID.
     */
    public function testIdentityReadsAddressTheBoundInstanceUuidNotTheName(): void
    {
        foreach ([
            'export_vm_info.yml' => 'export_vm_config',
            'powercycle_vm_info.yml' => 'powercycle_target',
            'startVMs-ESXi_playbook.yml' => 'item',
            'autostartVMs-ESXi_playbook.yml' => 'item',
        ] as $file => $loopItem) {
            $calls = $this->moduleArguments($this->playbooks()[$file] ?? '', 'community.vmware.vmware_guest_info');
            self::assertCount(1, $calls, $file . ' must read its live VM exactly once (zero-match must not pass).');
            $arguments = $calls[0];

            self::assertArrayNotHasKey('name', $arguments, $file . ' reads the VM by name; a namesake would be the object that is read.');
            self::assertSame('true', $arguments['use_instance_uuid'] ?? '', $file . ' must set use_instance_uuid.');
            self::assertMatchesRegularExpression(
                '/\b' . preg_quote($loopItem, '/') . '(?:\.vm_instance_uuid|\[\'vm_instance_uuid\'\])/',
                $arguments['uuid'] ?? '',
                $file . ' must address the VM by its bound vm_instance_uuid.'
            );
        }
    }

    /**
     * AN-07: the identity assertions name the stored and the live instance UUID,
     * and the export's per-VM line names its reason. Identity fields only: a
     * module argument or the ESXi credentials never appear in these messages.
     */
    public function testIdentityMessagesNameBothInstanceUuidsWithoutModuleArguments(): void
    {
        foreach (['startVMs-ESXi_playbook.yml', 'autostartVMs-ESXi_playbook.yml', 'powercycleVMs-ESXi_playbook.yml'] as $file) {
            $source = $this->playbooks()[$file] ?? '';
            self::assertSame(
                1,
                preg_match('/VirtuSphere VM identity mismatch before .*?stored instance UUID.*?live instance UUID/s', $source),
                $file . ' names no stored and live instance UUID in its identity assertion.'
            );
        }

        $export = $this->playbooks()['export_vm_info.yml'] ?? '';
        self::assertStringContainsString('export_result_reason', $export, 'the export line must carry the reason of a failed VM');
        self::assertStringContainsString("': query_failed'", $export);
        self::assertStringContainsString('identity mismatch, stored instance UUID', $export);
        // The query task of this file does carry the password argument; only the
        // reason block that the line prints is held to "no credentials".
        self::assertSame(
            1,
            preg_match('/export_result_reason: >-(.*?)- name: Ergebnis dieser Exportabfrage melden/s', $export, $reason),
            'the reason block is not where the export line expects it'
        );
        self::assertStringNotContainsString('password', $reason[1], 'the reason must not print credentials');
    }

    /**
     * AN-06: a refused touch confirmation stops the export before the upload.
     * The stop must say that the results already read were NOT uploaded.
     */
    public function testARefusedExportConfirmationSaysWhatWasNotUploaded(): void
    {
        $export = $this->playbooks()['export_vm_info.yml'] ?? '';
        self::assertStringContainsString('were NOT uploaded: start the export again.', $export);
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
        // The mission builder moved into a domain module too (Etappe 8); same
        // reason as the inventory twin below, so both read their registry.
        require_once dirname(__DIR__, 2) . '/lib/ansible_command_modules.php';
        $commandSource = '';
        foreach (VIRTUSPHERE_ANSIBLE_COMMAND_MODULES as $module) {
            $commandSource .= (string) file_get_contents(dirname(__DIR__, 2) . '/' . $module);
        }
        self::assertNotSame('', $commandSource, 'the Ansible command module registry produced no source.');
        self::assertStringContainsString(
            "'chmod 600 accounts.yml'",
            $commandSource,
            'The remote command builder must chmod accounts.yml (contains the ESXi password) to 600 before running playbooks.'
        );

        // The inventory twin's remote-command builder moved into a domain
        // module (Etappe 7, ADR-0006); the owner registry is what still
        // decides which file to read, so a later reshuffle cannot silently
        // drop this check the way a hardcoded filename would.
        $inventorySource = '';
        foreach (VIRTUSPHERE_ANSIBLE_INVENTORY_MODULES as $module) {
            $inventorySource .= (string) file_get_contents(dirname(__DIR__, 2) . '/' . $module);
        }
        self::assertStringContainsString(
            "'chmod 600 accounts.yml'",
            $inventorySource,
            'The Ansible inventory modules must chmod accounts.yml (contains the ESXi password) to 600 before running playbooks.'
        );
    }

    /** @return array<string, string> playbook file name => source */
    private function playbooks(): array
    {
        $paths = glob(ansible_source_dir() . DIRECTORY_SEPARATOR . '*_playbook.yml');
        self::assertIsArray($paths);
        self::assertNotEmpty($paths, 'No playbooks found (zero-match must not pass).');
        $paths = array_merge(
            $paths,
            glob(ansible_source_dir() . DIRECTORY_SEPARATOR . '*_tasks.yml') ?: [],
            glob(ansible_source_dir() . DIRECTORY_SEPARATOR . '*_vm_info.yml') ?: []
        );

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

    /**
     * The arguments of each call of one module: the lines indented below its
     * key, as key => raw scalar. Read from the module key rather than from
     * tasks(), which splits at blank lines and would hand a task directly under
     * a comment block to the part it drops.
     *
     * @return list<array<string, string>>
     */
    private function moduleArguments(string $source, string $module): array
    {
        $lines = preg_split('/\R/', $source) ?: [];
        $calls = [];
        foreach ($lines as $index => $line) {
            if (preg_match('/^(\s*)' . preg_quote($module, '/') . ':\s*$/', $line, $key) !== 1) {
                continue;
            }
            $arguments = [];
            for ($next = $index + 1; $next < count($lines); $next++) {
                preg_match('/^(\s*)(\w+):\s*(.*)$/', $lines[$next], $argument);
                if ($argument === [] || strlen($argument[1]) <= strlen($key[1])) {
                    break;
                }
                $arguments[$argument[2]] = trim($argument[3]);
            }
            $calls[] = $arguments;
        }

        return $calls;
    }
}
