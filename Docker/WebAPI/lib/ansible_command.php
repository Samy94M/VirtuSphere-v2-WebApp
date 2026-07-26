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

// Stage marker echoed before each preflight component; the last one on stdout
// names the component the shell reached before the && chain broke.
const VIRTUSPHERE_ANSIBLE_PREFLIGHT_MARKER = '::virtusphere-preflight::';

// Component token for the optional portal-reachability probe (the MAC return
// route). Appended by ansible_preflight_command() only when an API base URL is
// configured; kept as a constant so ansible_preflight_failed_component() names
// it even though it is not one of the static tool checks.
const VIRTUSPHERE_ANSIBLE_PREFLIGHT_PORTAL = 'portal';

// Component token for the machine-API allowlist probe that follows the portal
// probe: reaching the portal is not the same as passing the IP gate that
// db_importMAC.php enforces on the MAC upload.
const VIRTUSPHERE_ANSIBLE_PREFLIGHT_ALLOWLIST = 'allowlist';

// Verdict line the allowlist probe prints ("<marker> ok|denied <ip>|unknown").
// Distinct from the stage marker: the stage marker says the probe ran, this
// line says what it found. The probe always exits 0, because a missing
// allowlist entry is a warning about a FUTURE deploy, not a broken credential.
const VIRTUSPHERE_ANSIBLE_ALLOWLIST_MARKER = '::virtusphere-allowlist::';

/**
 * The Ansible-host readiness checks, keyed by the component token shown to the
 * operator, in the order a deploy needs the pieces. Each value is the shell test
 * for that component (possibly several commands joined by &&).
 *
 * Redirection rule: the `command -v` presence probes are silenced, but the real
 * checks keep stderr on the captured stream (2>&1) so a failure's actual reason
 * (a Python ModuleNotFoundError, a missing collection) survives into the detail
 * behind the alert, not just the marker.
 *
 * $strict swaps the collection probe from vmware_guest to vmware_host_auto_start,
 * the module that pins the required collection floor (requirements.yml 6.2.0,
 * ADR-0025). The on-demand credential test uses strict mode to catch a too-old
 * collection that carries vmware_guest but not the autostart module. The deploy
 * worker uses the lenient probe on purpose: an old collection can still run a
 * create-only deploy, so its hard preflight gate must not fail for a module that
 * job may never use (the autostart step has its own preflight, ADR-0025).
 *
 * @return array<string, string>
 */
function ansible_preflight_checks(bool $strict = false): array
{
    $collectionModule = $strict ? 'community.vmware.vmware_host_auto_start' : 'community.vmware.vmware_guest';

    return [
        'ansible-playbook' => 'command -v ansible-playbook >/dev/null 2>&1 && ansible-playbook --version 2>&1',
        'python3' => 'command -v python3 >/dev/null 2>&1 && python3 --version 2>&1',
        'pyvmomi' => 'python3 -c ' . ansible_sh_quote('import pyVim, pyVmomi') . ' 2>&1',
        'community.vmware' => 'ansible-doc -t module ' . $collectionModule . ' 2>&1',
    ];
}

/**
 * A single && chain that echoes a stage marker BEFORE attempting each component.
 * Because the chain stops at the first failing component, the LAST marker on
 * stdout names exactly the component that failed. The worker/test caller reads
 * it with ansible_preflight_failed_component() instead of guessing from an exit
 * code that only ever says "1".
 *
 * When $apiBaseUrl is set, a final 'portal' step probes the MAC return route
 * from the host via python3 (already required above): the deploy's
 * upload_mac_list.py posts back to this URL, and a host that cannot reach it
 * leaves VMs stuck at stage 2/5. The URL travels in an env var, not interpolated
 * into the python source, so a crafted setting cannot break out of the quoting.
 *
 * An 'allowlist' step then asks the frozen wire contract itself whether the
 * host would pass the IP gate of that upload: a GET to db_importMAC.php from a
 * non-allowlisted IP answers with the legacy 403 that echoes the caller's IP
 * ("Zugriff verweigert. Ihre IP: ..."), while an allowlisted IP reaches the
 * method check (405). Nothing is written either way and the wire behaviour is
 * exactly the E3-frozen one, so the probe changes no contract. It prints a
 * verdict line and always exits 0: health.php passing while this says denied
 * is a warning (deploys would lose their MAC upload), not a broken credential.
 */
function ansible_preflight_command(string $apiBaseUrl = '', bool $strict = false): string
{
    $checks = ansible_preflight_checks($strict);

    $apiBaseUrl = trim($apiBaseUrl);
    if ($apiBaseUrl !== '') {
        // An HTTP status of any kind proves the route: only a transport error
        // means the host cannot reach the portal. urlopen() raises HTTPError on
        // 4xx/5xx, so the bare call failed this component for a portal that was
        // answering perfectly well but reporting itself degraded, and the deploy
        // died in its preflight over a health nuance. Same rule as
        // Test-VsApiAnswered on the client side and as health.php's own 200 for
        // `degraded`: three layers, one predicate.
        $healthUrl = rtrim($apiBaseUrl, '/') . '/portal/health.php';
        $checks[VIRTUSPHERE_ANSIBLE_PREFLIGHT_PORTAL] = 'VS_PF_URL=' . ansible_sh_quote($healthUrl)
            . ' python3 -c ' . ansible_sh_quote(
                'import os, urllib.request, urllib.error' . "\n"
                . 'try:' . "\n"
                . '    urllib.request.urlopen(os.environ["VS_PF_URL"], timeout=5)' . "\n"
                . 'except urllib.error.HTTPError as error:' . "\n"
                . '    print("portal answered HTTP %d" % error.code)' . "\n"
            ) . ' 2>&1';

        $macUploadUrl = rtrim($apiBaseUrl, '/') . '/db_importMAC.php';
        $checks[VIRTUSPHERE_ANSIBLE_PREFLIGHT_ALLOWLIST] = 'VS_PF_MAC_URL=' . ansible_sh_quote($macUploadUrl)
            . ' python3 -c ' . ansible_sh_quote(ansible_allowlist_probe_source()) . ' 2>&1';
    }

    $steps = [];
    foreach ($checks as $component => $check) {
        $steps[] = 'echo ' . ansible_sh_quote(VIRTUSPHERE_ANSIBLE_PREFLIGHT_MARKER . ' ' . $component);
        $steps[] = $check;
    }

    return implode(' && ', $steps);
}

/**
 * Python source of the allowlist probe. Only the 403 counts as denied: any
 * other HTTP answer (the expected 405, but also e.g. a redirect target's
 * status) means the IP gate was passed, and a transport error right after the
 * portal probe succeeded is reported as unknown rather than crying wolf. The
 * "Ihre IP: " needle is part of the frozen legacy 403 (machine_api.php), so the
 * echoed IP can be lifted into the operator's warning.
 */
function ansible_allowlist_probe_source(): string
{
    $source = <<<'PY'
import os, re, urllib.request, urllib.error
try:
    urllib.request.urlopen(os.environ["VS_PF_MAC_URL"], timeout=5)
    print("{marker} ok")
except urllib.error.HTTPError as error:
    if error.code == 403:
        body = ""
        try:
            body = error.read().decode("utf-8", "replace")
        except Exception:
            pass
        hit = re.search("Ihre IP: ([0-9a-fA-F.:]+)", body)
        print("{marker} denied " + (hit.group(1) if hit else ""))
    else:
        print("{marker} ok")
except Exception:
    print("{marker} unknown")
PY;

    return str_replace('{marker}', VIRTUSPHERE_ANSIBLE_ALLOWLIST_MARKER, $source);
}

/**
 * Reads the allowlist probe's verdict line out of the preflight output. The
 * last line wins. 'absent' means the probe never printed one (no API base URL,
 * or output from before the probe existed); the caller then simply has no
 * verdict to act on. A denied verdict carries the IP the portal echoed back,
 * validated here because it crossed a remote shell before it reaches an audit
 * line and a flash message.
 *
 * @return array{status: 'ok'|'denied'|'unknown'|'absent', ip: string}
 */
function ansible_preflight_allowlist_verdict(string $output): array
{
    $status = 'absent';
    $ip = '';
    foreach (explode("\n", $output) as $line) {
        $line = trim($line);
        if (!str_starts_with($line, VIRTUSPHERE_ANSIBLE_ALLOWLIST_MARKER . ' ')) {
            continue;
        }
        $verdict = trim(substr($line, strlen(VIRTUSPHERE_ANSIBLE_ALLOWLIST_MARKER) + 1));
        if ($verdict === 'ok' || $verdict === 'unknown') {
            $status = $verdict;
            $ip = '';
        } elseif (str_starts_with($verdict, 'denied')) {
            $status = 'denied';
            $candidate = trim(substr($verdict, strlen('denied')));
            $ip = filter_var($candidate, FILTER_VALIDATE_IP) !== false ? $candidate : '';
        }
    }

    return ['status' => $status, 'ip' => $ip];
}

/**
 * The component whose check failed, read from the preflight output. The last
 * marker printed is the component the shell reached before the chain broke, so
 * that component is the culprit. Null when no marker was printed at all (the
 * SSH exec never ran the script) - the caller then keeps its generic message.
 */
function ansible_preflight_failed_component(string $output): ?string
{
    // The static tool checks plus the optional portal/allowlist tokens, so a
    // probe failure is named too even though this reader has no API base URL.
    $components = array_merge(
        array_keys(ansible_preflight_checks()),
        [VIRTUSPHERE_ANSIBLE_PREFLIGHT_PORTAL, VIRTUSPHERE_ANSIBLE_PREFLIGHT_ALLOWLIST]
    );
    $found = null;
    foreach (explode("\n", $output) as $line) {
        $line = trim($line);
        if (!str_starts_with($line, VIRTUSPHERE_ANSIBLE_PREFLIGHT_MARKER . ' ')) {
            continue;
        }
        $candidate = trim(substr($line, strlen(VIRTUSPHERE_ANSIBLE_PREFLIGHT_MARKER) + 1));
        if (in_array($candidate, $components, true)) {
            $found = $candidate;
        }
    }

    return $found;
}

/**
 * The preflight output with the internal stage markers removed, so the detail
 * shown behind the alert is the remote's own error text and nothing else.
 */
function ansible_preflight_strip_markers(string $output): string
{
    $lines = array_filter(
        explode("\n", $output),
        static fn (string $line): bool => !str_starts_with(trim($line), VIRTUSPHERE_ANSIBLE_PREFLIGHT_MARKER . ' ')
            && !str_starts_with(trim($line), VIRTUSPHERE_ANSIBLE_ALLOWLIST_MARKER . ' ')
    );

    return trim(implode("\n", $lines));
}

function ansible_sh_quote(string $value): string
{
    return "'" . str_replace("'", "'\\''", $value) . "'";
}
