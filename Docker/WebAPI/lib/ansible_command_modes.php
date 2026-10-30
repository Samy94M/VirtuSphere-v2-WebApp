<?php

declare(strict_types=1);

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/deploy_stored_mode.php';
require_once __DIR__ . '/credentials.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/ansible_command_shell.php';
// K21: the remote-directory assertion of the file-only removal below.
require_once __DIR__ . '/ansible_command_create.php';
require_once __DIR__ . '/ansible_command_steps.php';

/**
 * Deploy-mode planning and the remote command the worker runs for a mission:
 * which playbooks a mode expands to, the job payload decode, VM filtering and
 * the step markers that bracket every playbook of a sequence.
 *
 * Split out of ansible_command.php by domain (Etappe 8 structural hunk); the
 * preflight/probe domain lives in ansible_command_preflight.php and changes
 * for other reasons. No behaviour change in the split itself.
 */

/**
 * The mission mode a stored payload names, or null when it names none. A
 * missing, non-string or unknown value is null and never a default: there is no
 * fallback sequence (AN-04). System modes are not mission modes and are refused
 * here too.
 */
function ansible_payload_mode(array $payload): ?string
{
    $mode = deploy_job_stored_mode($payload);
    if ($mode === null) {
        return null;
    }

    return in_array($mode, virtusphere_user_deploy_modes(), true) ? $mode : null;
}

/**
 * The stored mission mode that ansible_remote_steps() refused: the job ends with
 * this reason and no playbook of an unrelated mode is derived.
 */
final class AnsibleDeployModeInvalid extends RuntimeException
{
}

/**
 * Decode a deploy job payload with safe defaults for the fields this module needs.
 * The mode is '' when the stored payload names no mission mode (see
 * ansible_payload_mode()); callers must not derive a sequence from it.
 *
 * @return array{mode: string, vm_ids: int[], powercycle_wait: int, start_wait: int}
 */
function ansible_job_payload(array $job): array
{
    $payload = json_decode((string) ($job['payload_json'] ?? '{}'), true);
    $payload = is_array($payload) ? $payload : [];

    $vmIds = [];
    foreach ((array) ($payload['vm_ids'] ?? []) as $vmId) {
        $id = (int) $vmId;
        if ($id > 0) {
            $vmIds[$id] = true;
        }
    }

    // Clamped at BOTH ends, both waits: an out-of-range value from an older
    // payload (or a crafted one) must reach the playbook as something the layer
    // above it can still survive, and a pause longer than the SSH idle budget is
    // exactly how a deploy dies with its VMs off.
    $wait = (int) ($payload['powercycle_wait'] ?? VIRTUSPHERE_POWERCYCLE_WAIT_DEFAULT);
    $wait = max(VIRTUSPHERE_POWERCYCLE_WAIT_MIN, min(VIRTUSPHERE_POWERCYCLE_WAIT_MAX, $wait));

    $startWait = (int) ($payload['start_wait'] ?? VIRTUSPHERE_START_WAIT_SECONDS_DEFAULT);
    $startWait = max(VIRTUSPHERE_START_WAIT_SECONDS_MIN, min(VIRTUSPHERE_START_WAIT_SECONDS_MAX, $startWait));

    // The mode decides which gates apply (a location-less mode needs no
    // datastore) and which playbooks run. A missing or unknown mode is NOT
    // repaired with the full pipeline: that would run create, power-cycle and
    // start for a job whose mode nobody can read (AN-04). The decoder reports ''
    // instead, and the consumers that would derive a sequence refuse it.
    $mode = ansible_payload_mode($payload) ?? '';

    return [
        'mode' => $mode,
        'vm_ids' => array_map('intval', array_keys($vmIds)),
        'powercycle_wait' => $wait,
        'start_wait' => $startWait,
    ];
}

/**
 * @param array<int, array> $vms
 * @param int[] $vmIds Empty means "all VMs" (whole mission).
 * @return array<int, array>
 */
function ansible_filter_vms(array $vms, array $vmIds): array
{
    if ($vmIds === []) {
        return $vms;
    }

    $wanted = array_flip($vmIds);
    return array_values(array_filter($vms, static fn (array $vm): bool => isset($wanted[(int) ($vm['id'] ?? 0)])));
}

/**
 * Playbook sequence for a deploy mode.
 *
 * $autostartEnabled gates the autostart step of the FULL pipeline only. A full
 * deploy of a mission that never asked for autostart must not touch the host's
 * autostart manager: the host may carry other missions whose policy would then
 * be rewritten with this mission's defaults. The explicit `autostart` mode always
 * runs, including for a disabled mission, because that is how a policy is
 * withdrawn (every VM of the mission is written with start_action "none").
 *
 * $thenAutostart is the payload flag of a start job that resumes a full
 * pipeline (K4b, deploy_job_resume_plan()): it appends the autostart step
 * under the same mission gate as the full pipeline. Every other mode ignores it.
 *
 * @return string[]
 */
function ansible_playbooks_for_mode(string $mode, bool $autostartEnabled = false, bool $thenAutostart = false): array
{
    return match ($mode) {
        'create' => [VIRTUSPHERE_PLAYBOOKS['create']],
        'powercycle' => [VIRTUSPHERE_PLAYBOOKS['powercycle'], VIRTUSPHERE_PLAYBOOKS['export']],
        'export' => [VIRTUSPHERE_PLAYBOOKS['export']],
        'start' => array_merge(
            [VIRTUSPHERE_PLAYBOOKS['start']],
            $thenAutostart && $autostartEnabled ? [VIRTUSPHERE_PLAYBOOKS['autostart']] : []
        ),
        VIRTUSPHERE_DEPLOY_MODE_AUTOSTART => [VIRTUSPHERE_PLAYBOOKS['autostart']],
        default => array_merge(
            [
                VIRTUSPHERE_PLAYBOOKS['create'],
                VIRTUSPHERE_PLAYBOOKS['powercycle'],
                VIRTUSPHERE_PLAYBOOKS['export'],
                VIRTUSPHERE_PLAYBOOKS['start'],
            ],
            $autostartEnabled ? [VIRTUSPHERE_PLAYBOOKS['autostart']] : []
        ),
    };
}

/**
 * The user-selectable modes whose playbook sequence actually runs the power-cycle
 * playbook, i.e. the only modes that read PowerCycleWaitSeconds. Derived from
 * ansible_playbooks_for_mode() rather than listed, so the deploy form's wait-time
 * lock cannot drift from the real sequence. Today: 'full' and 'powercycle'.
 *
 * @return string[]
 */
function ansible_modes_using_powercycle(): array
{
    return array_values(array_filter(
        virtusphere_user_deploy_modes(),
        static fn (string $mode): bool => in_array(VIRTUSPHERE_PLAYBOOKS['powercycle'], ansible_playbooks_for_mode($mode), true)
    ));
}

/**
 * The modes whose sequence runs the start playbook, i.e. the only modes that
 * read StartWaitSeconds. Derived the same way as the power-cycle list, so the
 * form's lock follows the sequence instead of a second hand-kept copy of it.
 * Today: 'full' and 'start'.
 *
 * @return string[]
 */
function ansible_modes_using_start(): array
{
    return array_values(array_filter(
        virtusphere_user_deploy_modes(),
        static fn (string $mode): bool => in_array(VIRTUSPHERE_PLAYBOOKS['start'], ansible_playbooks_for_mode($mode), true)
    ));
}

/**
 * Whether a mode's sequence runs the MAC export playbook, i.e. whether the
 * worker must find a mac_import result in deploy_jobs.result_json afterwards.
 * Derived from ansible_playbooks_for_mode() rather than listed, so a sequence
 * change cannot drift from the worker's expectation: a mode without the export
 * step must never be failed for a missing result (L3), and a mode with it must
 * never finish green without one. Today: 'full', 'powercycle' and 'export'.
 * The autostart flag only gates the autostart step, never the export step, so
 * it cannot change the answer.
 */
function ansible_mode_expects_mac_result(string $mode): bool
{
    return in_array(VIRTUSPHERE_PLAYBOOKS['export'], ansible_playbooks_for_mode($mode), true);
}

/**
 * Whether a mode's sequence runs the create playbook, i.e. whether the queue
 * has to materialize one durable create result row per selected VM (Etappe
 * 14B). Derived the same way as the three helpers above, so a mode that gains
 * or loses the create step cannot leave the queue behind: a create-capable job
 * without rows would be indistinguishable from a legacy job, and a job with
 * rows for a mode that never creates anything would report progress nobody
 * makes. Today: 'full' and 'create'. The autostart flag only appends a step and
 * cannot change the answer.
 */
function ansible_mode_creates_vms(string $mode): bool
{
    return in_array(VIRTUSPHERE_PLAYBOOKS['create'], ansible_playbooks_for_mode($mode), true);
}

/**
 * Modes that address existing VMs by their bound ESXi instance UUID need a
 * durable portal binding before they enter the job's run scope. A full/create
 * sequence is exempt because its Create owner establishes that binding before
 * the later UUID-addressed steps run.
 */
function ansible_mode_requires_bound_instance_uuid(string $mode): bool
{
    if (ansible_mode_creates_vms($mode)) {
        return false;
    }

    foreach (['powercycle', 'export', 'start', 'autostart'] as $step) {
        if (in_array(VIRTUSPHERE_PLAYBOOKS[$step], ansible_playbooks_for_mode($mode), true)) {
            return true;
        }
    }

    return false;
}

/**
 * The ordered remote steps of a deploy sequence, one per playbook.
 *
 * Until Etappe 8 this was a single remote `a && b && c` chain, and a chain has
 * no boundary the portal can stand on: a cancel accepted while it ran could
 * only be honoured after the LAST playbook, so ADR-0033 and the portal promised
 * a stop that the worker had no way to perform. Now each playbook is its own
 * remote command, and the worker decides between them whether a next one starts
 * at all.
 *
 * Every step repeats the preamble, because every step is its own shell: the
 * working directory, the 0600 on the secret inputs and the correlation id are
 * not state that survives a finished command.
 *
 * Cleanup is deliberately NOT an EXIT trap here. With one chain, EXIT ran once
 * at the end; per step it would delete the job directory after the first
 * playbook. The signal traps keep the property that actually mattered - a
 * connection that drops, or a killed remote shell, does not leave accounts.yml
 * behind - and the normal path removes the directory through
 * ansible_remote_cleanup_command() once the sequence is over.
 *
 * @param array<string, mixed> $payload
 * @return array<int, array{playbook: string, command: string}> In execution order.
 * @throws AnsibleDeployModeInvalid when the payload names no mission mode (AN-04).
 */
function ansible_remote_steps(string $remoteDir, array $payload, bool $autostartEnabled = false): array
{
    // AN-04: a payload without a readable mission mode derives no sequence at
    // all. The former fallback ran the full pipeline for it.
    $mode = ansible_payload_mode($payload);
    if ($mode === null) {
        throw new AnsibleDeployModeInvalid('The deploy mode of this job is missing or unknown; no playbook sequence was derived.');
    }
    $verbose = !empty($payload['verbose']);
    $preamble = [
        // The traps fire only when the shell is TERMINATED, never on a normal
        // exit: sshd sends HUP to the session when the channel closes, which is
        // the case this protects against.
        'trap ' . ansible_sh_quote('rm -rf -- ' . ansible_sh_quote($remoteDir)) . ' HUP INT TERM',
        'cd ' . ansible_sh_quote($remoteDir),
        'chmod 600 accounts.yml',
        'if [ -f ' . VIRTUSPHERE_ESXI_TRUST_FILE . ' ]; then chmod 600 ' . VIRTUSPHERE_ESXI_TRUST_FILE . '; fi',
        // ADR-0032: opaque diagnostic id for the whole remote sequence; remote
        // tooling passes it through, nothing parses it.
        'export VS_CORRELATION_ID=' . ansible_sh_quote(virtusphere_correlation_id()),
        // Etappe 14B. Python block-buffers stdout as soon as it is not a
        // terminal, and the worker reads through an SSH pipe: without this, the
        // result lines of a long loop sit in the buffer until the process ends.
        // That is what the 13.08.2026 incident was - "no output for 1800
        // seconds" over a create task that was still running, and fourteen of
        // fifteen VMs appeared on ESXi afterwards. It is set here, once, for
        // every playbook of every mode, because a progress marker in a playbook
        // repairs nothing while its line is still in a buffer.
        // The gate `ansible-output-buffering` measures both cases in the pinned
        // QA image, so this line cannot quietly stop working.
        'export PYTHONUNBUFFERED=1',
    ];

    $steps = [];
    foreach (ansible_playbooks_for_mode($mode, $autostartEnabled, !empty($payload['then_autostart'])) as $playbook) {
        if ($playbook === VIRTUSPHERE_PLAYBOOKS['create']) {
            // Etappe 14B, Teiletappe E: the create playbook is not a step of
            // this sequence any more. It mutates exactly one VM per call, and
            // the worker drives one call per VM so that every one of them has a
            // durable result before the next one starts. Handing the whole
            // selection to one remote command is what left the 13.08.2026
            // incident with fourteen VMs on ESXi and no record of which.
            continue;
        }
        $commands = $preamble;
        $commands[] = 'echo ' . ansible_sh_quote(ansible_step_marker_line(VIRTUSPHERE_ANSIBLE_STEP_BEGIN, $playbook));
        $commands[] = 'ansible-playbook ' . ansible_sh_quote($playbook) . ($verbose ? ' -vvv' : '') . ' 2>&1';
        // Reached only when the playbook succeeded, which is what makes "the
        // last begin without its end" name the failed step.
        $commands[] = 'echo ' . ansible_sh_quote(ansible_step_marker_line(VIRTUSPHERE_ANSIBLE_STEP_END, $playbook));

        $steps[] = ['playbook' => $playbook, 'command' => implode(' && ', $commands)];
    }

    return $steps;
}

/**
 * Removes the job's remote work directory once the sequence is over, whatever
 * its outcome. Best-effort by contract: it runs over the same SSH transport
 * that may already be gone, and a job material left behind by an unreachable
 * host is a state this stage does not resolve, only report.
 */
function ansible_remote_cleanup_command(string $remoteDir): string
{
    return 'rm -rf -- ' . ansible_sh_quote($remoteDir);
}

/** K21: the state line of a confirmed removal of accounts.yml; lib/deploy_remote_secret.php reads it. */
const VIRTUSPHERE_REMOTE_SECRET_STATE_PREFIX = 'VIRTUSPHERE_REMOTE_SECRET';
const VIRTUSPHERE_REMOTE_SECRET_REMOVED = 'removed';
const VIRTUSPHERE_REMOTE_SECRET_ABSENT = 'absent';

/**
 * K21 (VT-04): the one command that removes the ESXi access file accounts.yml.
 * The file form removes the file and nothing else, because an uncertain create
 * unit keeps its async state in the directory; $wholeDir removes the job
 * directory of a provably idle host. Only after a successful removal it prints
 * one state line: "removed" when the file was there, "absent" when it was already
 * gone. `rm -f` answers 0 for a path that does not exist, so exit 0 alone cannot
 * tell a removed file from a host that never had it. Refuses a remote directory
 * that is not absolute or that traverses before it is quoted.
 */
function ansible_remote_secret_remove_command(string $remoteDir, bool $wholeDir = false): string
{
    ansible_create_assert_remote_dir($remoteDir);
    $dir = rtrim($remoteDir, '/');
    $file = ansible_sh_quote($dir . '/accounts.yml');

    return 'if [ -e ' . $file . ' ] || [ -L ' . $file . ' ]; then s=' . VIRTUSPHERE_REMOTE_SECRET_REMOVED
        . '; else s=' . VIRTUSPHERE_REMOTE_SECRET_ABSENT . '; fi; '
        . ($wholeDir ? 'rm -rf -- ' . ansible_sh_quote($dir) : 'rm -f -- ' . $file)
        . ' && echo "' . VIRTUSPHERE_REMOTE_SECRET_STATE_PREFIX . ' $s"';
}

/** Writing follows the real playbook sequence; system inventory and export read. */
function ansible_mode_writes_esxi(string $mode): bool
{
    if (array_key_exists($mode, VIRTUSPHERE_SYSTEM_PLAYBOOKS)) {
        return false;
    }
    return array_diff(ansible_playbooks_for_mode($mode, true), [VIRTUSPHERE_PLAYBOOKS['export']]) !== [];
}

// Stage marker echoed before each preflight component; the last one on stdout
