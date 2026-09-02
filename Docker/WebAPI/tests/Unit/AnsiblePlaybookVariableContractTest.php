<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/ansible.php';

/**
 * Cross-language contract (Plan v2, AP4): every variable a playbook reads must
 * exist in the YAML that ansible_accounts_yml()/ansible_serverlist_yml()
 * generate, and every per-VM key the generator emits must have a consumer (or
 * be on the documented parity list). The campaign 2026-07 did this comparison
 * once by hand; this test keeps it true. It parses the real playbooks from
 * ansible_source_dir(), so a renamed playbook variable or generator key fails
 * here before it fails on an ESXi host.
 */
final class AnsiblePlaybookVariableContractTest extends TestCase
{
    /**
     * Generated per-VM keys nothing reads via Jinja. They exist for parity
     * with the desktop client's serverlist format and as operator
     * documentation inside the artifact; retire with the desktop API at E3.
     */
    private const VM_KEYS_WITHOUT_CONSUMER = ['packages', 'os', 'vm_moid'];

    /** Jinja filters/keywords and Ansible builtins that never name our data. */
    private const NON_DATA_TOKENS = [
        'default', 'int', 'bool', 'length', 'list', 'map', 'attribute', 'lower',
        'first', 'selectattr', 'rejectattr', 'combine', 'dict2items', 'flatten',
        'to_json', 'to_nice_json', 'b64encode', 'trim', 'lookup',
        // Filters and tests the create control playbooks use (Etappe 14B).
        'string', 'replace', 'truncate', 'match', 'equalto',
        'if', 'else', 'not', 'and', 'or', 'in', 'is', 'defined',
        'true', 'false', 'True', 'False', 'none', 'None',
        'item', 'ansible_date_time', 'ansible_facts', 'hostvars',
        'inventory_hostname', 'playbook_dir', 'omit',
    ];

    public function testEveryPlaybookVariableIsGeneratedAndEveryVmKeyHasAConsumer(): void
    {
        [$topKeys, $vmKeys, $missionKeys] = $this->generatedServerlistKeys();
        $accountKeys = $this->generatedAccountKeys();

        $consumedVmRoots = [];
        foreach ($this->playbooks() as $name => $source) {
            $loadsServerlist = str_contains($source, 'serverlist.yml');
            $loadsAccounts = str_contains($source, 'accounts.yml');

            $provided = self::NON_DATA_TOKENS;
            if ($loadsServerlist) {
                $provided = array_merge($provided, $topKeys);
            }
            if ($loadsAccounts) {
                $provided = array_merge($provided, $accountKeys);
            }
            $provided = array_merge($provided, $this->locallyDefinedNames($source));
            // Third source, next to the two generated files: the extra-vars the
            // worker passes to a create control playbook. Declared in
            // VIRTUSPHERE_CREATE_EXTRA_VARS rather than listed here, and checked
            // in the other direction below, so a playbook cannot read a name
            // nobody passes and a declaration cannot outlive its reader.
            $provided = array_merge($provided, VIRTUSPHERE_CREATE_EXTRA_VARS[$name] ?? []);
            // Names a file included with include_tasks defines. Without this a
            // playbook could not use an include at all, and the shared identity
            // check exists precisely so three playbooks do not each keep their
            // own copy of that matrix.
            $provided = array_merge($provided, $this->includedTaskNames($source));

            foreach ($this->rootIdentifiers($source) as $root) {
                self::assertContains(
                    $root,
                    $provided,
                    sprintf('%s reads "%s", which neither generator nor playbook provides.', $name, $root)
                );
            }

            if (!$loadsServerlist) {
                continue;
            }

            // `item` in a loop and `vs_target` in a create control call are the
            // same thing under two names: the one VM entry of the serverlist a
            // task is working on. The create flow deliberately has no loop, so a
            // scan that knows only `item` stops seeing the per-VM keys the
            // moment the loop disappears - and would then report a key as
            // unconsumed exactly when it moved into the new path. The included
            // task files count as part of the playbook that includes them,
            // because the shared identity check is where the create flow reads
            // the stored instance uuid.
            $scanned = $source;
            foreach ($this->includedTaskFiles($source) as $included) {
                $scanned .= "\n" . (string) file_get_contents(ansible_source_dir() . DIRECTORY_SEPARATOR . $included);
            }
            preg_match_all('/\b(?:item|vs_target)\.((?:\w+\.)*\w+)/', $scanned, $matches);
            foreach (array_unique($matches[1]) as $path) {
                self::assertContains(
                    $path,
                    $vmKeys,
                    sprintf('%s reads item.%s, which ansible_serverlist_yml() does not emit.', $name, $path)
                );
                $consumedVmRoots[] = explode('.', $path)[0];
            }

            // Filter chains consume keys by name, not via item.<key>: the
            // power-cycle target selection reads needs_mac through
            // selectattr('needs_mac', ...). Counted only for the reverse
            // direction; names that are no VM key (instance, item) are simply
            // never asked about there.
            preg_match_all("/(?:selectattr|rejectattr)\\('(\\w+)'|map\\(attribute='(\\w+)/", $source, $attrMatches);
            foreach (array_merge($attrMatches[1], $attrMatches[2]) as $attribute) {
                if ($attribute !== '') {
                    $consumedVmRoots[] = $attribute;
                }
            }

            preg_match_all('/\bmission_configuration\.((?:\w+\.)*\w+)/', $source, $matches);
            foreach (array_unique($matches[1]) as $path) {
                self::assertContains(
                    'mission_configuration.' . $path,
                    $missionKeys,
                    sprintf('%s reads mission_configuration.%s, which the generator does not emit.', $name, $path)
                );
            }
        }

        // Reverse direction: a generator key nobody reads is either documented
        // parity or a typo that silently starves a playbook.
        $consumedVmRoots = array_unique($consumedVmRoots);
        foreach ($vmKeys as $key) {
            if (str_contains($key, '.')) {
                continue;
            }
            if (in_array($key, self::VM_KEYS_WITHOUT_CONSUMER, true)) {
                continue;
            }
            self::assertContains(
                $key,
                $consumedVmRoots,
                sprintf('Generated per-VM key "%s" has no playbook consumer and is not on the parity list.', $key)
            );
        }
    }

    /** @return array{0: string[], 1: string[], 2: string[]} top, per-VM, mission_configuration keys */
    private function generatedServerlistKeys(): array
    {
        $mission = [
            'id' => 7,
            'mission_name' => 'Contract',
            'hypervisor_datacenter' => 'DC1',
            'hypervisor_datastorage' => 'ds1',
            'wds_vlan' => 'VLAN10',
        ];
        $vm = [
            'vm_name' => 'vm01',
            'vm_ram' => '4096',
            'vm_cpu' => '2',
            'vm_guest_id' => 'windows2019srv_64Guest',
            'disks' => [],
            'interfaces' => [],
            'packages' => [],
        ];
        $yaml = ansible_serverlist_yml($mission, [$vm], 5, 'DC1', 'esxi01');

        $topKeys = [];
        $vmKeys = [];
        $missionKeys = [];
        $context = '';
        $lastVmKey = '';
        $lastMissionSub = '';
        foreach (preg_split('/\R/', $yaml) ?: [] as $line) {
            if (preg_match('/^([A-Za-z_]\w*):/', $line, $m) === 1) {
                $topKeys[] = $m[1];
                $context = $m[1];
                continue;
            }
            if ($context === 'vm_configurations') {
                if (preg_match('/^  (?:- )?(\w+):/', $line, $m) === 1 || preg_match('/^    (\w+):/', $line, $m) === 1) {
                    $vmKeys[] = $m[1];
                    $lastVmKey = $m[1];
                } elseif (preg_match('/^      (?:- )?(\w+):/', $line, $m) === 1 || preg_match('/^        (\w+):/', $line, $m) === 1) {
                    $vmKeys[] = $lastVmKey . '.' . $m[1];
                }
            } elseif ($context === 'mission_configuration') {
                if (preg_match('/^  (\w+):/', $line, $m) === 1) {
                    $missionKeys[] = 'mission_configuration.' . $m[1];
                    $lastMissionSub = $m[1];
                } elseif (preg_match('/^    (\w+):/', $line, $m) === 1) {
                    $missionKeys[] = 'mission_configuration.' . $lastMissionSub . '.' . $m[1];
                }
            }
        }

        // Guard the parser itself: an empty set would let everything pass.
        self::assertContains('vm_configurations', $topKeys);
        self::assertContains('autostart.enabled', $vmKeys);
        self::assertContains('mission_configuration.autostart.esxi_host', $missionKeys);

        return [array_unique($topKeys), array_unique($vmKeys), array_unique($missionKeys)];
    }

    /** @return string[] */
    private function generatedAccountKeys(): array
    {
        $yaml = ansible_accounts_yml(
            ['host' => 'esxi01.example.invalid', 'port' => 443, 'username' => 'root'],
            'secret',
            ['username' => 'ansible'],
            'http://10.10.10.10:8021'
        );
        preg_match_all('/^([A-Za-z_]\w*):/m', $yaml, $matches);
        self::assertContains('esxi_hostname', $matches[1]);

        return $matches[1];
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
     * Names defined by every task file this source includes.
     *
     * @return string[]
     */
    private function includedTaskNames(string $source): array
    {
        preg_match_all('/include_tasks:\s*\.\/([\w.-]+\.yml)/', $source, $matches);
        $names = [];
        foreach (array_unique($matches[1]) as $file) {
            $path = ansible_source_dir() . DIRECTORY_SEPARATOR . $file;
            self::assertFileExists($path, 'A playbook includes ' . $file . ', which is not in the Ansible source directory.');
            $names = array_merge($names, $this->locallyDefinedNames((string) file_get_contents($path)));
        }

        return $names;
    }

    public function testEveryDeclaredCreateExtraVarIsReadBySomePlaybook(): void
    {
        // The reverse direction of the extra-var declaration. A name that no
        // playbook reads any more would keep the worker passing something into
        // nothing, and the next reader of the constant would believe it matters.
        $playbooks = $this->playbooks();
        foreach (VIRTUSPHERE_CREATE_EXTRA_VARS as $playbook => $names) {
            self::assertArrayHasKey($playbook, $playbooks, 'Extra-vars are declared for a playbook that does not exist.');
            $source = $playbooks[$playbook] . "\n" . implode("\n", array_map(
                fn (string $file): string => (string) file_get_contents(ansible_source_dir() . DIRECTORY_SEPARATOR . $file),
                $this->includedTaskFiles($playbooks[$playbook])
            ));
            foreach ($names as $variable) {
                self::assertStringContainsString(
                    $variable,
                    $source,
                    sprintf('%s declares the extra-var "%s", which the playbook never reads.', $playbook, $variable)
                );
            }
        }
    }

    /** @return string[] */
    private function includedTaskFiles(string $source): array
    {
        preg_match_all('/include_tasks:\s*\.\/([\w.-]+\.yml)/', $source, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Names a playbook defines itself: register targets, set_fact keys and
     * play-level vars.
     *
     * @return string[]
     */
    private function locallyDefinedNames(string $source): array
    {
        $names = [];
        preg_match_all('/^\s*register:\s*(\w+)/m', $source, $matches);
        $names = array_merge($names, $matches[1]);

        foreach (['set_fact', 'vars'] as $block) {
            if (preg_match_all('/^(\s*)(?:ansible\.builtin\.)?' . $block . ':\s*$((?:\R^\1\s+.*$)+)/m', $source, $blocks, PREG_SET_ORDER) > 0) {
                foreach ($blocks as $match) {
                    preg_match_all('/^\s*(\w+):/m', $match[2], $keys);
                    $names = array_merge($names, $keys[1]);
                }
            }
        }

        return $names;
    }

    /**
     * Root identifiers of every Jinja expression and bare condition line
     * (when/failed_when/changed_when), with quoted strings stripped.
     *
     * @return string[]
     */
    private function rootIdentifiers(string $source): array
    {
        $expressions = [];
        preg_match_all('/\{\{(.+?)\}\}/s', $source, $matches);
        $expressions = array_merge($expressions, $matches[1]);
        preg_match_all('/^\s*(?:when|failed_when|changed_when):\s*(.+)$/m', $source, $matches);
        $expressions = array_merge($expressions, $matches[1]);

        $roots = [];
        foreach ($expressions as $expression) {
            $bare = preg_replace('/\'[^\']*\'|"[^"]*"/', ' ', $expression) ?? '';
            preg_match_all('/(?<![\w.])([A-Za-z_]\w*)/', $bare, $tokens);
            foreach ($tokens[1] as $token) {
                if (!in_array($token, self::NON_DATA_TOKENS, true)) {
                    $roots[] = $token;
                }
            }
        }

        return array_unique($roots);
    }
}
