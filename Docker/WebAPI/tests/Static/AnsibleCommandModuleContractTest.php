<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/ansible_command_modules.php';

/**
 * Pins the public surface of the deploy-mode and preflight command layer across
 * its Etappe 8 (ADR-0006) split. Same reason as AnsibleInventoryModuleContractTest:
 * a static contract that reads exactly one source file stops guarding the moment
 * that file is split into domain modules, silently and while still green.
 *
 * The surface list is what a caller of the old single file could call. It is
 * checked twice on purpose: once by loading only the facade, which proves the
 * require path still delivers everything, and once against the functions the
 * registered modules actually define, which proves nothing was added outside
 * the registry or defined twice.
 */
final class AnsibleCommandModuleContractTest extends TestCase
{
    private const SURFACE = [
        'ansible_job_payload',
        'ansible_filter_vms',
        'ansible_playbooks_for_mode',
        'ansible_modes_using_powercycle',
        'ansible_modes_using_start',
        'ansible_mode_expects_mac_result',
        'ansible_step_marker_line',
        'ansible_step_marker_parse',
        'ansible_step_failure_suffix',
        'ansible_remote_steps',
        'ansible_remote_cleanup_command',
        'ansible_preflight_checks',
        'ansible_preflight_command',
        'ansible_probe_opener_source',
        'ansible_allowlist_probe_source',
        'ansible_preflight_allowlist_verdict',
        'ansible_preflight_failed_component',
        'ansible_preflight_strip_markers',
        'ansible_sh_quote',
    ];

    public function testRegistryMatchesTheFilesystemInBothDirections(): void
    {
        $root = str_replace('\\', '/', dirname(__DIR__, 2));
        $files = array_map(
            static fn (string $path): string => 'lib/' . basename($path),
            glob($root . '/lib/ansible_command*.php') ?: []
        );
        $files = array_values(array_diff($files, ['lib/ansible_command_modules.php']));
        self::assertNotSame([], $files, 'Zero match: no ansible_command module was found on disk.');

        self::assertSame(
            $this->sorted($files),
            $this->sorted(VIRTUSPHERE_ANSIBLE_COMMAND_MODULES),
            'The Ansible command owner registry and filesystem disagree.'
        );
    }

    #[RunInSeparateProcess]
    public function testFacadeAloneDefinesTheCompleteSurface(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/ansible_command.php';

        $missing = array_values(array_filter(self::SURFACE, static fn (string $name): bool => !function_exists($name)));
        self::assertSame([], $missing, 'The facade does not define: ' . implode(', ', $missing));
    }

    public function testEveryFunctionHasExactlyOneRegisteredOwner(): void
    {
        $root = dirname(__DIR__, 2) . '/';
        $owners = [];
        foreach (VIRTUSPHERE_ANSIBLE_COMMAND_MODULES as $module) {
            if ($module === 'lib/ansible_command.php') {
                // The facade itself defines no function of its own; it only
                // requires the domain modules.
                continue;
            }
            $source = (string) file_get_contents($root . $module);
            preg_match_all('/^function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/m', $source, $matches);
            foreach ($matches[1] as $function) {
                $owners[$function][] = $module;
            }
        }
        self::assertNotSame([], $owners, 'No function was found in the registered Ansible command modules.');

        $duplicates = array_filter($owners, static fn (array $files): bool => count($files) !== 1);
        self::assertSame([], $duplicates, 'An Ansible command function has more than one owner.');
        self::assertSame($this->sorted(self::SURFACE), $this->sorted(array_keys($owners)));
    }

    public function testShellQuotingHasExactlyOneImplementation(): void
    {
        // The split's one real risk: two remote-command domains, each tempted
        // to keep a local quoting helper. A second rule is a command-injection
        // difference that nobody notices while both files still look right.
        $root = dirname(__DIR__, 2) . '/';
        $definitions = [];
        foreach (glob($root . 'lib/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            if (preg_match('/^function\s+ansible_sh_quote\s*\(/m', $source) === 1) {
                $definitions[] = 'lib/' . basename($file);
            }
        }

        self::assertSame(['lib/ansible_command_shell.php'], $definitions);
    }

    /** @param array<int, string> $values @return array<int, string> */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
