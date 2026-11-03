<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/deploy_worker_mission.php';

final class DeployWorkerTransportTypeTest extends TestCase
{
    public function testMissionStepDecorationPreservesEveryExactTransportType(): void
    {
        $exceptions = [
            new SshTransportBudgetExceeded('budget'),
            new SftpTransportFailed('sftp'),
            new SshTransportConfigurationException('config'),
        ];
        foreach ($exceptions as $exception) {
            $decorated = deploy_worker_transport_failure_with_step($exception, 'create_vms.yml');
            self::assertSame($exception::class, $decorated::class);
            self::assertSame($exception, $decorated->getPrevious());
            self::assertStringContainsString('create_vms.yml', $decorated->getMessage());
        }
    }

    public function testGenericRuntimeWithBudgetTextStaysGeneric(): void
    {
        $original = new RuntimeException('Remote command exceeded the total time limit of 10 seconds.');
        $decorated = deploy_worker_transport_failure_with_step($original, null);

        self::assertSame(RuntimeException::class, $decorated::class);
        self::assertSame($original, $decorated->getPrevious());
    }

    public function testMissionAndInventoryConsumeTheThrowableWithoutTextDemotion(): void
    {
        $mission = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/deploy_worker_mission.php');
        $inventory = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/deploy_worker_inventory.php');

        self::assertStringContainsString('catch (DeployWorkerCancelled $cancelled)', $mission);
        self::assertStringContainsString('throw $cancelled;', $mission);
        self::assertStringContainsString('throw deploy_worker_transport_failure_with_step($transportError, $currentStep);', $mission);
        self::assertStringContainsString('deploy_worker_classify_inventory_failure($phase, $exception)', $inventory);
        self::assertStringNotContainsString('deploy_worker_classify_inventory_failure($phase, $exception->getMessage())', $inventory);
    }
}
