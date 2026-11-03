<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/deploy_remote_secret.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_create_constants.php';

/**
 * What a removal of the ESXi access file confirmed (K21, VT-04). A row is
 * forgotten only after exit 0 AND a state line of the removal command: "removed"
 * when the file was there, "absent" when it was already gone. Exit 0 without a
 * state line is no confirmation, because `rm -f` also answers 0 for a path on a
 * host that never had the file.
 *
 * The lease of a status query outside the job keeps a concurrent sweep away from
 * its row. It must outlast the whole query: the upload, the status command and
 * the removal afterwards.
 */
final class DeployRemoteSecretStateTest extends TestCase
{
    /** @param array{exit_code: int, output: string} $result */
    #[DataProvider('results')]
    public function testOnlyExitZeroWithItsStateLineIsAConfirmation(array $result, ?string $expected): void
    {
        self::assertSame($expected, deploy_remote_secret_removal_state($result));
    }

    /** @return array<string, array{array{exit_code: int, output: string}, ?string}> */
    public static function results(): array
    {
        return [
            'removed' => [['exit_code' => 0, 'output' => "VIRTUSPHERE_REMOTE_SECRET removed\n"], 'removed'],
            'absent' => [['exit_code' => 0, 'output' => "VIRTUSPHERE_REMOTE_SECRET absent\n"], 'absent'],
            'state after banner noise' => [['exit_code' => 0, 'output' => "Last login: today\r\nVIRTUSPHERE_REMOTE_SECRET removed\r\n"], 'removed'],
            'exit zero without state' => [['exit_code' => 0, 'output' => ''], null],
            'state with a failed exit' => [['exit_code' => 1, 'output' => "VIRTUSPHERE_REMOTE_SECRET removed\n"], null],
            'unknown state word' => [['exit_code' => 0, 'output' => "VIRTUSPHERE_REMOTE_SECRET maybe\n"], null],
            'state inside a longer line' => [['exit_code' => 0, 'output' => "echo VIRTUSPHERE_REMOTE_SECRET removed\n"], null],
        ];
    }

    public function testAMissingExitCodeIsNoConfirmation(): void
    {
        self::assertNull(deploy_remote_secret_removal_state(['output' => "VIRTUSPHERE_REMOTE_SECRET removed\n"]));
    }

    public function testTheStatusQueryLeaseOutlastsUploadStatusCommandAndRemoval(): void
    {
        $query = 15 + VIRTUSPHERE_SFTP_OP_TIMEOUT_SECONDS + VIRTUSPHERE_SFTP_TOTAL_TIMEOUT_SECONDS
            + 15 + VIRTUSPHERE_CREATE_CONTROL_IDLE_TIMEOUT_SECONDS
            + 15 + VIRTUSPHERE_DEPLOY_REMOTE_CLEANUP_TIMEOUT_SECONDS;

        self::assertGreaterThan($query, VIRTUSPHERE_REMOTE_SECRET_STATUS_LEASE_SECONDS);
    }
}
