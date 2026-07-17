<?php

declare(strict_types=1);

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/deploy_constants.php';

/**
 * Deploy-mode planning and the remote shell commands the worker runs: which
 * playbooks a mode expands to, the job payload decode, VM filtering, and the
 * remote/preflight command strings. Split out of ansible.php by domain
 * (ADR-0006 file-size discipline); no behaviour change.
 */

/**
 * Decode a deploy job payload with safe defaults for the fields this module needs.
 *
 * @return array{vm_ids: int[], powercycle_wait: int}
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

    $wait = (int) ($payload['powercycle_wait'] ?? VIRTUSPHERE_POWERCYCLE_WAIT_DEFAULT);
    $wait = max(VIRTUSPHERE_POWERCYCLE_WAIT_MIN, min(VIRTUSPHERE_POWERCYCLE_WAIT_MAX, $wait));

    // The mode decides which gates apply (a location-less mode needs no
    // datastore) and which playbooks run. An unreadable payload falls back to
    // the full pipeline, the strictest of them.
    $mode = strtolower(trim((string) ($payload['mode'] ?? VIRTUSPHERE_DEPLOY_MODE_FULL)));
    if (!in_array($mode, virtusphere_deploy_modes(), true)) {
        $mode = VIRTUSPHERE_DEPLOY_MODE_FULL;
    }

    return ['mode' => $mode, 'vm_ids' => array_map('intval', array_keys($vmIds)), 'powercycle_wait' => $wait];
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

// Step markers (AP6): every playbook of a sequence is bracketed by a begin and
// an end line on stdout. Because the sequence is one && chain, a failure stops
// the chain right after the failing playbook - the last begin without its end
// IS the failed phase, and the worker names it in the job's error message
// instead of reporting only the exit code of a five-playbook pipeline.
const VIRTUSPHERE_ANSIBLE_STEP_MARKER_PREFIX = '::virtusphere-step::';

const VIRTUSPHERE_ANSIBLE_STEP_BEGIN = 'begin';
const VIRTUSPHERE_ANSIBLE_STEP_END = 'end';

function ansible_step_marker_line(string $event, string $playbook): string
{
    return VIRTUSPHERE_ANSIBLE_STEP_MARKER_PREFIX . ' ' . $event . ' ' . $playbook;
}

/**
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

function ansible_remote_command(string $remoteDir, array $payload, bool $autostartEnabled = false): string
{
    $mode = (string) ($payload['mode'] ?? VIRTUSPHERE_DEPLOY_MODE_FULL);
    $verbose = !empty($payload['verbose']);
    $commands = [
        'cd ' . ansible_sh_quote($remoteDir),
        'chmod 600 accounts.yml',
        // ADR-0032: opaque diagnostic id for the whole remote sequence; remote
        // tooling passes it through, nothing parses it.
        'export VS_CORRELATION_ID=' . ansible_sh_quote(virtusphere_correlation_id()),
    ];

    foreach (ansible_playbooks_for_mode($mode, $autostartEnabled) as $playbook) {
        $commands[] = 'echo ' . ansible_sh_quote(ansible_step_marker_line(VIRTUSPHERE_ANSIBLE_STEP_BEGIN, $playbook));
        $commands[] = 'ansible-playbook ' . ansible_sh_quote($playbook) . ($verbose ? ' -vvv' : '') . ' 2>&1';
        $commands[] = 'echo ' . ansible_sh_quote(ansible_step_marker_line(VIRTUSPHERE_ANSIBLE_STEP_END, $playbook));
    }

    $cleanup = 'rm -rf -- ' . ansible_sh_quote($remoteDir);
    return 'trap ' . ansible_sh_quote($cleanup) . ' EXIT; ' . implode(' && ', $commands);
}

function ansible_preflight_command(): string
{
    $checks = [
        'command -v ansible-playbook >/dev/null 2>&1',
        'ansible-playbook --version 2>&1',
        'command -v python3 >/dev/null 2>&1',
        'python3 --version 2>&1',
        'python3 -c ' . ansible_sh_quote('import pyVim, pyVmomi; print("pyvmomi import ok")') . ' 2>&1',
        'ansible-doc -t module community.vmware.vmware_guest >/dev/null 2>&1',
        'echo community.vmware.vmware_guest available',
    ];

    return implode(' && ', $checks);
}

function ansible_sh_quote(string $value): string
{
    return "'" . str_replace("'", "'\\''", $value) . "'";
}
