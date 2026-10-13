<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/ansible_command.php';

/**
 * The one remote command that removes the ESXi access file accounts.yml (K21,
 * VT-04). The file form names exactly one file and never recurses, because the
 * job directory can hold the async evidence of an uncertain create unit; the
 * directory form removes the whole job directory of a provably idle host. Both
 * report on stdout whether the file was there and is removed or was already
 * gone, and only after a successful removal: an exit code alone cannot tell the
 * two apart, because `rm -f` answers 0 for a path that does not exist (measured
 * in the QA image by the K21 Astra review).
 *
 * The behavioural cases run the built command through /bin/sh in a private
 * temporary directory, so they pin what the shell does, not how the text looks.
 * A directory that is not an absolute path without traversal is refused before
 * anything is quoted into a shell command.
 */
final class AnsibleRemoteSecretCommandTest extends TestCase
{
    private const STATE_REMOVED = 'VIRTUSPHERE_REMOTE_SECRET removed';
    private const STATE_ABSENT = 'VIRTUSPHERE_REMOTE_SECRET absent';

    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/vs-k21-command-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($this->root, 0700, true));
    }

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            exec('rm -rf -- ' . escapeshellarg($this->root));
        }
    }

    public function testTheFileFormRemovesTheAccountsFileAndReportsThatItWasThere(): void
    {
        $dir = $this->jobDir('job one', true);
        file_put_contents($dir . '/serverlist.yml', 'keep');

        [$exit, $output] = $this->runShell(ansible_remote_secret_remove_command($dir));

        self::assertSame(0, $exit);
        self::assertSame([self::STATE_REMOVED], $output);
        self::assertFileDoesNotExist($dir . '/accounts.yml');
        self::assertFileExists($dir . '/serverlist.yml', 'the file form touches nothing but accounts.yml');
    }

    public function testTheFileFormReportsAnAlreadyMissingFileWithExitZero(): void
    {
        $dir = $this->jobDir('job two', false);

        [$exit, $output] = $this->runShell(ansible_remote_secret_remove_command($dir));

        self::assertSame(0, $exit);
        self::assertSame([self::STATE_ABSENT], $output);
    }

    public function testTheFileFormReportsAMissingJobDirectoryAsAbsent(): void
    {
        [$exit, $output] = $this->runShell(ansible_remote_secret_remove_command($this->root . '/never-created'));

        self::assertSame(0, $exit);
        self::assertSame([self::STATE_ABSENT], $output);
    }

    public function testTheDirectoryFormRemovesTheWholeDirectoryAndReportsThatTheFileWasThere(): void
    {
        $dir = $this->jobDir('job three', true);
        mkdir($dir . '/create.vm.1/async', 0700, true);

        [$exit, $output] = $this->runShell(ansible_remote_secret_remove_command($dir, true));

        self::assertSame(0, $exit);
        self::assertSame([self::STATE_REMOVED], $output);
        self::assertDirectoryDoesNotExist($dir);
    }

    public function testTheDirectoryFormReportsAbsentForADirectoryWithoutTheFile(): void
    {
        $dir = $this->jobDir('job four', false);

        [$exit, $output] = $this->runShell(ansible_remote_secret_remove_command($dir, true));

        self::assertSame(0, $exit);
        self::assertSame([self::STATE_ABSENT], $output);
        self::assertDirectoryDoesNotExist($dir);
    }

    public function testAFailedFileRemovalExitsNonZeroAndReportsNoState(): void
    {
        $dir = $this->jobDir('job five', false);
        // A directory named accounts.yml cannot be removed by rm -f, also not as root.
        mkdir($dir . '/accounts.yml/inner', 0700, true);

        [$exit, $output] = $this->runShell(ansible_remote_secret_remove_command($dir));

        self::assertNotSame(0, $exit);
        self::assertSame([], array_values(array_filter($output, static fn (string $line): bool => str_starts_with($line, 'VIRTUSPHERE_REMOTE_SECRET'))));
        self::assertDirectoryExists($dir . '/accounts.yml');
    }

    public function testAQuoteInTheDirectoryIsEscapedForTheShellAndCannotEndTheArgument(): void
    {
        $dir = $this->jobDir("it's a job", true);

        [$exit, $output] = $this->runShell(ansible_remote_secret_remove_command($dir));

        self::assertSame(0, $exit);
        self::assertSame([self::STATE_REMOVED], $output);
        self::assertStringContainsString("'\\''", ansible_remote_secret_remove_command($dir));
    }

    public function testATrailingSlashOnTheDirectoryDoesNotProduceADoubleSlash(): void
    {
        self::assertStringNotContainsString('//', ansible_remote_secret_remove_command('/tmp/deploy job/'));
        self::assertStringContainsString("'/tmp/deploy job/accounts.yml'", ansible_remote_secret_remove_command('/tmp/deploy job/'));
    }

    public function testTheFileFormIsNeverARecursiveRemoval(): void
    {
        $command = ansible_remote_secret_remove_command('/tmp/job');

        self::assertStringContainsString('rm -f -- ', $command);
        self::assertDoesNotMatchRegularExpression('/\brm\s+-[a-zA-Z]*r/', $command);
    }

    #[DataProvider('refusedDirectories')]
    public function testADirectoryThatIsEmptyRelativeOrTraversingIsRefusedBeforeAnyCommandIsBuilt(string $directory): void
    {
        $this->expectException(InvalidArgumentException::class);

        ansible_remote_secret_remove_command($directory);
    }

    #[DataProvider('refusedDirectories')]
    public function testTheDirectoryFormRefusesTheSameDirectories(string $directory): void
    {
        $this->expectException(InvalidArgumentException::class);

        ansible_remote_secret_remove_command($directory, true);
    }

    /** @return array<string, array{string}> */
    public static function refusedDirectories(): array
    {
        return [
            'empty' => [''],
            'relative' => ['tmp/job'],
            'parent prefix' => ['../job'],
            'traversal inside the path' => ['/tmp/../etc'],
        ];
    }

    private function jobDir(string $name, bool $withSecret): string
    {
        $dir = $this->root . '/' . $name;
        self::assertTrue(mkdir($dir, 0700, true));
        if ($withSecret) {
            file_put_contents($dir . '/accounts.yml', "esxi_password: fixture\n");
        }

        return $dir;
    }

    /** @return array{0: int, 1: list<string>} exit code and stdout lines of /bin/sh -c $command */
    private function runShell(string $command): array
    {
        $output = [];
        $exit = -1;
        exec('/bin/sh -c ' . escapeshellarg($command) . ' 2>/dev/null', $output, $exit);

        return [$exit, $output];
    }
}
