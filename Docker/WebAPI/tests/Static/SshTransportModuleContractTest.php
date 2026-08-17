<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/ssh.php';

final class SshTransportModuleContractTest extends TestCase
{
    public function testRegistryAndFilesystemAreBidirectionallyComplete(): void
    {
        $registered = VIRTUSPHERE_SSH_TRANSPORT_MODULES;
        sort($registered);
        $files = array_map('basename', glob($this->lib() . '/ssh*.php') ?: []);
        sort($files);

        self::assertSame([], $this->registryProblems($registered, $files));
    }

    public function testRegistryValidatorRejectsEveryDriftDirectionAndZeroMatch(): void
    {
        $registered = VIRTUSPHERE_SSH_TRANSPORT_MODULES;
        $files = $registered;
        self::assertNotSame([], $this->registryProblems($registered, []), 'an empty module scan passed');
        self::assertNotSame([], $this->registryProblems(array_slice($registered, 1), $files), 'a missing registry entry passed');
        self::assertNotSame([], $this->registryProblems([...$registered, 'ssh_extra.php'], $files), 'an additional registry entry passed');
        self::assertNotSame([], $this->registryProblems([...$registered, $registered[0]], $files), 'a duplicate registry entry passed');
    }

    public function testSplitHasOneOwnerPerFunctionAndKeepsBothDomainsBounded(): void
    {
        $owners = [];
        foreach (VIRTUSPHERE_SSH_TRANSPORT_MODULES as $file) {
            $path = $this->lib() . '/' . $file;
            $source = (string) file_get_contents($path);
            preg_match_all('/^function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/m', $source, $matches);
            foreach ($matches[1] as $function) {
                $owners[$function][] = $file;
            }
            self::assertLessThanOrEqual(400, count(file($path)), $file . ' exceeds the ADR-0006 target');
        }

        self::assertNotSame([], $owners, 'the function-owner scan matched nothing');
        foreach ($owners as $function => $files) {
            self::assertCount(1, $files, $function . ' has multiple owners: ' . implode(', ', $files));
        }
        self::assertSame(['ssh.php'], $owners['ssh_execute_command'] ?? []);
        self::assertSame(['ssh_sftp.php'], $owners['ssh_sftp_upload_directory'] ?? []);
        self::assertSame(['ssh_sftp.php'], $owners['ssh_sftp_probe'] ?? []);
    }

    public function testSftpCleanupAndCallbackBoundariesStayExplicit(): void
    {
        $source = (string) file_get_contents($this->lib() . '/ssh_sftp.php');
        self::assertSame(2, substr_count($source, '$sftp->disconnect();'), 'upload and probe each disconnect exactly once');
        self::assertStringContainsString('} finally {', $source);
        self::assertStringContainsString("'delete probe file'", $source);
        self::assertStringContainsString('if ($logger !== null)', $source);

        self::assertSame(1, preg_match(
            '/function ssh_sftp_run_operation\(.*?^\}/ms',
            $source,
            $guard
        ), 'the SFTP operation guard was not found');
        self::assertStringNotContainsString('disconnect', $guard[0], 'the guard clears timeout state before classification');
        self::assertStringNotContainsString('$logger', $guard[0], 'DB/logger callbacks must remain outside the SFTP guard');
    }

    private function lib(): string
    {
        return dirname(__DIR__, 2) . '/lib';
    }

    /** @param list<string> $registered @param list<string> $files @return list<string> */
    private function registryProblems(array $registered, array $files): array
    {
        $problems = [];
        if ($files === []) {
            $problems[] = 'module scan matched no files';
        }
        if ($registered !== array_values(array_unique($registered))) {
            $problems[] = 'module registry contains duplicates';
        }
        $uniqueRegistered = array_values(array_unique($registered));
        sort($uniqueRegistered);
        $uniqueFiles = array_values(array_unique($files));
        sort($uniqueFiles);
        if ($uniqueRegistered !== $uniqueFiles) {
            $problems[] = 'module registry and filesystem differ';
        }

        return $problems;
    }
}
