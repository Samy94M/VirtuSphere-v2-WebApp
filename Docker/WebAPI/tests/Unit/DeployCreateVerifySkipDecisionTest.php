<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/deploy_worker_create_unit.php';

final class DeployCreateVerifySkipDecisionTest extends TestCase
{
    public function testOnlyAProvenMissingInstanceOfTheSameVmCanBeRecreated(): void
    {
        $unit = ['vm_id' => 42];
        $source = ['vm_id' => 42, 'vm_instance_uuid' => 'old-uuid'];
        $absent = [
            'event' => VIRTUSPHERE_CREATE_EVENT_PREPARED,
            'existed_before' => false,
            'replaced_instance_uuid' => 'OLD-UUID',
        ];
        self::assertTrue(deploy_worker_create_skip_proves_absence($unit, $source, $absent));
        self::assertFalse(deploy_worker_create_skip_proves_absence($unit, $source, array_replace($absent, ['existed_before' => true])));
        self::assertFalse(deploy_worker_create_skip_proves_absence($unit, $source, array_replace($absent, ['event' => VIRTUSPHERE_CREATE_EVENT_REJECTED])));
        self::assertFalse(deploy_worker_create_skip_proves_absence($unit, $source, array_replace($absent, ['replaced_instance_uuid' => null])));
        self::assertFalse(deploy_worker_create_skip_proves_absence($unit, ['vm_id' => 43, 'vm_instance_uuid' => 'old-uuid'], $absent));
        self::assertFalse(deploy_worker_create_skip_proves_absence($unit, ['vm_id' => 42, 'vm_instance_uuid' => ''], $absent));
    }
}
