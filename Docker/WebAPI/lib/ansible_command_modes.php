<?php

declare(strict_types=1);

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/credentials.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/ansible_command_shell.php';

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
 * Decode a deploy job payload with safe defaults for the fields this module needs.
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
    // datastore) and which playbooks run. An unreadable payload falls back to
    // the full pipeline, the strictest of them.
    $mode = strtolower(trim((string) ($payload['mode'] ?? VIRTUSPHERE_DEPLOY_MODE_FULL)));
    if (!in_array($mode, virtusphere_deploy_modes(), true)) {
        $mode = VIRTUSPHERE_DEPLOY_MODE_FULL;
    }

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
 * @return string[]
 */
function ansible_playbooks_for_mode(string $mode, bool $autostartEnabled = false): array
{
    return match ($mode) {
        'create' => [VIRTUSPHERE_PLAYBOOKS['create']],
        'powercycle' => [VIRTUSPHERE_PLAYBOOKS['powercycle'], VIRTUSPHERE_PLAYBOOKS['export']],
        'export' => [VIRTUSPHERE_PLAYBOOKS['export']],
        'start' => [VIRTUSPHERE_PLAYBOOKS['start']],
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

// Step markers (AP6): every per-playbook remote command is bracketed by a begin
// and an end line on stdout. The worker controls and names the step from its
// descriptor; the persisted markers are the technical display evidence. The
// short chain inside one command still omits the end marker when its playbook
// fails, so the later presenter can identify an incomplete phase honestly.
const VIRTUSPHERE_ANSIBLE_STEP_MARKER_PREFIX = '::virtusphere-step::';

const VIRTUSPHERE_ANSIBLE_STEP_BEGIN = 'begin';
const VIRTUSPHERE_ANSIBLE_STEP_END = 'end';

function ansible_step_marker_line(string $event, string $playbook): string
{
    return VIRTUSPHERE_ANSIBLE_STEP_MARKER_PREFIX . ' ' . $event . ' ' . $playbook;
}

/**
 * Reads one step marker back out of the stored output.
 *
 * Deliberately kept although the worker no longer calls it: since Etappe 8 the
 * worker knows which step it is in from the descriptor it just started, which
 * is strictly better evidence than a marker that may not have arrived yet. The
 * markers stay the technical SSoT for the DISPLAY - phase headings and "current
 * phase" are derived from them rather than from a second hand-kept playbook
 * order. That reader belongs to Etappe 13, together with the 10A cursor/drain
 * contract and the 10B terminal presenter. Removing this as dead code would
 * force that stage to write the parser again, differently.
 *
 * @return array{event: string, playbook: string}|null Null for any line that
 *         is not a well-formed step marker.
 */
function ansible_step_marker_parse(string $line): ?array
{
    $line = trim($line);
    if (!str_starts_with($line, VIRTUSPHERE_ANSIBLE_STEP_MARKER_PREFIX . ' ')) {
        return null;
    }

    $parts = explode(' ', substr($line, strlen(VIRTUSPHERE_ANSIBLE_STEP_MARKER_PREFIX) + 1), 2);
    $event = $parts[0];
    $playbook = trim($parts[1] ?? '');
    if (!in_array($event, [VIRTUSPHERE_ANSIBLE_STEP_BEGIN, VIRTUSPHERE_ANSIBLE_STEP_END], true) || $playbook === '') {
        return null;
    }

    return ['event' => $event, 'playbook' => $playbook];
}

/**
 * Suffix naming the failed playbook step for a job error message. Empty when
 * no step was open (the failure happened outside the playbook sequence, e.g.
 * during cd/chmod or before the first marker arrived).
 */
function ansible_step_failure_suffix(?string $playbook): string
{
    return $playbook === null || $playbook === '' ? '' : ' (playbook step: ' . $playbook . ')';
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
 */
function ansible_remote_steps(string $remoteDir, array $payload, bool $autostartEnabled = false): array
{
    $mode = (string) ($payload['mode'] ?? VIRTUSPHERE_DEPLOY_MODE_FULL);
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
    ];

    $steps = [];
    foreach (ansible_playbooks_for_mode($mode, $autostartEnabled) as $playbook) {
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

// Stage marker echoed before each preflight component; the last one on stdout
