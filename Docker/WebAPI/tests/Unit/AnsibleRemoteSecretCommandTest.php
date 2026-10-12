<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/ansible_command.php';

/**
 * The one remote command that removes the ESXi access file accounts.yml outside
 * a sequence step (K21, VT-04). It names exactly one file: a recursive removal
 * of the job directory belongs to ansible_remote_cleanup_command(), and the
 * sweep must never reach it, because the directory can hold the async evidence
 * of an uncertain create unit. A directory that is not an absolute path without
 * traversal is refused before anything is quoted into a shell command.
 */
final class AnsibleRemoteSecretCommandTest extends TestCase
{
    public function testRemovesExactlyTheAccountsFileInsideTheJobDirectory(): void
    {
        self::assertSame(
            "rm -f -- '/tmp/deploy job/accounts.yml'",
            ansible_remote_secret_remove_command('/tmp/deploy job')
        );
    }

    public function testATrailingSlashOnTheDirectoryDoesNotProduceADoubleSlash(): void
    {
        self::assertSame(
            "rm -f -- '/tmp/deploy job/accounts.yml'",
            ansible_remote_secret_remove_command('/tmp/deploy job/')
        );
    }

    public function testAQuoteInTheDirectoryIsEscapedForTheShellAndCannotEndTheArgument(): void
    {
        self::assertSame(
            "rm -f -- '/tmp/it'\\''s/accounts.yml'",
            ansible_remote_secret_remove_command("/tmp/it's")
        );
    }

    public function testTheCommandIsAForcedFileRemovalAndNeverARecursiveOne(): void
    {
        $command = ansible_remote_secret_remove_command('/tmp/job');

        self::assertStringStartsWith('rm -f -- ', $command);
        self::assertDoesNotMatchRegularExpression('/\s-[a-zA-Z]*r/', $command);
    }

    #[DataProvider('refusedDirectories')]
    public function testADirectoryThatIsEmptyRelativeOrTraversingIsRefusedBeforeAnyCommandIsBuilt(string $directory): void
    {
        $this->expectException(InvalidArgumentException::class);

        ansible_remote_secret_remove_command($directory);
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
}
