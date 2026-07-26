<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/deploy_page.php';

/**
 * The deploy page's blocker boxes. Each one states a prerequisite the operator
 * cannot satisfy on this page, so each one has to name the page that satisfies
 * it; a box without a route is what this helper replaced.
 *
 * The permission is the target page's, not the deploy page's, and it is data
 * rather than markup so it can be checked against the permission list instead
 * of against a hand-copied string.
 */
final class DeployPrerequisiteNoticesTest extends TestCase
{
    public function testNothingIsReportedWhenEveryPrerequisiteIsMet(): void
    {
        self::assertSame([], deploy_prerequisite_notices(true, true, true, true, ''));
    }

    public function testEachMissingPrerequisiteProducesExactlyOneNotice(): void
    {
        self::assertCount(1, deploy_prerequisite_notices(false, true, true, true, ''));
        self::assertCount(1, deploy_prerequisite_notices(true, false, true, true, ''));
        self::assertCount(1, deploy_prerequisite_notices(true, true, false, true, ''));
        self::assertCount(1, deploy_prerequisite_notices(true, true, true, false, 'no base url'));
        self::assertCount(4, deploy_prerequisite_notices(false, false, false, false, 'no base url'));
    }

    /**
     * The point of the box: a sentence alone leaves the operator to find the
     * page, which is the defect. Every notice carries a target and a label, and
     * neither may be a key echoing itself (a missing catalog entry).
     */
    public function testEveryNoticeCarriesATargetAndATranslatedLabel(): void
    {
        foreach (deploy_prerequisite_notices(false, false, false, false, 'no base url') as $notice) {
            self::assertNotSame('', $notice['url']);
            self::assertNotSame('', $notice['label']);
            self::assertStringNotContainsString('deploy.', $notice['label'], 'untranslated key');
            self::assertStringNotContainsString('deploy.', $notice['message'], 'untranslated key');
        }
    }

    /**
     * A link the user may not follow is worse than none, so the caller gates it;
     * that only works while the value is a permission the RBAC layer knows. An
     * empty string means the target is open to every signed-in user.
     */
    public function testPermissionsAreRealPermissions(): void
    {
        foreach (deploy_prerequisite_notices(false, false, false, false, 'no base url') as $notice) {
            if ($notice['permission'] === '') {
                continue;
            }
            self::assertContains($notice['permission'], VIRTUSPHERE_PERMISSIONS, $notice['url']);
        }
    }

    /**
     * settings.php falls back to its first tab when the fragment is missing, so
     * a link to a field in another tab lands on the wrong panel and the operator
     * reads the box as wrong rather than the link.
     */
    public function testSettingsLinkNamesItsTab(): void
    {
        $notices = deploy_prerequisite_notices(true, true, true, false, 'no base url');
        self::assertSame('settings.php#panel-deploy', $notices[0]['url']);
        self::assertSame('system.config', $notices[0]['permission']);
    }

    /**
     * The resolver's message already separates "not set in the portal" from
     * "not set in the .env", so the notice must not replace it with a generic
     * sentence of its own.
     */
    public function testApiBaseUrlNoticeKeepsTheResolverMessage(): void
    {
        $notices = deploy_prerequisite_notices(true, true, true, false, 'resolver said this');
        self::assertSame('resolver said this', $notices[0]['message']);
    }

    /**
     * The flag decides, not the string. deploy.php reads the message out of a
     * caught exception, and an exception with an empty message would otherwise
     * drop the box while the gate below still blocks the queue: a disabled
     * button and nothing on screen saying why.
     */
    public function testAnUnexplainedApiBaseUrlFailureStillProducesABox(): void
    {
        $notices = deploy_prerequisite_notices(true, true, true, false, '');
        self::assertCount(1, $notices);
        self::assertNotSame('', $notices[0]['message']);
        self::assertStringNotContainsString('settings.', $notices[0]['message'], 'untranslated key');
    }
}
