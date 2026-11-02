<?php

declare(strict_types=1);

use phpseclib3\Exception\ConnectionClosedException;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/ssh.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_worker_outcome.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_worker_mission.php';
require_once dirname(__DIR__, 2) . '/lib/layout_response.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_terminal_presenter.php';
require_once dirname(__DIR__, 2) . '/lib/audit_presenter.php';

/**
 * U-01 (user decision 30.10.2026): a lost SSH connection to the Ansible host
 * while a playbook step runs ends the job `outcome_unknown` with reason
 * `ansible_connection_lost`. Measured 01.11.2026: phpseclib reports the loss as
 * ConnectionClosedException from read(), and in the black-hole case the step
 * finished on the host although the worker saw only the loss. Everything that
 * is not that exact type in that exact phase stays `failed`.
 */
final class DeployAnsibleConnectionLostTest extends TestCase
{
    protected function tearDown(): void
    {
        Lang::load(Lang::DEFAULT_LOCALE);
    }

    public function testALostConnectionWhileReadingBecomesTheTypedTransportLoss(): void
    {
        $logged = '';
        $slices = ['TASK [create] ***', new ConnectionClosedException('Connection closed by server')];
        $index = 0;
        try {
            ssh_stream_command_output(static function () use (&$index, $slices) {
                $slice = $slices[$index++];
                if ($slice instanceof Throwable) {
                    throw $slice;
                }

                return $slice;
            }, static function (string $chunk) use (&$logged): void {
                $logged .= $chunk;
            }, null, 60, 600, static fn (): int => 1000);
            self::fail('A lost connection ended the read loop as if the channel had closed.');
        } catch (SshTransportConnectionLost $exception) {
            self::assertInstanceOf(ConnectionClosedException::class, $exception->getPrevious());
            self::assertStringContainsString('Connection closed by server', $exception->getMessage());
        }
        self::assertSame('TASK [create] ***', $logged, 'output read before the loss still reaches the log');
    }

    public function testOtherReadFailuresKeepTheirOwnType(): void
    {
        try {
            ssh_stream_command_output(static function (): never {
                throw new RuntimeException('Error reading channel data (94)');
            }, static function (): void {
            }, null, 60, 600, static fn (): int => 1000);
            self::fail('The read failure vanished.');
        } catch (RuntimeException $exception) {
            self::assertNotInstanceOf(SshTransportConnectionLost::class, $exception);
        }
    }

    public function testOnlyALostConnectionDuringARemoteStepNamesTheNewReason(): void
    {
        $lost = new SshTransportConnectionLost('lost');
        self::assertSame(VIRTUSPHERE_DEPLOY_TERMINAL_REASON_ANSIBLE_CONNECTION_LOST, deploy_terminal_reason_for_exception($lost, true));
        // Host preflight and inventory pulls read only: no step was in flight.
        self::assertSame(VIRTUSPHERE_DEPLOY_TERMINAL_REASON_EXECUTION_FAILED, deploy_terminal_reason_for_exception($lost));
        self::assertSame(VIRTUSPHERE_DEPLOY_TERMINAL_REASON_TIMEOUT, deploy_terminal_reason_for_exception(new SshTransportBudgetExceeded('idle'), true));
        self::assertSame(VIRTUSPHERE_DEPLOY_TERMINAL_REASON_EXECUTION_FAILED, deploy_terminal_reason_for_exception(new RuntimeException('SSH transport closed without a command exit status.'), true));
        // phpseclib's own type without the transport's translation is no evidence.
        self::assertSame(VIRTUSPHERE_DEPLOY_TERMINAL_REASON_EXECUTION_FAILED, deploy_terminal_reason_for_exception(new ConnectionClosedException('Connection closed by server'), true));
    }

    public function testTheFailedStepIsAddedWithoutLosingTheType(): void
    {
        $wrapped = deploy_worker_transport_failure_with_step(new SshTransportConnectionLost('lost'), 'export.yml');
        self::assertInstanceOf(SshTransportConnectionLost::class, $wrapped);
    }

    public function testTheReasonBelongsOnlyToTheUnknownOutcome(): void
    {
        self::assertSame(
            [VIRTUSPHERE_DEPLOY_STATUS_OUTCOME_UNKNOWN],
            VIRTUSPHERE_DEPLOY_TERMINAL_REASON_STATUSES[VIRTUSPHERE_DEPLOY_TERMINAL_REASON_ANSIBLE_CONNECTION_LOST]
        );
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_OUTCOME_UNKNOWN, deploy_terminal_status_for_failure_reason(VIRTUSPHERE_DEPLOY_TERMINAL_REASON_ANSIBLE_CONNECTION_LOST));
        foreach ([VIRTUSPHERE_DEPLOY_TERMINAL_REASON_EXECUTION_FAILED, VIRTUSPHERE_DEPLOY_TERMINAL_REASON_TIMEOUT] as $reason) {
            self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_FAILED, deploy_terminal_status_for_failure_reason($reason));
        }
        $this->expectException(InvalidArgumentException::class);
        deploy_terminal_reason_assert(VIRTUSPHERE_DEPLOY_STATUS_FAILED, VIRTUSPHERE_DEPLOY_TERMINAL_REASON_ANSIBLE_CONNECTION_LOST);
    }

    public function testTheTransportCategoryIsDecidedByType(): void
    {
        $lost = new SshTransportConnectionLost('permission denied');
        self::assertTrue(ansible_connection_error_is_typed($lost));
        self::assertSame(VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_TRANSPORT, ansible_connection_error_category($lost));
    }

    public function testThePortalSaysWhatHappenedAndKeepsTheRawTextInTheDetail(): void
    {
        $detail = 'SSH connection to the Ansible host was lost while the remote command ran: Connection closed by server (failed step: export.yml)';
        foreach (['de', 'en'] as $locale) {
            Lang::load($locale);
            $view = deploy_terminal_presenter([
                'id' => 9,
                'status' => VIRTUSPHERE_DEPLOY_STATUS_OUTCOME_UNKNOWN,
                'last_error' => $detail,
                'terminal_reason_code' => VIRTUSPHERE_DEPLOY_TERMINAL_REASON_ANSIBLE_CONNECTION_LOST,
                'terminal_reason_detail' => $detail,
                'payload_json' => '{"mode":"full","vm_ids":[]}',
            ]);

            self::assertIsArray($view['result']);
            self::assertSame(__t('deploy.result_outcome_unknown_connection_lost'), $view['result']['text']);
            self::assertStringNotContainsString('Connection closed', $view['result']['text']);
            self::assertIsArray($view['reason']);
            self::assertSame(__t('deploy.terminal_reason_ansible_connection_lost'), $view['reason']['text']);
            self::assertSame($detail, $view['reason']['detail']);
            self::assertNull($view['last_error']);
        }
    }

    public function testTheAuditReasonIsAClosedWord(): void
    {
        self::assertNotSame('ansible_connection_lost', audit_code_label('de', 'deploy_reason', 'ansible_connection_lost'));
    }
}
