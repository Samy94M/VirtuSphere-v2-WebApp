<?php

declare(strict_types=1);

use phpseclib3\Net\SFTP;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/ssh_sftp.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_worker_runtime.php';

final class SshSftpBudgetTest extends TestCase
{
    public function testTransportTypesStayRuntimeCompatibleButNeverBecomeCancel(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/ssh_transport_exceptions.php');
        foreach (['SshTransportBudgetExceeded', 'SftpTransportFailed', 'SshTransportConfigurationException'] as $class) {
            self::assertStringContainsString('final class ' . $class . ' extends RuntimeException', $source);
        }
        self::assertStringNotContainsString('extends DeployWorkerCancelled', $source);
    }

    public function testLocalPrerequisitesUseTheConfigurationTypeBeforeNetworkIo(): void
    {
        try {
            ssh_sftp_probe(['host' => '', 'username' => ''], 'secret');
            self::fail('an empty SFTP endpoint passed');
        } catch (SshTransportConfigurationException $exception) {
            self::assertStringContainsString('host and username', $exception->getMessage());
        }

        try {
            ssh_sftp_upload_directory(
                ['host' => 'ansible.invalid', 'username' => 'worker'],
                'secret',
                sys_get_temp_dir() . '/vs-missing-' . bin2hex(random_bytes(4)),
                '/tmp/target'
            );
            self::fail('a vanished local work directory passed');
        } catch (SshTransportConfigurationException $exception) {
            self::assertStringContainsString('Local deploy work directory', $exception->getMessage());
        }
    }

    public function testLoginFalseAndLoginExceptionAreSftpFailures(): void
    {
        $falseLogin = $this->createStub(SFTP::class);
        $falseLogin->method('login')->willReturn(false);
        try {
            ssh_sftp_login($falseLogin, 'worker', 'secret', 'login failed');
            self::fail('a false SFTP login passed');
        } catch (SftpTransportFailed $exception) {
            self::assertNull($exception->getPrevious());
        }

        $cause = new UnexpectedValueException('unexpected login packet');
        $throwingLogin = $this->createStub(SFTP::class);
        $throwingLogin->method('login')->willThrowException($cause);
        try {
            ssh_sftp_login($throwingLogin, 'worker', 'secret', 'login failed');
            self::fail('a throwing SFTP login passed');
        } catch (SftpTransportFailed $exception) {
            self::assertSame($cause, $exception->getPrevious());
        }
    }

    public function testOperationTimeoutFalseBecomesTheBudgetTypeBeforeCleanup(): void
    {
        $sftp = $this->createMock(SFTP::class);
        $sftp->expects(self::once())->method('setTimeout')->with((float) VIRTUSPHERE_SFTP_OP_TIMEOUT_SECONDS);
        $sftp->expects(self::once())->method('isTimeout')->willReturn(true);
        $sftp->expects(self::never())->method('disconnect');

        $this->expectException(SshTransportBudgetExceeded::class);
        $this->expectExceptionMessage('operation time budget');
        ssh_sftp_run_operation(
            $sftp,
            'upload fixture.yml',
            'upload failed',
            static fn (): bool => false,
            0.0,
            'total budget',
            $this->clock(0.0)
        );
    }

    public function testRemainingTotalBudgetCapsTheOperationWithoutRoundingToZero(): void
    {
        $sftp = $this->createMock(SFTP::class);
        $sftp->expects(self::once())->method('setTimeout')->with(0.25);
        $sftp->expects(self::once())->method('isTimeout')->willReturn(true);

        $this->expectException(SshTransportBudgetExceeded::class);
        $this->expectExceptionMessage('SFTP upload exceeded the total time budget');
        ssh_sftp_run_operation(
            $sftp,
            'upload fixture.yml',
            'upload failed',
            static fn (): bool => false,
            0.0,
            'SFTP upload exceeded the total time budget',
            $this->clock(VIRTUSPHERE_SFTP_TOTAL_TIMEOUT_SECONDS - 0.25)
        );
    }

    public function testExpiredBudgetStopsImmediatelyBeforeTheOperation(): void
    {
        $called = false;
        $sftp = $this->createMock(SFTP::class);
        $sftp->expects(self::never())->method('setTimeout');

        try {
            ssh_sftp_run_operation(
                $sftp,
                'upload fixture.yml',
                'upload failed',
                static function () use (&$called): bool {
                    $called = true;
                    return true;
                },
                0.0,
                'total expired',
                $this->clock((float) VIRTUSPHERE_SFTP_TOTAL_TIMEOUT_SECONDS)
            );
            self::fail('an expired total budget returned successfully');
        } catch (SshTransportBudgetExceeded $exception) {
            self::assertSame('total expired', $exception->getMessage());
        }

        self::assertFalse($called, 'the remote operation ran after remaining <= 0');
    }

    public function testSuccessfulOperationCannotCrossTheTotalBudgetAfterwards(): void
    {
        $sftp = $this->createMock(SFTP::class);
        $sftp->expects(self::once())->method('setTimeout')->with(1.0);

        $this->expectException(SshTransportBudgetExceeded::class);
        $this->expectExceptionMessage('expired directly after operation');
        ssh_sftp_run_operation(
            $sftp,
            'delete probe file',
            'delete failed',
            static fn (): bool => true,
            0.0,
            'expired directly after operation',
            $this->clock(
                VIRTUSPHERE_SFTP_TOTAL_TIMEOUT_SECONDS - 1.0,
                VIRTUSPHERE_SFTP_TOTAL_TIMEOUT_SECONDS
            )
        );
    }

    public function testLegitimateFalseIsReturnedOnlyWhenItWasNotATimeout(): void
    {
        $sftp = $this->createMock(SFTP::class);
        $sftp->expects(self::once())->method('setTimeout');
        $sftp->expects(self::once())->method('isTimeout')->willReturn(false);
        $sftp->method('is_dir')->willReturn(false);

        $result = ssh_sftp_run_operation(
            $sftp,
            'inspect missing directory',
            'inspect failed',
            static fn (): bool => $sftp->is_dir('/missing'),
            0.0,
            'total budget',
            $this->clock(0.0, 1.0),
            true
        );

        self::assertFalse($result);
    }

    public function testNonTimeoutFalseBecomesSftpFailure(): void
    {
        $sftp = $this->createStub(SFTP::class);
        $sftp->method('isTimeout')->willReturn(false);

        $this->expectException(SftpTransportFailed::class);
        $this->expectExceptionMessage('permission denied');
        ssh_sftp_run_operation(
            $sftp,
            'create directory',
            'permission denied',
            static fn (): bool => false,
            0.0,
            'total budget',
            $this->clock(0.0)
        );
    }

    public function testNonTimeoutThrowableIsWrappedAndPreservedAsPrevious(): void
    {
        $cause = new UnexpectedValueException('unexpected packet type');
        $sftp = $this->createStub(SFTP::class);
        $sftp->method('isTimeout')->willReturn(false);

        try {
            ssh_sftp_run_operation(
                $sftp,
                'upload fixture.yml',
                'upload failed',
                $this->failingOperation($cause),
                0.0,
                'total budget',
                $this->clock(0.0)
            );
            self::fail('the foreign SFTP exception returned successfully');
        } catch (SftpTransportFailed $exception) {
            self::assertSame($cause, $exception->getPrevious());
            self::assertSame('upload failed', $exception->getMessage());
        }
    }

    public function testTimeoutThrowableBecomesBudgetAndPreservesPrevious(): void
    {
        $cause = new UnexpectedValueException('expected packet missing');
        $sftp = $this->createStub(SFTP::class);
        $sftp->method('isTimeout')->willReturn(true);

        try {
            ssh_sftp_run_operation(
                $sftp,
                'upload fixture.yml',
                'upload failed',
                $this->failingOperation($cause),
                0.0,
                'total budget',
                $this->clock(0.0)
            );
            self::fail('the timed-out SFTP exception returned successfully');
        } catch (SshTransportBudgetExceeded $exception) {
            self::assertSame($cause, $exception->getPrevious());
        }
    }

    /** @return Closure():float */
    private function clock(float ...$values): Closure
    {
        self::assertNotSame([], $values);
        $index = 0;
        return static function () use (&$index, $values): float {
            $position = min($index, count($values) - 1);
            $index++;
            return $values[$position];
        };
    }

    /** @return Closure():bool */
    private function failingOperation(Throwable $cause): Closure
    {
        return static function () use ($cause): bool {
            throw $cause;
        };
    }
}
