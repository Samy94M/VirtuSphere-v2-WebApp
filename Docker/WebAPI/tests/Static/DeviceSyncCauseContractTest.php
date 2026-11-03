<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/device_sync_cause_constants.php';

/**
 * MX1 (FM-05) portal side of the reportDeviceCauses contract.
 *
 *  - The PowerShell mirror reads VIRTUSPHERE_DEVICE_SYNC_CAUSES from the PHP
 *    file by regex, so the layout (`const ... = [`, then one `    'code',` per
 *    line, then `];`) is wire, not style. The regex here is the same shape.
 *  - deploy_vm_sync_causes has one writer, reader and purge:
 *    lib/repo/vm_sync_causes.php. The search is derived from every PHP file
 *    under Docker/WebAPI, not from a list of known callers.
 *  - The endpoint routes the action through the token gate, its own byte bound
 *    and the validator before the writer.
 *
 * The PHP-side mirror against VirtuSphere-Common.ps1 and the language keys per
 * code belong to the display package (C2).
 */
final class DeviceSyncCauseContractTest extends TestCase
{
    private const CONSTANTS = 'lib/device_sync_cause_constants.php';
    private const OWNER = 'lib/repo/vm_sync_causes.php';

    public function testTheCodeListLayoutIsTheOneThePowerShellMirrorReads(): void
    {
        $source = $this->source(self::CONSTANTS);
        $matched = preg_match("/^const VIRTUSPHERE_DEVICE_SYNC_CAUSES = \\[\\n((?:    '[a-z_]+',\\n)+)\\];$/m", $source, $match);

        self::assertSame(1, $matched, 'the list must stay one quoted code per line');
        preg_match_all("/'([a-z_]+)'/", $match[1], $codes);
        self::assertSame(VIRTUSPHERE_DEVICE_SYNC_CAUSES, $codes[1]);
        self::assertSame(1, substr_count($source, 'const VIRTUSPHERE_DEVICE_SYNC_CAUSES = ['));
    }

    public function testTheTableHasOneOwner(): void
    {
        $root = dirname(__DIR__, 2);
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        $found = [];
        foreach ($files as $file) {
            $path = str_replace('\\', '/', substr((string) $file, strlen($root) + 1));
            if (!str_ends_with($path, '.php') || str_starts_with($path, 'tests/') || str_starts_with($path, 'vendor/')) {
                continue;
            }
            if (str_contains((string) file_get_contents((string) $file), 'deploy_vm_sync_causes')) {
                $found[] = $path;
            }
        }
        sort($found);

        self::assertSame(['lib/migrations/0072_device_sync_causes.php', self::OWNER], $found);
    }

    public function testTheEndpointGatesBoundsAndValidatesTheAction(): void
    {
        $report = $this->source('mecm_report.php');
        $branch = (string) strstr($report, "if (\$action === 'reportDeviceCauses')");
        $branch = substr($branch, 0, (int) strpos($branch, "if (\$action === 'reportPhase')"));

        self::assertStringContainsString("'reportDeviceCauses' => VIRTUSPHERE_DEVICE_SYNC_CAUSE_MAX_BODY_BYTES", $report);
        self::assertStringContainsString("\$action === 'reportDeviceCauses';", $report, 'token gate');
        self::assertNotSame('', $branch);
        self::assertLessThan(strpos($branch, 'device_sync_causes_validate('), strpos($branch, 'machine_api_forbidden('), 'IP gate first');
        self::assertLessThan(strpos($branch, 'repo_vm_sync_causes_record('), strpos($branch, 'device_sync_causes_validate('), 'validate before write');
        self::assertStringNotContainsString('deploy_vms', $branch);
    }

    private function source(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
