<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/layout.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_mission_busy_exception.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_create_progress_actions.php';

final class PortalActionableErrorTest extends TestCase
{
    protected function setUp(): void
    {
        Lang::load('de');
    }

    public function testBusyJobFlashRendersItsLogWithoutJavaScriptAndChecksPermission(): void
    {
        $error = new DeployMissionBusyException('Mission has an active deploy job.', 42);
        $user = ['role' => VIRTUSPHERE_ROLE_USER];
        $action = portal_error_action($error, $user);
        self::assertSame(['url' => deploy_job_log_url(42), 'label' => __t('deploy.flash_open_job_log')], $action);
        self::assertNull(portal_error_action($error, ['role' => 'no_permissions']));
        $_SESSION['_flash'] = [];
        flash_portal_error($error, $user);
        $flash = flash_messages()[0];
        self::assertStringContainsString('href="' . deploy_job_log_url(42) . '"', flash_alert_html($flash));
        self::assertSame(portal_error_message($error), $flash['message']);
        flash_portal_error($error, ['role' => 'no_permissions']);
        self::assertStringNotContainsString('href=', flash_alert_html(flash_messages()[0]));
    }

    public function testValidationActionUsesTheTargetsPermissionAndRejectsExternalUrls(): void
    {
        $action = ['url' => system_status_url(VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_MECM), 'label_key' => 'layout.nav_system_status', 'permission' => 'system.config'];
        $error = new ValidationException([], 'Check MECM', $action);
        self::assertNull(portal_error_action($error, ['role' => VIRTUSPHERE_ROLE_USER]));
        self::assertSame(['url' => $action['url'], 'label' => __t($action['label_key'])], portal_error_action($error, ['role' => VIRTUSPHERE_ROLE_ADMIN]));
        $action['url'] = 'https://external.example/';
        self::assertNull(portal_error_action(new ValidationException([], 'Check', $action), ['role' => VIRTUSPHERE_ROLE_ADMIN]));
        self::assertNull(portal_error_action(new ValidationException([], 'Field error'), ['role' => VIRTUSPHERE_ROLE_ADMIN]));
        self::assertNull(portal_error_action(new RuntimeException('Other error'), ['role' => VIRTUSPHERE_ROLE_ADMIN]));
    }

    public function testReplacedVmActionUsesItsExactIdentityAndWritePermission(): void
    {
        $replacement = ['vm_id' => 17];
        self::assertSame(['url' => vm_edit_url(9, 17), 'label' => __t('deploy.create_progress_link_vm')], deploy_create_progress_replacement_action($replacement, 9, ['role' => VIRTUSPHERE_ROLE_USER]));
        self::assertNull(deploy_create_progress_replacement_action($replacement, 9, ['role' => 'no_permissions']));
        self::assertNull(deploy_create_progress_replacement_action(['vm_id' => 0], 9, ['role' => VIRTUSPHERE_ROLE_USER]));
    }
}
