<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * F2: lib/seed.php refuses the old positional form `<user> <password> [email]`,
 * which put the admin password into the process list and the shell history.
 *
 * The refusal happens before the database is touched, so this starts the real
 * entrypoint in a subprocess against a host that cannot resolve (RFC 2606
 * `.invalid`, as in DeployWorkerCliSmokeTest): a form that slipped past the
 * check would end in a connection error instead of exit code 2, and could never
 * create an account anywhere.
 */
final class SeedCliFormsTest extends TestCase
{
    private const UNRESOLVABLE_DB_HOST = 'vs-seed-unreachable.invalid';
    private const PASSWORD = 'Seed-Probe-Passwort-7x';

    /** @return array<string, array{0: list<string>}> */
    public static function refusedForms(): array
    {
        return [
            'user password email' => [['admin', self::PASSWORD, 'admin@example.lan']],
            'user password' => [['admin', self::PASSWORD]],
            'four arguments' => [['admin', self::PASSWORD, 'admin@example.lan', 'extra']],
        ];
    }

    /**
     * @param list<string> $arguments
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusedForms')]
    public function testThePasswordAsAnArgumentIsRefused(array $arguments): void
    {
        [$exitCode, $output] = $this->runSeed($arguments, '');

        self::assertSame(2, $exitCode, $output);
        self::assertStringContainsString('not accepted as an argument', $output, $output);
        self::assertStringNotContainsString(self::PASSWORD, $output, 'the refusal repeats the password');
        self::assertStringNotContainsString('mysqli', $output, 'the refusal must come before the database');
    }

    /**
     * The documented form gets past the argument check and reads the password
     * from STDIN; here it stops at the unreachable database, which proves it was
     * not refused as the old form.
     */
    public function testTheDocumentedFormReachesTheDatabase(): void
    {
        [$exitCode, $output] = $this->runSeed(['admin', 'admin@example.lan'], self::PASSWORD . "\n");

        self::assertNotSame(0, $exitCode, $output);
        self::assertStringNotContainsString('not accepted as an argument', $output, $output);
        self::assertStringNotContainsString('Usage:', $output, $output);
        self::assertStringNotContainsString(self::PASSWORD, $output, 'the password reached the output');
    }

    /**
     * @param list<string> $arguments
     * @return array{0: int, 1: string}
     */
    private function runSeed(array $arguments, string $stdin): array
    {
        $entry = dirname(__DIR__, 2) . '/lib/seed.php';
        self::assertFileExists($entry);

        $process = proc_open(
            [PHP_BINARY, $entry, ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
            array_merge($_ENV, getenv(), ['DB_HOST' => self::UNRESOLVABLE_DB_HOST])
        );
        self::assertIsResource($process, 'could not start lib/seed.php');

        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }
}
