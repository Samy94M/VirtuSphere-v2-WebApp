<?php

declare(strict_types=1);

use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/ssh.php';

final class SshHostKeyTest extends TestCase
{
    public function testForeignHostKeyNeverReceivesThePassword(): void
    {
        $sftp = $this->transport(SFTP::class);
        $sftp->expects(self::never())->method('login');
        try {
            ssh_sftp_login($sftp, 'worker', 'password-fixture', 'login failed', $this->credential(),
                static function (array $snapshot, array $observed): void {
                    throw new SshHostIdentityRejected('mismatch', $observed);
                });
            self::fail('A foreign host key reached authentication.');
        } catch (SshHostIdentityRejected $exception) {
            self::assertSame('mismatch', $exception->reason);
            self::assertStringNotContainsString('password-fixture', $exception->getMessage());
        }
    }

    public function testNewUnconfirmedHostNeverReceivesThePassword(): void
    {
        $ssh = $this->transport(SSH2::class);
        $ssh->expects(self::never())->method('login');
        $this->expectException(SshHostIdentityRejected::class);
        $this->expectExceptionMessage('administrator confirmation');
        ssh_verified_login($ssh, 'worker', 'secret-fixture', $this->credential(),
            static function (array $snapshot, array $observed): void {
                throw new SshHostIdentityRejected('unconfirmed', $observed);
            });
    }

    public function testLoginFollowsSuccessfulDurableVerification(): void
    {
        $events = [];
        $ssh = $this->transport(SSH2::class);
        $ssh->expects(self::once())->method('login')->with('worker', 'secret-fixture')
            ->willReturnCallback(static function () use (&$events): bool {
                self::assertSame(['durable verification'], $events);
                $events[] = 'login';
                return true;
            });
        self::assertTrue(ssh_verified_login($ssh, 'worker', 'secret-fixture', $this->credential(),
            static function () use (&$events): void { $events[] = 'durable verification'; }));
        self::assertSame(['durable verification', 'login'], $events);
    }

    public function testStorageFailureCannotReachAuthentication(): void
    {
        $ssh = $this->transport(SSH2::class);
        $ssh->expects(self::never())->method('login');
        $this->expectException(mysqli_sql_exception::class);
        ssh_verified_login($ssh, 'worker', 'secret-fixture', $this->credential(),
            static function (): void { throw new mysqli_sql_exception('storage unavailable'); });
    }

    public function testMalformedServerKeyCannotReachVerificationOrLogin(): void
    {
        $ssh = $this->createMock(SSH2::class);
        $ssh->method('getServerPublicHostKey')->willReturn(false);
        $ssh->expects(self::never())->method('login');
        $this->expectException(SshHostIdentityRejected::class);
        ssh_verified_login($ssh, 'worker', 'secret-fixture', $this->credential(),
            static function (): void { self::fail('Malformed key reached the trust owner.'); });
    }

    public function testInternalReloginUsesTheGuardAgain(): void
    {
        $ssh = new HostIdentityReplayProbe();
        $ssh->publicKey = self::publicKey('a');
        $pin = null;
        $verify = static function (array $snapshot, array $observed) use (&$pin): void {
            if ($pin === null) { $pin = $observed['fingerprint']; }
            if ($pin !== $observed['fingerprint']) { throw new SshHostIdentityRejected('mismatch', $observed, $pin); }
        };
        self::assertTrue(ssh_verified_login($ssh, 'worker', 'secret-fixture', $this->credential(), $verify));
        self::assertSame(1, $ssh->attempts);
        $ssh->publicKey = self::publicKey('b');
        try {
            $ssh->login('worker', 'secret-fixture'); // Same virtual dispatch as phpseclib reconnect().
            self::fail('A relogin to another host key passed.');
        } catch (SshHostIdentityRejected $exception) {
            self::assertSame('reconnect_unverified', $exception->reason);
            self::assertSame(1, $ssh->attempts);
        }
    }

    public function testNegotiatedRsaSha2NamesDoNotChangeTheFingerprintOrKeyType(): void
    {
        $wire = pack('N', 7) . 'ssh-rsa' . pack('N', 3) . 'abc';
        $ssh = $this->createStub(SSH2::class);
        $ssh->method('getServerPublicHostKey')->willReturn('rsa-sha2-512 ' . base64_encode($wire));
        $rsa = $this->createStub(SSH2::class);
        $rsa->method('getServerPublicHostKey')->willReturn('ssh-rsa ' . base64_encode($wire));
        self::assertSame(ssh_host_identity($rsa), ssh_host_identity($ssh));
        self::assertSame('ssh-rsa', ssh_host_identity($ssh)['type']);
    }

    public function testHostIdentityKeepsItsClosedCategoryThroughTheSftpClassifier(): void
    {
        $exception = new SshHostIdentityRejected('mismatch');
        self::assertSame(VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_HOST_IDENTITY, ansible_connection_error_category($exception));
        self::assertTrue(ansible_connection_error_is_typed($exception));
        self::assertSame(VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_HOST_IDENTITY, credential_test_sftp_failure($exception, '')['code']);
    }

    public function testVendorConnectionResetRetainsTheSignatureValidationCache(): void
    {
        $cache = new ReflectionProperty(SSH2::class, 'signature_validated');
        foreach ([SSH2::class, SFTP::class] as $class) {
            $transport = new $class('fixture.invalid');
            self::assertFalse($cache->getValue($transport));
            $cache->setValue($transport, true);
            (new ReflectionMethod($class, 'reset_connection'))->invoke($transport);
            self::assertTrue($cache->getValue($transport), $class . ' reset invalidates the reviewed cache assumption');
        }
    }

    /** @return array<string,mixed> */
    private function credential(): array
    {
        return ['id' => 1, 'type' => 'ansible', 'host' => 'fixture.invalid', 'port' => 22, 'username' => 'worker', 'config_revision' => 1];
    }

    /**
     * @template T of SSH2
     * @param class-string<T> $class
     * @return T&PHPUnit\Framework\MockObject\MockObject
     */
    private function transport(string $class): SSH2
    {
        $transport = $this->createMock($class);
        $transport->method('getServerPublicHostKey')->willReturn(self::publicKey('b'));
        return $transport;
    }

    public static function publicKey(string $byte): string
    {
        return 'ssh-ed25519 ' . base64_encode(pack('N', 11) . 'ssh-ed25519' . pack('N', 32) . str_repeat($byte, 32));
    }
}

class HostIdentityReplayParent extends SSH2
{
    public string $publicKey = '';
    public int $attempts = 0;
    public function __construct() { }
    public function __destruct() { }
    public function getServerPublicHostKey() { return $this->publicKey; }
    public function login($username, ...$args) { ++$this->attempts; return true; }
}

final class HostIdentityReplayProbe extends HostIdentityReplayParent implements VirtuSphereHostIdentityConnection
{
    use VirtuSphereHostIdentityGuard;
}
