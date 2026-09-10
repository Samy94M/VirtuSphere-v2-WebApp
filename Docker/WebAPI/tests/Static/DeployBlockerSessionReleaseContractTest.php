<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Guards the point at which the read-only live endpoint releases its session. */
final class DeployBlockerSessionReleaseContractTest extends TestCase
{
    public function testAllSessionAuthAndRbacWorkPrecedesTheCloseAndNoSessionReadFollowsIt(): void
    {
        $root = dirname(__DIR__, 2);
        $endpoint = (string) file_get_contents($root . '/portal/deploy_blockers.php');
        $bootstrap = (string) file_get_contents($root . '/lib/bootstrap.php');

        $currentUser = $this->position($endpoint, 'current_user($connection)');
        $mustChange = $this->position($endpoint, '$mustChangePassword =');
        $permission = $this->position($endpoint, "can('deploy.run', \$user)");
        $close = $this->position($endpoint, 'session_write_close();');
        $blockerRead = $this->position($endpoint, '$blockers = deploy_queue_blockers($connection, $state);');

        self::assertTrue($currentUser < $mustChange);
        self::assertTrue($mustChange < $permission);
        self::assertTrue($permission < $close);
        self::assertTrue($close < $blockerRead);

        $afterClose = substr($endpoint, $close + strlen('session_write_close();'));
        self::assertStringNotContainsString('$_SESSION', $afterClose);
        self::assertStringNotContainsString('current_user(', $afterClose);
        self::assertStringNotContainsString("can('", $afterClose);
        self::assertStringContainsString('Lang::load(__locale_resolve());', $bootstrap, 'locale persistence must finish before the endpoint code runs');
    }

    private function position(string $source, string $needle): int
    {
        $position = strpos($source, $needle);
        self::assertNotFalse($position, 'missing session contract fragment: ' . $needle);

        return (int) $position;
    }
}
