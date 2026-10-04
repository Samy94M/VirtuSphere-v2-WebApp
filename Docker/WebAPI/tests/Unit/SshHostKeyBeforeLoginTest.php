<?php

declare(strict_types=1);

use phpseclib3\Net\SFTP;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/ssh.php';

/** Portable counterexample: this exact file also runs on the pre-K1 source. */
final class SshHostKeyBeforeLoginTest extends TestCase
{
    public function testForeignHostKeyIsRejectedBeforeAnyPasswordLogin(): void
    {
        $sftp = $this->createMock(SFTP::class);
        $sftp->method('getServerPublicHostKey')->willReturn('ssh-ed25519 '
            . base64_encode(pack('N', 11) . 'ssh-ed25519' . pack('N', 32) . str_repeat('x', 32)));
        $sftp->expects(self::never())->method('login');
        try {
            ssh_sftp_login($sftp, 'worker', 'password-fixture', 'login failed',
                ['id' => 1, 'type' => 'ansible', 'host' => 'fixture.invalid', 'port' => 22, 'username' => 'worker'],
                static function (array $snapshot, array $observed): void {
                    throw new SshHostIdentityRejected('mismatch', $observed);
                });
            self::fail('A foreign host reached authentication.');
        } catch (Throwable $exception) {
            self::assertSame('mismatch', $exception instanceof SshHostIdentityRejected ? $exception->reason : $exception->getMessage());
            self::assertStringNotContainsString('password-fixture', $exception->getMessage());
        }
    }
}
