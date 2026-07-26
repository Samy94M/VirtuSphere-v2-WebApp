<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/deploy_urls.php';

final class DeployUrlsTest extends TestCase
{
    public function testJobLogUrlOwnsTheRouteAndRejectsInvalidIds(): void
    {
        self::assertSame('deploy_log.php?id=42', deploy_job_log_url(42));

        $this->expectException(InvalidArgumentException::class);
        deploy_job_log_url(0);
    }

    public function testMissionJobReturnsToItsFilteredDeployList(): void
    {
        self::assertSame(
            'deploy.php?mission_id=17',
            deploy_job_origin_url(['mission_id' => 17, 'credential_esxi_id' => 9])
        );
    }

    public function testInventoryJobReturnsToItsExactSystemStatusCard(): void
    {
        self::assertSame(
            'system_status.php?inventory=9#credential-9',
            deploy_job_origin_url(['mission_id' => null, 'credential_esxi_id' => 9])
        );
    }

    public function testInventoryJobWithDeletedCredentialReturnsToTheEsxiSection(): void
    {
        self::assertSame(
            'system_status.php#esxi',
            deploy_job_origin_url(['mission_id' => null, 'credential_esxi_id' => null])
        );
    }
}
