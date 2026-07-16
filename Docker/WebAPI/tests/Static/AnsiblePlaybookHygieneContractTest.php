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
        'inventoryESXi_playbook.yml' => 6,
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
