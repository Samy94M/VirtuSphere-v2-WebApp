<?php

declare(strict_types=1);

require_once __DIR__ . '/ansible_command_create.php';
require_once __DIR__ . '/ansible_create_protocol.php';
require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/deploy_create_result.php';
require_once __DIR__ . '/deploy_worker_runtime.php';
require_once __DIR__ . '/deploy_worker_stream.php';
require_once __DIR__ . '/ssh.php';

/**
 * What the worker ASKS the Ansible host about one create unit, and what it
 * reads back (Etappe 14B, Teiletappe E).
 *
 * Separated from the poll loop because these are two different kinds of
 * decision. The loop decides what a unit's state means; this module decides
 * nothing at all - it runs one control call, hands back the one marker the
 * protocol allows, searches for a job id a lost launch may have left behind,
 * removes an async state file, and waits in heartbeat-sized slices. Keeping the
 * two apart is what keeps a transport failure from being read as an outcome.
 */

/**
 * Runs one control call and returns its single marker.
 *
 * Marker lines are collected while the output streams into the job log rather
 * than from an accumulated copy of it: a control call's output is remote input,
 * and holding all of it to search it afterwards would make output size a memory
 * question. The count check stays the protocol's, so zero and two markers keep
 * meaning what they mean.
 *
 * @param array<string, mixed> $context
 * @param array<string, mixed> $unit
 * @param array<string, mixed> $extraVars
 * @return array{exit_code:?int,marker:?array<string,mixed>,transport_error:?string,protocol_error:?string}
 */
function deploy_worker_create_control_call(
    DeployWorkerDbChannel $channel,
    array $context,
    string $playbook,
    array $unit,
    array $extraVars,
    ?string $expectedJid = null
): array {
    $command = ansible_create_control_command(
        (string) $context['remote_dir'],
        $playbook,
        (int) $unit['position'],
        $extraVars,
        !empty($context['verbose'])
    );
    $secrets = (array) ($context['secrets'] ?? []);
    $markerLines = [];
    $buffer = '';
    $collect = static function (string $line) use (&$markerLines): void {
        if (str_starts_with(trim($line), VIRTUSPHERE_CREATE_MARKER_PREFIX)) {
            $markerLines[] = trim($line);
        }
    };

    try {
        $exitCode = ssh_execute_command(
            (array) $context['credential'],
            (string) $context['secret'],
            $command,
            static function (string $chunk) use ($channel, &$buffer, $collect): void {
                deploy_worker_log_stream_chunk($channel, VIRTUSPHERE_DEPLOY_LOG_ANSIBLE, $buffer, $chunk, $collect);
            },
            VIRTUSPHERE_CREATE_CONTROL_IDLE_TIMEOUT_SECONDS,
            static function () use ($channel): void {
                $channel->tick();
            },
            VIRTUSPHERE_CREATE_CONTROL_TOTAL_TIMEOUT_SECONDS
        );
        deploy_worker_log_stream_flush($channel, VIRTUSPHERE_DEPLOY_LOG_ANSIBLE, $buffer, $collect);
    } catch (RuntimeException $exception) {
        deploy_worker_log_stream_flush($channel, VIRTUSPHERE_DEPLOY_LOG_ANSIBLE, $buffer, $collect);

        return [
            'exit_code' => null,
            'marker' => null,
            'transport_error' => deploy_worker_redact_secrets($exception->getMessage(), $secrets),
            'protocol_error' => null,
        ];
    }

    try {
        $marker = ansible_create_marker_extract(implode("\n", $markerLines));
        if (!ansible_create_marker_matches($marker, (int) $unit['vm_id'], (string) $unit['vm_name'], $expectedJid)) {
            throw new CreateMarkerProtocolException('The marker describes another VM or another async job.');
        }
    } catch (CreateMarkerProtocolException $exception) {
        return [
            'exit_code' => $exitCode,
            'marker' => null,
            'transport_error' => null,
            'protocol_error' => deploy_worker_redact_secrets($exception->getMessage(), $secrets)
                . ' (control call exit code ' . $exitCode . ')',
        ];
    }

    return ['exit_code' => $exitCode, 'marker' => $marker, 'transport_error' => null, 'protocol_error' => null];
}

/**
 * Looks for the async job a lost launch call may have started (plan 9.4.10).
 *
 * The one thing this must never do is start a second `vmware_guest` on
 * suspicion. It asks the async directory of THIS unit, which nothing else
 * writes to, over short fresh channels, and answers with the one job id it
 * found, with nothing, or with an ambiguity.
 *
 * @param array<string, mixed> $context
 * @param array<string, mixed> $unit
 * @return array{jid:?string,error_code:?string,detail:string}
 */
function deploy_worker_create_discover_jid(DeployWorkerDbChannel $channel, array $context, array $unit): array
{
    $asyncDir = ansible_create_async_dir((string) $context['remote_dir'], (int) $unit['position']);
    $deadline = time() + VIRTUSPHERE_CREATE_JID_DISCOVERY_TIMEOUT_SECONDS;
    $command = 'ls -1 -- ' . ansible_sh_quote($asyncDir) . ' 2>/dev/null | head -n 8';

    do {
        try {
            $capture = ssh_execute_capture(
                (array) $context['credential'],
                (string) $context['secret'],
                $command,
                VIRTUSPHERE_CREATE_JID_DISCOVERY_INTERVAL_SECONDS * 2
            );
            $found = [];
            foreach (preg_split('/\R/', (string) $capture['output']) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '' && deploy_create_jid_is_valid($line)) {
                    $found[] = $line;
                }
            }
            if (count($found) === 1) {
                return ['jid' => $found[0], 'error_code' => null, 'detail' => ''];
            }
            if (count($found) > 1) {
                return [
                    'jid' => null,
                    'error_code' => VIRTUSPHERE_CREATE_ERROR_PROTOCOL_ERROR,
                    'detail' => 'The async directory of this VM holds more than one job id; no second create was started.',
                ];
            }
        } catch (RuntimeException) {
            // Still unreachable. Keep the heartbeat and the cancel state alive
            // between attempts rather than blocking the whole window in one
            // long call.
        }
        $channel->tick();
        deploy_worker_create_sleep($channel, $context, VIRTUSPHERE_CREATE_JID_DISCOVERY_INTERVAL_SECONDS);
    } while (time() < $deadline);

    return [
        'jid' => null,
        'error_code' => VIRTUSPHERE_CREATE_ERROR_LAUNCH_UNCONFIRMED,
        'detail' => 'No async job of this VM could be found after the launch channel was lost; no second create was started.',
    ];
}

/**
 * Removes the async state of one finished unit, best effort.
 *
 * Never for an uncertain unit and never by path: `async_status mode=cleanup`
 * removes exactly the state file of exactly this job id. A failure is reported
 * and changes nothing about the unit's outcome, which is already committed.
 *
 * @param array<string, mixed> $context
 * @param array<string, mixed> $unit
 */
function deploy_worker_create_cleanup_async(DeployWorkerDbChannel $channel, array $context, array $unit): void
{
    $jid = (string) ($unit['async_jid'] ?? '');
    if (!deploy_create_jid_is_valid($jid)) {
        return;
    }
    $remoteDir = (string) $context['remote_dir'];
    try {
        ssh_execute_capture(
            (array) $context['credential'],
            (string) $context['secret'],
            ansible_create_control_command($remoteDir, VIRTUSPHERE_CREATE_PLAYBOOK_CLEANUP, (int) $unit['position'], [
                'vs_async_jid' => $jid,
                'vs_async_dir' => ansible_create_async_dir($remoteDir, (int) $unit['position']),
            ], false),
            VIRTUSPHERE_CREATE_CONTROL_IDLE_TIMEOUT_SECONDS
        );
    } catch (Throwable $exception) {
        $channel->log(
            VIRTUSPHERE_DEPLOY_LOG_SYSTEM,
            deploy_worker_create_progress_line($unit, 'POLL', 'async state of this VM could not be cleaned up: '
                . deploy_worker_redact_secrets($exception->getMessage(), (array) ($context['secrets'] ?? [])))
        );
    }
}

/**
 * Whether this job is still this worker's, without confirming a cancel.
 *
 * Deliberately not deploy_worker_assert_job_is_ours(): that helper confirms the
 * cancellation at the boundary it is called from, and inside the poll loop
 * there is no boundary. The VM is being created, and the promise the portal
 * makes is "after the current VM".
 *
 * @return 'ours'|'cancelling'|'lost'
 */
function deploy_worker_create_ownership(mysqli $db, int $jobId, string $workerId): string
{
    $row = repo_fetch_one($db, 'SELECT status, locked_by FROM deploy_jobs WHERE id = ? LIMIT 1', 'i', [$jobId]);
    if ($row === null || (string) ($row['locked_by'] ?? '') !== $workerId) {
        return 'lost';
    }

    return match ((string) $row['status']) {
        VIRTUSPHERE_DEPLOY_STATUS_RUNNING => 'ours',
        VIRTUSPHERE_DEPLOY_STATUS_CANCELLING => 'cancelling',
        default => 'lost',
    };
}

/**
 * Waits, in slices, so the job heartbeat keeps beating while nothing else
 * happens. A single long sleep is what made a healthy worker look dead to the
 * reaper before AP6.
 *
 * @param array<string, mixed> $context
 */
function deploy_worker_create_sleep(DeployWorkerDbChannel $channel, array $context, int $seconds): void
{
    $sleeper = $context['sleeper'] ?? null;
    if (is_callable($sleeper)) {
        $sleeper($seconds);

        return;
    }
    $slice = max(1, min($seconds, VIRTUSPHERE_DEPLOY_HEARTBEAT_INTERVAL_SECONDS));
    for ($waited = 0; $waited < $seconds; $waited += $slice) {
        sleep((int) min($slice, $seconds - $waited));
        $channel->tick();
    }
}
