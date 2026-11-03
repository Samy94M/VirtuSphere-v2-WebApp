<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/ansible_command_preflight.php';

/**
 * FC2-17. The Ansible host preflight had two idle budgets: 25 s in the
 * credential's full test and 45 s in a job, so a slow host turned the full
 * test red while every job on it went through. Every caller now passes the one
 * constant. The callers are found, not listed: a new place that builds the
 * preflight command is covered from the day it is written.
 */
final class AnsiblePreflightBudgetContractTest extends TestCase
{
    public function testEveryPreflightCallerUsesTheSharedIdleBudget(): void
    {
        $lib = dirname(__DIR__, 2) . '/lib';
        $callers = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($lib, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $source = self::withoutComments((string) file_get_contents($file->getPathname()));
            $calls = preg_match_all('/(?<!function )\bansible_preflight_command\(/', $source);
            if ($calls > 0) {
                $callers[substr($file->getPathname(), strlen($lib) + 1)] = [$calls, substr_count($source, 'VIRTUSPHERE_ANSIBLE_PREFLIGHT_IDLE_SECONDS')];
            }
        }

        self::assertGreaterThanOrEqual(3, count($callers), 'the scan found fewer preflight callers than exist today: ' . implode(', ', array_keys($callers)));
        foreach ($callers as $path => [$calls, $budgets]) {
            self::assertGreaterThanOrEqual($calls, $budgets, $path . ' runs the preflight without the shared idle budget');
        }
        self::assertSame(45, VIRTUSPHERE_ANSIBLE_PREFLIGHT_IDLE_SECONDS);
    }

    private static function withoutComments(string $php): string
    {
        $code = '';
        foreach (token_get_all($php) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
}
