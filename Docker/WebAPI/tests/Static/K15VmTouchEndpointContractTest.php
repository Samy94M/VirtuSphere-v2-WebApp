<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class K15VmTouchEndpointContractTest extends TestCase
{
    public function testTouchRouteRequiresMachineIpAndExactTypedRequest(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/deploy_vm_touch.php');
        self::assertIsString($source);
        self::assertStringContainsString('machine_api_ip_allowed($connection, $clientIp, \'vm_touch\')', $source);
        self::assertStringContainsString('($_SERVER[\'REQUEST_METHOD\'] ?? \'\') !== \'POST\'', $source);
        self::assertStringContainsString('count($payload) !== 5', $source);
        foreach (['job_id', 'vm_id', 'attempt', 'generation', 'phase'] as $field) {
            self::assertStringContainsString("$" . "payload['$field']", $source);
        }
        self::assertStringContainsString("'reason_code' => 'touch_refused'", $source);
    }

    public function testRouteAcknowledgesTheExactAdmissionIdentityAndDoesNotWriteVmState(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/deploy_vm_touch.php');
        self::assertIsString($source);
        self::assertStringContainsString('repo_deploy_job_vm_admit_touch($connection, $jobId, $vmId, $attempt, $generation, $phase)', $source);
        self::assertStringContainsString('[\'success\' => true, \'job_id\' => $jobId, \'vm_id\' => $vmId, \'attempt\' => $attempt, \'generation\' => $generation, \'phase\' => $phase]', $source);
        self::assertStringNotContainsString('UPDATE deploy_vms', $source);
        self::assertStringNotContainsString('correlation_id', $source);
        self::assertStringNotContainsString('lock_token', $source);
    }
}
