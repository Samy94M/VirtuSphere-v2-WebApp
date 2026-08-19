<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/deploy_job_output.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_worker_db_channel.php';
require_once dirname(__DIR__) . '/Support/RecordingDbOperations.php';

/**
 * The one gate every worker output passes before it is persisted (Etappe 8).
 *
 * Ansible output is not plain text: it carries ANSI colour, progress rendering
 * with carriage returns, occasionally invalid UTF-8 from a remote locale, and
 * with `-vvv` single lines of many kilobytes. Three readers - the portal table,
 * the polling JSON and the raw download - all assume it is. Normalising per
 * reader would be three rules that drift, so the writer stores what every
 * reader needs.
 */
final class DeployJobOutputLimitsTest extends TestCase
{
    public function testAnsiColourAndControlCharactersAreRemovedButTabSurvives(): void
    {
        // A real ansible-playbook line with colour and a cursor sequence.
        $line = "\x1b[0;32mok: [esxi01]\x1b[0m\tchanged=0\x1b[2K";
        self::assertSame("ok: [esxi01]\tchanged=0", deploy_job_output_normalize_line($line));

        // OSC (window title) has its own terminator and must not leave bytes.
        self::assertSame('after', deploy_job_output_normalize_line("\x1b]0;a title\x07after"));
        // DEL and the other C0 characters go; tab is the one exception,
        // because Ansible indents structured output with it.
        self::assertSame("a\tb", deploy_job_output_normalize_line("a\x00\x7f\tb\x08"));
    }

    public function testInvalidUtf8BecomesVisibleInsteadOfBreakingTheColumn(): void
    {
        $line = deploy_job_output_normalize_line("valid \xC3\x28 tail");
        self::assertTrue(mb_check_encoding($line, 'UTF-8'), 'a stored line must be valid UTF-8');
        self::assertStringContainsString('valid', $line);
        self::assertStringContainsString('tail', $line);
    }

    public function testALongLineIsCutOnACharacterBoundaryAndSaysSo(): void
    {
        // Multi-byte on purpose: cutting mid-character would put exactly the
        // invalid sequence into the column this function exists to keep clean.
        $line = str_repeat('ä', VIRTUSPHERE_DEPLOY_OUTPUT_LINE_MAX_BYTES);
        $result = deploy_job_output_truncate_line($line);

        self::assertTrue($result['truncated']);
        self::assertLessThanOrEqual(VIRTUSPHERE_DEPLOY_OUTPUT_LINE_MAX_BYTES, strlen($result['line']));
        self::assertTrue(mb_check_encoding($result['line'], 'UTF-8'));
        self::assertStringEndsWith(VIRTUSPHERE_DEPLOY_OUTPUT_TRUNCATION_MARKER, $result['line']);
    }

    public function testAShortLineIsUntouched(): void
    {
        $result = deploy_job_output_truncate_line('TASK [Gathering Facts]');
        self::assertFalse($result['truncated']);
        self::assertSame('TASK [Gathering Facts]', $result['line']);
    }

    /**
     * The notice's `match` has no default, and the only thing that lets PHPStan
     * prove it exhaustive is the hand-written union in its docblock. A third
     * truncation kind added to the constants but not to that union would keep
     * the build green and turn into an \UnhandledMatchError raised inside a
     * stream callback - where an \Error is not caught by the worker's
     * `catch (Throwable)`, so the job would lose its terminal state entirely.
     */
    public function testTheNoticeDocblockUnionMatchesTheTruncationKinds(): void
    {
        $doc = (new ReflectionFunction('deploy_job_output_limit_notice'))->getDocComment();
        self::assertIsString($doc, 'the docblock is what narrows the match; it may not be dropped.');
        self::assertSame(1, preg_match("/@param\s+((?:'[a-z_]+'\|)*'[a-z_]+')/", $doc, $union), '@param union not found.');
        preg_match_all("/'([a-z_]+)'/", $union[1], $literals);
        self::assertNotSame([], $literals[1], 'Zero match: the @param union is empty.');

        $kinds = [VIRTUSPHERE_DEPLOY_OUTPUT_LIMIT_LINE, VIRTUSPHERE_DEPLOY_OUTPUT_LIMIT_TOTAL];
        sort($kinds);
        $documented = $literals[1];
        sort($documented);
        self::assertSame($kinds, $documented);

        // And every documented kind really produces a sentence.
        foreach ($kinds as $kind) {
            self::assertNotSame('', deploy_job_output_limit_notice($kind));
        }
    }

    public function testTheLineLimitIsAnnouncedOncePerJobNotPerLine(): void
    {
        $ops = new RecordingDbOperations();
        $channel = $this->channel($ops);

        for ($i = 0; $i < 3; $i++) {
            $channel->log(VIRTUSPHERE_DEPLOY_LOG_STDOUT, str_repeat('x', VIRTUSPHERE_DEPLOY_OUTPUT_LINE_MAX_BYTES + 100));
        }

        $notices = array_values(array_filter(
            $ops->logs,
            static fn (array $row): bool => str_contains($row['line'], 'stored shortened')
        ));
        self::assertCount(1, $notices, 'one notice per job; per line it would be the noise it prevents');
        self::assertSame(VIRTUSPHERE_DEPLOY_LOG_SYSTEM, $notices[0]['stream']);
        // The three lines themselves are all stored, just short.
        self::assertCount(4, $ops->logs);
    }

    public function testTheTotalBudgetStopsStoringAndSaysSoExactlyOnce(): void
    {
        $ops = new RecordingDbOperations();
        $channel = $this->channel($ops);

        $chunk = str_repeat('y', VIRTUSPHERE_DEPLOY_OUTPUT_LINE_MAX_BYTES);
        $needed = (int) ceil(VIRTUSPHERE_DEPLOY_OUTPUT_JOB_MAX_BYTES / VIRTUSPHERE_DEPLOY_OUTPUT_LINE_MAX_BYTES);
        for ($i = 0; $i < $needed + 5; $i++) {
            $channel->log(VIRTUSPHERE_DEPLOY_LOG_STDOUT, $chunk);
        }

        $notices = array_values(array_filter(
            $ops->logs,
            static fn (array $row): bool => str_contains($row['line'], 'stored-output limit')
        ));
        self::assertCount(1, $notices);
        // Everything after the notice is dropped, so the log cannot keep
        // growing past the budget the notice just announced.
        self::assertSame($notices[0]['line'], end($ops->logs)['line']);
    }

    /**
     * The sentinel rule at the writer (Etappe 8): a secret that reaches the
     * worker never reaches the row. `no_log` on the Ansible side is defence in
     * depth, not a guarantee - `-vvv`, a module without it or a failing task
     * can echo a value back, and the job log is readable by everyone with
     * deploy.run.
     */
    public function testASecretEchoedByThePlaybookNeverReachesTheRow(): void
    {
        $ops = new RecordingDbOperations();
        $channel = $this->channel($ops);
        $sentinel = 'vs-sentinel-8c4f1e2a';
        $channel->withSecrets([$sentinel, null, '']);

        $channel->log(VIRTUSPHERE_DEPLOY_LOG_STDOUT, 'fatal: password=' . $sentinel . ' rejected');
        $channel->log(VIRTUSPHERE_DEPLOY_LOG_STDOUT, 'url https://h/?pw=' . rawurlencode($sentinel));

        foreach ($ops->logs as $row) {
            self::assertStringNotContainsString($sentinel, $row['line']);
            self::assertStringNotContainsString(rawurlencode($sentinel), $row['line']);
        }
        // The rest of the evidence survives: redaction must not shred the line
        // the operator needs in order to see WHAT was rejected.
        self::assertStringContainsString('fatal: password=*** rejected', $ops->logs[0]['line']);
    }

    /**
     * A colour sequence wrapped around a secret used to defeat a naive
     * redaction that ran before normalisation. Order matters, and this pins it.
     */
    public function testNormalisationRunsBeforeRedactionSoAColouredSecretIsStillCaught(): void
    {
        $ops = new RecordingDbOperations();
        $channel = $this->channel($ops);
        $sentinel = 'vs-sentinel-9b7d3c11';
        $channel->withSecrets([$sentinel]);

        $channel->log(VIRTUSPHERE_DEPLOY_LOG_STDOUT, "msg: \x1b[31m" . substr($sentinel, 0, 6) . "\x1b[0m" . substr($sentinel, 6));

        self::assertStringNotContainsString($sentinel, $ops->logs[0]['line']);
    }

    private function channel(RecordingDbOperations $ops): DeployWorkerDbChannel
    {
        return new DeployWorkerDbChannel(
            $this->connectionStub(),
            static fn (): mysqli => throw new RuntimeException('no reconnect in this test'),
            42,
            'phpunit:limits',
            static fn (): int => 1_000_000,
            $ops
        );
    }

    private function connectionStub(): mysqli
    {
        // Never used: RecordingDbOperations does not touch the handle, and the
        // channel only hands it on. Allocating an unconnected mysqli keeps this
        // test free of a database.
        return mysqli_init();
    }
}
