<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * LG-09 / LG-10 / HA-13 / HA-23 / HA-24 / HA-14: the state change of a listed action and
 * the audit row that records it are one transaction, and that row is written with
 * audit_event_required(). An unchecked audit_event() after the commit is how a change
 * ends up applied with no trail when the insert is the thing that fails.
 *
 * Source-level pins, relative to Docker/WebAPI. Behaviour is covered by the integration
 * tests of the repositories these actions call.
 */
final class AuditTransactionOwnershipContractTest extends TestCase
{
    /** file => event constants whose row must be written required inside a repo_transaction() */
    private const REQUIRED_IN_TRANSACTION = [
        'portal/vms.php' => ['VIRTUSPHERE_AUDIT_EVENT_VM_CHANGED', 'VIRTUSPHERE_AUDIT_EVENT_VM_BULK_CHANGED'],
        'portal/missions.php' => ['VIRTUSPHERE_AUDIT_EVENT_MISSION_CHANGED'],
        'portal/os.php' => ['VIRTUSPHERE_AUDIT_EVENT_CATALOG_ITEM_DELETED'],
        'portal/vlans.php' => ['VIRTUSPHERE_AUDIT_EVENT_CATALOG_ITEM_DELETED'],
        'lib/system_status_page.php' => ['VIRTUSPHERE_AUDIT_EVENT_DEPLOY_INVENTORY_REFRESH', 'VIRTUSPHERE_AUDIT_EVENT_DEPLOY_VLAN_REASSIGNED'],
        'lib/system_status_service_actions.php' => [
            'VIRTUSPHERE_AUDIT_EVENT_DEPLOY_CLAIM_PAUSED',
            'VIRTUSPHERE_AUDIT_EVENT_DEPLOY_CLAIM_RESUMED',
            'VIRTUSPHERE_AUDIT_EVENT_DEPLOY_RECOVERY_REVIEWED',
        ],
        'lib/deploy_recovery_actions.php' => [
            'VIRTUSPHERE_AUDIT_EVENT_DEPLOY_CLEANUP_RETRIED',
            'VIRTUSPHERE_AUDIT_EVENT_DEPLOY_RECOVERY_DOCUMENTED',
            'VIRTUSPHERE_AUDIT_EVENT_DEPLOY_CREATE_RELEASED',
        ],
        'lib/vm_save_service.php' => ['VIRTUSPHERE_AUDIT_EVENT_VM_CHANGED'],
    ];

    private function code(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** @return list<int> offsets of the audit_event_required() calls that name $event */
    private function requiredCallsFor(string $code, string $event): array
    {
        preg_match_all('/\baudit_event_required\s*\(/', $code, $matches, PREG_OFFSET_CAPTURE);
        $offsets = [];
        foreach ($matches[0] as [, $offset]) {
            if (str_contains(substr($code, $offset, 260), $event)) {
                $offsets[] = $offset;
            }
        }

        return $offsets;
    }

    private function assertRequiredInsideTransaction(string $file, string $event): void
    {
        $code = $this->code($file);
        $offsets = $this->requiredCallsFor($code, $event);
        self::assertNotSame([], $offsets, sprintf('%s must write %s with audit_event_required() (zero-match guard)', $file, $event));

        foreach ($offsets as $offset) {
            $open = strrpos(substr($code, 0, $offset), 'repo_transaction(');
            self::assertIsInt($open, sprintf('%s: %s is written outside any repo_transaction()', $file, $event));
            self::assertFalse(
                strpos(substr($code, $open, $offset - $open), '});'),
                sprintf('%s: the transaction of %s closes before its audit row is written', $file, $event)
            );
            self::assertIsInt(strpos($code, '});', $offset), sprintf('%s: the transaction around %s never closes after it', $file, $event));
        }

        self::assertDoesNotMatchRegularExpression(
            '/\baudit_event\s*\([^;]{0,200}' . preg_quote($event, '/') . '/s',
            $code,
            sprintf('%s must not write %s with the unchecked audit_event()', $file, $event)
        );
    }

    public function testEachListedActionWritesItsRowRequiredInsideItsTransaction(): void
    {
        foreach (self::REQUIRED_IN_TRANSACTION as $file => $events) {
            foreach ($events as $event) {
                $this->assertRequiredInsideTransaction($file, $event);
            }
        }
    }

    /** HA-25: the bulk reset writes one row per VM, in that VM's own transaction, through the same helper as the single action. */
    public function testTheBulkResetWritesItsRowPerVmInsideThatVmsTransaction(): void
    {
        $code = $this->code('lib/repo/vms_mecm_reset.php');

        preg_match('/function repo_bulk_reset_mecm_ids\(.*?\n}\n/s', $code, $bulk);
        self::assertNotEmpty($bulk, 'repo_bulk_reset_mecm_ids() must exist (zero-match guard)');
        $transaction = strpos($bulk[0], 'repo_transaction(');
        $row = strpos($bulk[0], 'repo_audit_mecm_id_reset(');
        self::assertIsInt($transaction);
        self::assertIsInt($row);
        self::assertLessThan($row, $transaction, 'the reset and its row share the per-VM transaction');

        preg_match('/function repo_audit_mecm_id_reset\(.*?\n}\n/s', $code, $helper);
        self::assertNotEmpty($helper, 'the shared row helper must exist (zero-match guard)');
        self::assertStringContainsString('audit_event_required($db, VIRTUSPHERE_AUDIT_EVENT_VM_MECM_CHANGED', $helper[0]);
    }

    /** HA-13: the bulk summary row is required on both paths, as the single action's row is. */
    public function testTheBulkSummaryIsRequiredOnBothPaths(): void
    {
        $code = $this->code('portal/vms.php');

        self::assertSame(2, substr_count($code, 'audit_event_required($connection, VIRTUSPHERE_AUDIT_EVENT_VM_BULK_CHANGED'));
        self::assertStringNotContainsString('audit_event($connection, VIRTUSPHERE_AUDIT_EVENT_VM_BULK_CHANGED', $code);
    }

    /** HA-24: deletions and creations name what they changed, and the registry allows the key. */
    public function testDeletionsAndCreationsNameWhatTheyChanged(): void
    {
        self::assertStringContainsString("'name' => \$missionName", $this->code('portal/missions.php'));
        self::assertStringContainsString("['name' => \$name]", $this->code('portal/os.php'));
        self::assertStringContainsString("['name' => \$name]", $this->code('portal/vlans.php'));
        self::assertStringContainsString("'name' => \$vmName", $this->code('portal/vms.php'));
        self::assertStringContainsString('$auditContext[\'name\'] = $savedName;', $this->code('lib/vm_save_service.php'));

        $credentials = $this->code('lib/credentials_actions.php');
        self::assertStringContainsString("'host' => (string) (\$createdRow['host'] ?? '')", $credentials);
        self::assertStringContainsString("'host' => (string) (\$before['host'] ?? '')", $credentials);

        $definitions = $this->code('lib/audit_event_definitions.php');
        self::assertStringContainsString("[], ['name'], 'required'", $definitions, 'the catalog deletion event must allow the name');
        self::assertStringContainsString("'selection_cleared', 'name', 'host']", $definitions, 'the credential event must allow the name and the host');
        self::assertStringContainsString("['changes', 'name']", $definitions, 'the VM event must allow the name');
    }

    /** HA-14: the raw log download is recorded before its stream starts. */
    public function testTheRawLogDownloadIsAuditedBeforeItStreams(): void
    {
        $code = $this->code('portal/deploy_log.php');

        $audit = strpos($code, 'audit_event($connection, VIRTUSPHERE_AUDIT_EVENT_DEPLOY_LOG_EXPORTED');
        $stream = strpos($code, 'Content-Disposition: attachment; filename="\' . $filename');
        self::assertIsInt($audit, 'the raw download must write its audit row (zero-match guard)');
        self::assertIsInt($stream, 'the raw download header was not found (zero-match guard)');
        self::assertLessThan($stream, $audit, 'the row must exist before the first byte of the download');
    }
}
