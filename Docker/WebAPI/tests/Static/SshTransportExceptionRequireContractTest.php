<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SshTransportExceptionRequireContractTest extends TestCase
{
    private const DIRECT_CONSUMERS = [
        'connection_errors.php',
        'ssh_sftp.php',
        'ssh.php',
        'deploy_worker_outcome.php',
    ];

    public function testEveryConsumerRequiresTheExceptionFileDirectly(): void
    {
        $sources = [];
        foreach (self::DIRECT_CONSUMERS as $file) {
            $sources[$file] = (string) file_get_contents($this->lib() . '/' . $file);
        }
        self::assertSame([], $this->directRequireProblems($sources));
    }

    public function testRequireValidatorRejectsNegativeAndZeroMatchFixtures(): void
    {
        self::assertNotSame([], $this->directRequireProblems([]), 'an empty source scan passed');
        self::assertNotSame(
            [],
            $this->directRequireProblems(array_fill_keys(self::DIRECT_CONSUMERS, '<?php declare(strict_types=1);')),
            'sources without the direct require passed'
        );
    }

    public function testOutcomeLoadsAllTypesWithoutLoadingSshFirst(): void
    {
        $entry = $this->lib() . '/deploy_worker_outcome.php';
        $code = 'require ' . var_export($entry, true) . '; echo json_encode(['
            . 'class_exists("SshTransportBudgetExceeded", false),'
            . 'class_exists("SftpTransportFailed", false),'
            . 'class_exists("SshTransportConfigurationException", false)'
            . ']);';
        $process = proc_open(
            [PHP_BINARY, '-r', $code],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2)
        );
        self::assertIsResource($process, 'could not start the isolated require process');
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        self::assertSame(0, $exit, $stderr);
        self::assertSame('[true,true,true]', $stdout, $stderr);
    }

    private function lib(): string
    {
        return dirname(__DIR__, 2) . '/lib';
    }

    /** @param array<string,string> $sources @return list<string> */
    private function directRequireProblems(array $sources): array
    {
        $problems = [];
        if ($sources === []) {
            $problems[] = 'source scan matched no consumers';
        }
        $needle = "require_once __DIR__ . '/ssh_transport_exceptions.php';";
        foreach (self::DIRECT_CONSUMERS as $file) {
            if (!isset($sources[$file])) {
                $problems[] = $file . ' was not scanned';
            } elseif (!str_contains($sources[$file], $needle)) {
                $problems[] = $file . ' has no direct exception require';
            }
        }

        return $problems;
    }
}
