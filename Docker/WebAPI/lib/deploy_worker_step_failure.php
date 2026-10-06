<?php

declare(strict_types=1);

require_once __DIR__ . '/ansible_command_modes.php';
require_once __DIR__ . '/defaults.php';
require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/deploy_job_output.php';
require_once __DIR__ . '/deploy_log_filter.php';
require_once __DIR__ . '/deploy_worker_db_channel.php';

/**
 * What a failed playbook step leaves behind for its terminal reason (FC2-05,
 * FC2-16).
 *
 * A failed step used to end as "Ansible command failed with exit code N
 * (playbook step: X)", and the task that failed and its message existed only
 * somewhere in the log, also where a playbook writes a clear cause. The worker
 * now follows the step's lines as they are stored and keeps the last task
 * header and the last failure line, and for the autostart step the number of
 * entries the playbook reports as written.
 */

// Written by autostartVMs-ESXi_playbook.yml in an `always` part, so it arrives
// also when a write for one VM failed.
const VIRTUSPHERE_ANSIBLE_AUTOSTART_MARKER = '::virtusphere-autostart::';

final class DeployWorkerStepTrace
{
    private ?string $task = null;
    private ?string $taskError = null;
    /** @var array{line:string,head:string,task:?string,error:?string}|null */
    private ?array $failure = null;
    /** @var array{written:int,total:int}|null */
    private ?array $autostart = null;

    /**
     * Observes one finished remote line, in order.
     *
     * Two output generations are read. Since ansible-core 2.19 the cause is
     * the one-line `[ERROR]: Task failed: ...` printed before the failure
     * line, a loop item's failure line ends in a multi-line JSON block, and
     * the task closes with `fatal: ... "One or more items failed"`. Before
     * 2.19 the failure line itself carried the message on one line.
     */
    public function observe(string $line): void
    {
        $line = trim($line);
        if (preg_match('/^TASK \[(.*)\]/', $line, $match) === 1) {
            $this->task = $match[1];
            $this->taskError = null;

            return;
        }
        if (str_starts_with($line, '[ERROR]: Task failed: ')) {
            $this->taskError = substr($line, strlen('[ERROR]: Task failed: '));

            return;
        }
        // An ignored failure is no cause. Ansible prints the failure and then
        // this line; the step goes on.
        if ($line === '...ignoring') {
            $this->failure = null;

            return;
        }
        if (preg_match(VIRTUSPHERE_ANSIBLE_FAILURE_HEAD_PATTERN, $line, $head) === 1 && str_starts_with($line, $head[0])) {
            // The closing summary of a loop says less than the item that
            // failed in the same task, so the item stays the cause.
            if (str_contains($line, 'One or more items failed')
                && $this->failure !== null
                && $this->failure['task'] === $this->task
                && str_starts_with($this->failure['head'], 'failed: ')
            ) {
                return;
            }
            $this->failure = ['line' => $line, 'head' => $head[0], 'task' => $this->task, 'error' => $this->taskError];

            return;
        }
        if (preg_match('/' . preg_quote(VIRTUSPHERE_ANSIBLE_AUTOSTART_MARKER, '/') . ' written=(\d+) total=(\d+)/', $line, $count) === 1) {
            $this->autostart = ['written' => (int) $count[1], 'total' => (int) $count[2]];
        }
    }

    /**
     * "TASK [name] <failure head>: <cause>", or the whole one-line failure
     * when no `[ERROR]` line named the cause; null when the step printed no
     * failure. The failure head is kept verbatim, so the log search finds it.
     */
    public function failureDetail(): ?string
    {
        if ($this->failure === null) {
            return null;
        }
        $prefix = $this->failure['task'] !== null ? 'TASK [' . $this->failure['task'] . '] ' : '';

        return $this->failure['error'] !== null
            ? $prefix . $this->failure['head'] . ': ' . $this->failure['error']
            : $prefix . $this->failure['line'];
    }

    /** @return array{written:int,total:int}|null */
    public function autostartCount(): ?array
    {
        return $this->autostart;
    }
}

/** A failed playbook step that carries its failure line for the terminal reason. */
final class DeployWorkerStepFailed extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $detail)
    {
        parent::__construct($message);
    }
}

/**
 * The exception for a step that returned a non-zero exit code. The message
 * stays the job's error; the failure line becomes the reason detail, normalised
 * and redacted the way its log line was, so the detail is the same text the log
 * search finds.
 */
function deploy_worker_step_failed(DeployWorkerDbChannel $channel, int $exitCode, string $step, DeployWorkerStepTrace $trace): DeployWorkerStepFailed
{
    $message = 'Ansible command failed with exit code ' . $exitCode . ansible_step_failure_suffix($step) . '.';
    $count = $trace->autostartCount();
    if ($step === VIRTUSPHERE_PLAYBOOKS['autostart'] && $count !== null) {
        // FC2-16: the VMs before and after a failed write carry different
        // policies. The number is what the playbook reported, never derived.
        $message .= ' ' . $count['written'] . ' of ' . $count['total']
            . ' VMs received their autostart entry before the step failed; the others keep their previous entry.';
    }
    $detail = $trace->failureDetail();
    if ($detail !== null) {
        $detail = $channel->redact(deploy_job_output_normalize_line($detail));
    }

    return new DeployWorkerStepFailed($message, $detail);
}
