<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The CSRF check that opens every POST handler is shared in
 * portal_guard_post() (lib/audit_events.php) so a new page cannot forget it or
 * pass the wrong page name. This pins the convention: the standard pages call
 * the helper and no longer hand-roll csrf_verify(); the three pages with their
 * own response contract (login soft-redirect, logout custom body, session_ping
 * JSON) keep doing their own check and must NOT be "fixed" to use the helper.
 */
final class PortalPostGuardContractTest extends TestCase
{
    private const GUARDED = [
        'deploy.php', 'settings.php', 'users.php', 'credentials.php', 'os.php',
        'vlans.php', 'vms.php', 'missions.php', 'vm_edit.php', 'system_status.php',
        'mission_details.php', 'account.php',
    ];

    private const EXEMPT = ['login.php', 'logout.php', 'session_ping.php'];

    private function source(string $page): string
    {
        $path = str_replace('\\', '/', dirname(__DIR__, 2)) . '/portal/' . $page;
        self::assertFileExists($path, $page . ' must exist');

        return (string) file_get_contents($path);
    }

    public function testGuardedPagesUseTheSharedHelper(): void
    {
        foreach (self::GUARDED as $page) {
            $source = $this->source($page);
            self::assertStringContainsString(
                'portal_guard_post(',
                $source,
                $page . ' must guard its POST via portal_guard_post()'
            );
            self::assertStringNotContainsString(
                'csrf_verify(',
                $source,
                $page . ' should let portal_guard_post() run the CSRF check, not hand-roll csrf_verify()'
            );
        }
    }

    public function testExemptPagesKeepTheirOwnContract(): void
    {
        foreach (self::EXEMPT as $page) {
            $source = $this->source($page);
            self::assertStringContainsString(
                'csrf_verify(',
                $source,
                $page . ' has its own CSRF response contract and must keep its explicit check'
            );
            self::assertStringNotContainsString(
                'portal_guard_post(',
                $source,
                $page . ' must not adopt the shared guard; its response contract differs'
            );
        }
    }
}
