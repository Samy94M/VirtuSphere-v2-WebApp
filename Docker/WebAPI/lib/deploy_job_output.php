<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_constants.php';
// For VIRTUSPHERE_CONNECTION_REDACT_MIN, the one minimum length below which a
// secret is too short to replace without shredding the message around it.
require_once __DIR__ . '/connection_errors.php';

// Stored-output limits (Etappe 8). Both are byte budgets, because the column,
// the JSON payload and the browser all pay in bytes, not characters.
//
//  - LINE: an `ansible-playbook -vvv` result line of a module that returns a
//    large structure is the realistic worst case, and a few kilobytes covers
//    it. The `line` column is TEXT (65535 bytes), so a cap well below it also
//    keeps one pathological line from being rejected by the database instead
//    of being stored short.
//  - JOB: the whole run. A full pipeline over a large mission with -vvv is a
//    few megabytes, so this is generous for every legitimate diagnosis while a
//    remote loop printing forever can no longer fill the disk or the DOM.
//
// Reaching either limit never ends the run: the playbook keeps changing ESXi
// and the heartbeat keeps beating. Only the diagnosis is marked as cut.
const VIRTUSPHERE_DEPLOY_OUTPUT_LINE_MAX_BYTES = 8192;
const VIRTUSPHERE_DEPLOY_OUTPUT_JOB_MAX_BYTES = 16777216;
const VIRTUSPHERE_DEPLOY_OUTPUT_TRUNCATION_MARKER = ' [line truncated]';

// The truncation kinds. Exactly one SYSTEM line is written per kind and job:
// per occurrence it would be the noise it exists to prevent.
const VIRTUSPHERE_DEPLOY_OUTPUT_LIMIT_LINE = 'line';
const VIRTUSPHERE_DEPLOY_OUTPUT_LIMIT_TOTAL = 'total';

/**
 * The one gate every worker output passes before it is persisted (Etappe 8).
 *
 * A job log is read in three places that all assume plain text: the portal
 * table, the polling JSON and the raw download. Ansible output is none of
 * those on its own. It carries ANSI colour, carriage returns from progress
 * rendering, occasionally invalid UTF-8 from a remote locale, and with `-vvv`
 * single lines of many kilobytes. Normalising at each reader would be three
 * rules that drift; normalising at the writer means the stored line is already
 * what every reader needs.
 *
 * Deliberately NOT a place that interprets output: it does not parse markers,
 * classify errors or shorten for readability. It only removes what cannot be
 * displayed and enforces the two limits that keep one runaway job from filling
 * the database and the browser.
 */

/**
 * Makes one line storable: valid UTF-8, no terminal control sequences.
 *
 * Tab survives on purpose - Ansible indents structured output with it, and
 * removing it turns a readable diff into one run-on line. Every other C0
 * character and DEL goes, including the ESC that starts a colour sequence,
 * because a raw escape in an HTML page or a JSON string is at best noise.
 */
function deploy_job_output_normalize_line(string $line): string
{
    // Invalid byte sequences become U+FFFD rather than being dropped: a line
    // that silently loses characters is worse evidence than one that shows
    // where the encoding broke.
    if (!mb_check_encoding($line, 'UTF-8')) {
        $line = mb_convert_encoding($line, 'UTF-8', 'UTF-8');
    }

    // CSI/OSC and the short two-character escapes, in that order: the long
    // forms first, so their trailing bytes are not left behind as text.
    $line = (string) preg_replace('/\x1b\][^\x07\x1b]*(?:\x07|\x1b\\\\)/', '', $line);
    $line = (string) preg_replace('/\x1b\[[0-9;?]*[ -\/]*[@-~]/', '', $line);
    $line = (string) preg_replace('/\x1b[@-_]/', '', $line);

    return (string) preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $line);
}

/**
 * Cuts a line to a byte budget without splitting a UTF-8 character in half,
 * which would put an invalid sequence into the column this function exists to
 * keep clean.
 *
 * @return array{line: string, truncated: bool}
 */
function deploy_job_output_truncate_line(string $line, int $maxBytes = VIRTUSPHERE_DEPLOY_OUTPUT_LINE_MAX_BYTES): array
{
    if (strlen($line) <= $maxBytes) {
        return ['line' => $line, 'truncated' => false];
    }

    $marker = VIRTUSPHERE_DEPLOY_OUTPUT_TRUNCATION_MARKER;
    $keep = $maxBytes - strlen($marker);
    $cut = $keep > 0 ? mb_strcut($line, 0, $keep, 'UTF-8') : '';

    return ['line' => $cut . $marker, 'truncated' => true];
}

/**
 * Why a job's output stopped being stored, as the one SYSTEM sentence the
 * operator sees for it. Technical English like every other job-log line: this
 * is the worker's own protocol, not portal prose.
 *
 * A reached limit never ends the run. The playbook keeps changing ESXi and the
 * heartbeat keeps beating; only the diagnosis is marked as cut, because a job
 * killed for being talkative would be the more expensive failure.
 *
 * @param 'line'|'total' $kind the two VIRTUSPHERE_DEPLOY_OUTPUT_LIMIT_* values,
 *        narrowed so PHPStan can prove the match exhaustive without a silent
 *        default. A third kind added to the constants and not to the match must
 *        be a build error, not an \UnhandledMatchError inside a stream callback
 *        (DeployJobOutputLimitsTest holds the union against the constants).
 */
function deploy_job_output_limit_notice(string $kind): string
{
    return match ($kind) {
        VIRTUSPHERE_DEPLOY_OUTPUT_LIMIT_LINE => 'Output lines longer than '
            . VIRTUSPHERE_DEPLOY_OUTPUT_LINE_MAX_BYTES . ' bytes are stored shortened. This notice appears once per job; '
            . 'the run itself is unaffected.',
        VIRTUSPHERE_DEPLOY_OUTPUT_LIMIT_TOTAL => 'This job reached the stored-output limit of '
            . VIRTUSPHERE_DEPLOY_OUTPUT_JOB_MAX_BYTES . ' bytes. Further output of the run is not stored. '
            . 'The run itself is unaffected and its result is recorded as usual.',
    };
}

/**
 * The per-job state of the output gate: what has already been stored, and
 * which limit has already been announced.
 *
 * Separate from DeployWorkerDbChannel on purpose. The channel's domain is the
 * outage state machine - connected or not, spool, backoff, ownership. What a
 * stored line may look like is a different question with a different reason to
 * change, and keeping it here also makes it testable without a channel.
 */
final class DeployJobOutputGate
{
    /** @var list<string> */
    private array $secrets = [];

    private int $totalBytes = 0;

    private bool $lineLimitAnnounced = false;

    /** @param array<int, mixed> $secrets */
    public function withSecrets(array $secrets): void
    {
        $this->secrets = array_values(array_filter(
            $secrets,
            static fn (mixed $secret): bool => is_string($secret) && $secret !== ''
        ));
    }

    /**
     * What should actually be written for one incoming line, in order.
     *
     * Returns zero rows once the job's budget is spent, one row normally, and
     * two when this line is the first to hit a limit: the (shortened) line and
     * the SYSTEM sentence that says so.
     *
     * @return list<array{stream: string, line: string}>
     */
    public function accept(string $stream, string $line): array
    {
        $line = deploy_job_output_normalize_line($line);
        if ($this->secrets !== []) {
            $line = deploy_worker_redact_secrets($line, $this->secrets);
        }

        if ($this->totalBytes >= VIRTUSPHERE_DEPLOY_OUTPUT_JOB_MAX_BYTES) {
            // Silent from here on: the notice below was written exactly once,
            // at the moment the budget ran out.
            return [];
        }

        $truncated = deploy_job_output_truncate_line($line);
        $this->totalBytes += strlen($truncated['line']);
        $rows = [['stream' => $stream, 'line' => $truncated['line']]];

        if ($truncated['truncated'] && !$this->lineLimitAnnounced) {
            $this->lineLimitAnnounced = true;
            $rows[] = [
                'stream' => VIRTUSPHERE_DEPLOY_LOG_SYSTEM,
                'line' => deploy_job_output_limit_notice(VIRTUSPHERE_DEPLOY_OUTPUT_LIMIT_LINE),
            ];
        }
        if ($this->totalBytes >= VIRTUSPHERE_DEPLOY_OUTPUT_JOB_MAX_BYTES) {
            $rows[] = [
                'stream' => VIRTUSPHERE_DEPLOY_LOG_SYSTEM,
                'line' => deploy_job_output_limit_notice(VIRTUSPHERE_DEPLOY_OUTPUT_LIMIT_TOTAL),
            ];
        }

        return $rows;
    }
}

/**
 * Strips credential secrets (and their URL-encoded form) out of text before it
 * reaches the job log. Same minimum length as connection_error_detail():
 * replacing a 1-3 character secret would shred the message.
 *
 * Deliberately no truncation or whitespace collapse here; that is the gate's
 * job and happens after this, so a secret cannot survive inside a fragment the
 * shortening produced.
 *
 * @param array<int, mixed> $secrets
 */
function deploy_worker_redact_secrets(string $message, array $secrets): string
{
    foreach ($secrets as $secret) {
        if (is_string($secret) && strlen($secret) >= VIRTUSPHERE_CONNECTION_REDACT_MIN) {
            $message = str_replace([$secret, rawurlencode($secret)], '***', $message);
        }
    }

    return $message;
}
