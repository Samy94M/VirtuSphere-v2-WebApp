<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The one thing no in-process test can prove about lib/deploy_worker.php: that
 * running it actually boots.
 *
 * The file exits on require, so every other contract has to inspect it as text
 * or load a module below it. A missing require, a require in the wrong order or
 * a helper left in a module nothing loads is therefore invisible until a
 * container restarts in production - which is exactly how the worker's System
 * status row stayed broken for weeks (the defect CliRequireClosureContractTest
 * was built for). After the 2026-08-11 split into a CLI shell plus modules,
 * that risk is higher, not lower.
 *
 * So this starts the real entrypoint in a subprocess and reads its observable
 * contract: option parsing, the `--once` fail-fast policy (three connect
 * attempts, a warning before each retry, then the exception propagates) and the
 * STDERR wording an operator greps for.
 *
 * It points the worker at a hostname that cannot resolve, on purpose and not
 * just for speed: with an unreachable *port* the worker would connect to
 * whatever answers on 127.0.0.1 and then claim and run a real deploy job. A
 * reserved `.invalid` name (RFC 2606) cannot resolve anywhere, so this test can
 * never touch a database.
 */
final class DeployWorkerCliSmokeTest extends TestCase
{
    private const UNRESOLVABLE_DB_HOST = 'vs-smoke-unreachable.invalid';

    public function testTheEntrypointBootsAndHonoursTheOnceFailFastPolicy(): void
    {
        $entry = dirname(__DIR__, 2) . '/lib/deploy_worker.php';
        self::assertFileExists($entry);

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            [PHP_BINARY, $entry, '--once'],
            $descriptors,
            $pipes,
            dirname(__DIR__, 2),
            array_merge($_ENV, getenv(), ['DB_HOST' => self::UNRESOLVABLE_DB_HOST])
        );
        self::assertIsResource($process, 'could not start the worker entrypoint');

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $output = $stdout . $stderr;
        self::assertNotSame('', trim($output), 'the worker produced no output at all; it never reached its connect loop.');

        // A fatal from a missing require or a redeclared function looks nothing
        // like the connection failure this run is supposed to end in.
        self::assertStringNotContainsString('Fatal error', $output, $output);
        self::assertStringNotContainsString('Cannot redeclare', $output, $output);
        self::assertStringNotContainsString('Call to undefined function', $output, $output);

        // The `--once` policy: three attempts, so two retry warnings and then
        // the exception. `--loop` would retry forever instead.
        self::assertStringContainsString('[deploy-worker] Database not reachable (attempt 1)', $output, $output);
        self::assertStringContainsString('[deploy-worker] Database not reachable (attempt 2)', $output, $output);
        self::assertStringNotContainsString('[deploy-worker] Database not reachable (attempt 3)', $output, $output);
        self::assertStringContainsString('mysqli_sql_exception', $output, $output);
        self::assertNotSame(0, $exitCode, 'a worker that cannot reach its database must not exit successfully.');
    }
}
