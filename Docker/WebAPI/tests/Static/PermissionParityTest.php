<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PermissionParityTest extends TestCase
{
    public function testPermissionLiteralsAreDeclared(): void
    {
        $root = dirname(__DIR__, 2);
        $files = glob($root . '/portal/*.php') ?: [];
        $unknown = [];

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            preg_match_all("/can\\('([^']+)'/", $source, $matches);
            foreach ($matches[1] as $permission) {
                if (!in_array($permission, VIRTUSPHERE_PERMISSIONS, true)) {
                    $unknown[] = basename($file) . ':' . $permission;
                }
            }
        }

        self::assertSame([], $unknown);
    }

    public function testCuratedWriteHandlersHavePermissionGuards(): void
    {
        $root = dirname(__DIR__, 2);
        $expectations = [
            'credentials.php' => ['credentials.manage'],
            'deploy.php' => ['deploy.run'],
            'mission_details.php' => ['missions.write'],
            'missions.php' => ['missions.write'],
            'os.php' => ['catalog.write'],
            'settings.php' => ['system.config'],
            'users.php' => ['users.manage'],
            'vlans.php' => ['catalog.write'],
            'vm_edit.php' => ['vms.write'],
            'vms.php' => ['vms.write'],
        ];

        foreach ($expectations as $file => $permissions) {
            $source = (string) file_get_contents($root . '/portal/' . $file);
            foreach ($permissions as $permission) {
                self::assertStringContainsString("can('" . $permission . "'", $source, $file . ' guard for ' . $permission);
            }
        }
    }

    /**
     * A write permission literal must appear before the first `$action ===`
     * dispatch, so the POST envelope is gated up front for every action branch
     * rather than only inside individual branches (regression guard for a
     * missing guard on the vms.php reset_mecm_id action).
     */
    public function testWriteHandlersGuardBeforeActionDispatch(): void
    {
        $root = dirname(__DIR__, 2);
        $expectations = [
            'credentials.php' => ['credentials.manage'],
            'deploy.php' => ['deploy.run'],
            'mission_details.php' => ['missions.write'],
            'missions.php' => ['missions.write'],
            'os.php' => ['catalog.write'],
            'settings.php' => ['system.config'],
            'users.php' => ['users.manage'],
            'vlans.php' => ['catalog.write'],
            'vms.php' => ['vms.write'],
        ];

        foreach ($expectations as $file => $permissions) {
            $source = (string) file_get_contents($root . '/portal/' . $file);
            $dispatchPos = strpos($source, '$action === ');
            if ($dispatchPos === false) {
                continue;
            }

            $guarded = false;
            foreach ($permissions as $permission) {
                $guardPos = strpos($source, "can('" . $permission . "'");
                if ($guardPos !== false && $guardPos < $dispatchPos) {
                    $guarded = true;
                    break;
                }
            }

            self::assertTrue($guarded, $file . ' must gate its write permission before the first $action dispatch');
        }
    }
}
