<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/ansible.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_jobs.php';

/**
 * Every deliberate pause in a playbook, against the constant that feeds it and
 * against the budget of the layer above it. The sibling of
 * AnsiblePlaybookHygieneContractTest for the one defect that cost a whole
 * deploy mode.
 *
 * What happened: accounts.yml carried `WaitingTime: "60"` and
 * startVMs-ESXi_playbook.yml read it as `pause: minutes:`. Sixty minutes of
 * silence, while lib/ssh.php aborts a remote command after
 * VIRTUSPHERE_SSH_IDLE_TIMEOUT_SECONDS without output, so mode `start` could
 * never power a VM on and mode `full` died in its last step with every VM off.
 * Nothing anywhere compared the two numbers, because they live in two languages.
 *
 * The four rules below are that comparison:
 *  - a pause is measured in SECONDS. A unit conversion between the emitting
 *    constant and the pausing playbook is the whole failure, so there is not
 *    allowed to be one.
 *  - a pause reads a variable this repo emits, and that variable is registered
 *    here with its bounds. A new pause fails the build until somebody names the
 *    constant it is fed from.
 *  - the playbook's own `default(N)` fallback equals that constant's default,
 *    because the fallback is what runs when an older artifact lacks the key.
 *  - the configured maximum stays strictly BELOW the idle budget, and the
 *    payload decoder clamps to it in both directions. That is the invariant, not
 *    any particular number: no configured playbook pause may reach the idle
 *    budget of the layer above it.
 */
final class AnsiblePauseBudgetContractTest extends TestCase
{
    /**
     * Playbook variable => the constants that own its value, plus the payload
     * key it travels in. Registering a variable here is the decision that its
     * pause is bounded; the assertions below then hold it to that.
     */
    private const PAUSE_VARIABLES = [
        'PowerCycleWaitSeconds' => [
            'default' => VIRTUSPHERE_POWERCYCLE_WAIT_DEFAULT,
            'min' => VIRTUSPHERE_POWERCYCLE_WAIT_MIN,
            'max' => VIRTUSPHERE_POWERCYCLE_WAIT_MAX,
            'payload' => 'powercycle_wait',
        ],
        'StartWaitSeconds' => [
            'default' => VIRTUSPHERE_START_WAIT_SECONDS_DEFAULT,
            'min' => VIRTUSPHERE_START_WAIT_SECONDS_MIN,
            'max' => VIRTUSPHERE_START_WAIT_SECONDS_MAX,
            'payload' => 'start_wait',
        ],
        // Fixed, so its bounds collapse onto the value and there is no payload
        // key to clamp: a hypervisor property is not an operator setting. It is
        // registered all the same, because being inside this budget is what
        // stops it from growing into a literal nothing checks again.
        'CreateSettleSeconds' => [
            'default' => VIRTUSPHERE_CREATE_SETTLE_SECONDS,
            'min' => VIRTUSPHERE_CREATE_SETTLE_SECONDS,
            'max' => VIRTUSPHERE_CREATE_SETTLE_SECONDS,
            'payload' => null,
        ],
    ];

    /** Duration keys the pause module accepts; only one of them is allowed. */
    private const DURATION_KEYS = ['seconds', 'minutes', 'hours'];

    public function testEveryPauseIsMeasuredInSecondsAndReadsARegisteredVariable(): void
    {
        $seen = [];
        foreach ($this->pauseTasks() as $where => $block) {
            $units = [];
            foreach (self::DURATION_KEYS as $key) {
                if (preg_match('/^\s*' . $key . ':\s*(\S.*)$/m', $block, $match) === 1) {
                    $units[$key] = trim($match[1]);
                }
            }

            self::assertSame(
                ['seconds'],
                array_keys($units),
                sprintf(
                    '%s: a pause must be declared in seconds and nothing else. %s',
                    $where,
                    'The emitting constant is in seconds, so any other unit multiplies it silently.'
                )
            );

            $variables = $this->variablesIn($units['seconds']);
            self::assertCount(
                1,
                $variables,
                sprintf('%s: the pause duration must be exactly one registered variable, found: %s', $where, implode(', ', $variables) ?: 'none')
            );

            $variable = $variables[0];
            self::assertArrayHasKey(
                $variable,
                self::PAUSE_VARIABLES,
                sprintf(
                    '%s pauses on "%s", which is not registered in PAUSE_VARIABLES. Name the constant that feeds it, or the pause is unbounded.',
                    $where,
                    $variable
                )
            );
            $seen[$variable] = true;

            // The fallback is what runs when the artifact predates the key, so
            // it is a second copy of the default and has to agree with it.
            self::assertSame(
                1,
                preg_match('/\bdefault\(\s*(\d+)\s*\)/', $units['seconds'], $fallback),
                sprintf('%s: the pause must carry a default(N) fallback for an artifact without the key.', $where)
            );
            self::assertSame(
                (int) self::PAUSE_VARIABLES[$variable]['default'],
                (int) $fallback[1],
                sprintf('%s: the default(%s) fallback disagrees with the constant that emits %s.', $where, $fallback[1], $variable)
            );
        }

        // Sorted: the order is the glob's, not a contract.
        $registered = array_keys(self::PAUSE_VARIABLES);
        $found = array_keys($seen);
        sort($registered);
        sort($found);
        self::assertSame(
            $registered,
            $found,
            'PAUSE_VARIABLES names a variable no playbook pauses on (or the scan found none); a registered bound nothing reads is a guard that stopped looking.'
        );
    }

    public function testEveryPauseVariableIsActuallyEmittedByTheGenerator(): void
    {
        $emitted = $this->generatedTopLevelValues();

        foreach (self::PAUSE_VARIABLES as $variable => $bounds) {
            self::assertArrayHasKey(
                $variable,
                $emitted,
                sprintf('%s is read by a playbook but ansible_serverlist_yml() does not emit it; the pause would fall back forever.', $variable)
            );
            self::assertSame(
                (string) $bounds['default'],
                $emitted[$variable],
                sprintf('%s is emitted as %s while its constant says %s.', $variable, $emitted[$variable], (string) $bounds['default'])
            );
        }
    }

    public function testNoConfiguredPauseCanReachTheIdleBudgetAboveIt(): void
    {
        foreach (self::PAUSE_VARIABLES as $variable => $bounds) {
            self::assertLessThan(
                VIRTUSPHERE_SSH_IDLE_TIMEOUT_SECONDS,
                (int) $bounds['max'],
                sprintf(
                    '%s may be configured up to %d s while the SSH layer aborts after %d s of silence. '
                    . 'A pause at the maximum would fail the job in the step it is waiting for.',
                    $variable,
                    (int) $bounds['max'],
                    VIRTUSPHERE_SSH_IDLE_TIMEOUT_SECONDS
                )
            );
            self::assertGreaterThanOrEqual(1, (int) $bounds['min'], $variable . ': the pause module raises anything below 1 s to 1 s.');
            self::assertGreaterThanOrEqual((int) $bounds['min'], (int) $bounds['default'], $variable . ': the default is below its own minimum.');
            self::assertLessThanOrEqual((int) $bounds['max'], (int) $bounds['default'], $variable . ': the default is above its own maximum.');

            // The SSH idle budget is not the closest layer above a pause. The
            // stale-heartbeat reaper is, at VIRTUSPHERE_DEPLOY_STALE_AFTER_SECONDS,
            // and today every registered maximum is allowed to exceed it: the
            // pause is only survivable because the transport's silence tick keeps
            // the job heartbeat fresh through a step that prints nothing. Naming
            // that dependency here is the point - a pause beyond the reap window
            // rides on ONE mechanism, and when it fails the job is marked failed
            // while the playbook keeps running (see SshStreamHardeningTest).
            if ((int) $bounds['max'] >= VIRTUSPHERE_DEPLOY_STALE_AFTER_SECONDS) {
                self::assertLessThan(
                    VIRTUSPHERE_DEPLOY_STALE_AFTER_SECONDS,
                    VIRTUSPHERE_SSH_SILENCE_TICK_SECONDS,
                    sprintf(
                        '%s may be configured up to %d s, past the %d s reap window. That is only survivable while the '
                        . 'silence tick refreshes the heartbeat; with the tick at %d s it does not.',
                        $variable,
                        (int) $bounds['max'],
                        VIRTUSPHERE_DEPLOY_STALE_AFTER_SECONDS,
                        VIRTUSPHERE_SSH_SILENCE_TICK_SECONDS
                    )
                );
            }
        }
    }

    public function testThePayloadDecoderClampsEveryPauseToThoseBounds(): void
    {
        $clamped = 0;
        foreach (self::PAUSE_VARIABLES as $variable => $bounds) {
            if ($bounds['payload'] === null) {
                continue;
            }
            $clamped++;
            $key = (string) $bounds['payload'];

            foreach ([[-1, (int) $bounds['min']], [0, (int) $bounds['min']], [999999, (int) $bounds['max']]] as [$input, $expected]) {
                // The worker's read of a stored payload...
                $decoded = ansible_job_payload(['payload_json' => json_encode([$key => $input], JSON_THROW_ON_ERROR)]);
                self::assertSame(
                    $expected,
                    $decoded[$key],
                    sprintf('ansible_job_payload() passes %s = %d through for %s; the playbook would pause that long.', $key, $input, $variable)
                );

                // ...and the enqueue that wrote it. Both clamp, because a retry
                // re-runs the stored payload and only one of the two sees the form.
                $stored = deploy_job_payload(['mode' => VIRTUSPHERE_DEPLOY_MODE_FULL, $key => $input]);
                self::assertSame(
                    $expected,
                    $stored[$key],
                    sprintf('deploy_job_payload() stores %s = %d unclamped for %s.', $key, $input, $variable)
                );
            }
        }

        self::assertGreaterThanOrEqual(2, $clamped, 'No configurable pause was clamp-tested; the payload keys were renamed away.');
    }

    /**
     * Every pause task in the Ansible tree, keyed by "file:line" so a failure
     * names the place instead of the pattern.
     *
     * @return array<string, string> location => the task's argument block
     */
    private function pauseTasks(): array
    {
        $paths = glob(ansible_source_dir() . DIRECTORY_SEPARATOR . '*.yml');
        self::assertIsArray($paths);
        self::assertNotEmpty($paths, 'No YAML found in the Ansible source dir (zero-match must not pass).');

        $tasks = [];
        foreach ($paths as $path) {
            $source = (string) file_get_contents($path);
            if (preg_match_all('/^([ \t]*)(?:ansible\.builtin\.)?pause:[ \t]*\R((?:^\1[ \t]+\S[^\r\n]*\R?)+)/m', $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }
            foreach ($matches as $match) {
                $line = substr_count(substr($source, 0, (int) $match[0][1]), "\n") + 1;
                $tasks[basename($path) . ':' . $line] = $match[2][0];
            }
        }

        self::assertNotEmpty($tasks, 'No pause task found at all; the scan pattern stopped matching and this contract went silently green.');

        return $tasks;
    }

    /**
     * Data names inside one Jinja expression: identifiers that are not filters
     * or literals. `{{ StartWaitSeconds | default(300) | int }}` yields exactly
     * ['StartWaitSeconds'].
     *
     * @return list<string>
     */
    private function variablesIn(string $expression): array
    {
        $filters = ['default', 'int', 'float', 'abs', 'round', 'trim'];
        preg_match_all('/(?<![\w.])([A-Za-z_]\w*)/', $expression, $tokens);

        return array_values(array_unique(array_filter(
            $tokens[1],
            static fn (string $token): bool => !in_array($token, $filters, true)
        )));
    }

    /**
     * The scalar top-level keys ansible_serverlist_yml() emits, rendered through
     * the real generator with every wait at its default.
     *
     * @return array<string, string>
     */
    private function generatedTopLevelValues(): array
    {
        $yaml = ansible_serverlist_yml(
            ['id' => 1, 'mission_name' => 'PauseBudget', 'hypervisor_datacenter' => 'DC1', 'hypervisor_datastorage' => 'ds1', 'wds_vlan' => 'VLAN10'],
            [['vm_name' => 'vm01', 'disks' => [], 'interfaces' => [], 'packages' => []]]
        );

        $values = [];
        foreach (preg_split('/\R/', $yaml) ?: [] as $line) {
            if (preg_match('/^([A-Za-z_]\w*):\s*(\S+)\s*$/', $line, $match) === 1) {
                $values[$match[1]] = $match[2];
            }
        }

        return $values;
    }
}
