<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/repo/log.php';
require_once dirname(__DIR__, 2) . '/lib/repo/vm_history.php';

/**
 * The VM chronicle (SP3): the page decides the change-log permission before it
 * reads, reads only through its repository, and the audit writer indexes every
 * context that names VMs. Derived from the sources and the registry, never a list.
 */
final class VmHistoryContractTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    public function testThePageDecidesThePermissionBeforeItReadsAndHoldsNoSql(): void
    {
        $page = (string) file_get_contents(self::ROOT . '/portal/vm_history.php');

        $permission = strpos($page, "can('users.manage'");
        $read = strpos($page, 'repo_vm_history_page(');
        self::assertIsInt($permission);
        self::assertIsInt($read);
        self::assertLessThan($read, $permission, 'users.manage is decided before the first read');
        self::assertDoesNotMatchRegularExpression('/->(prepare|query)\(|\bSELECT\b/', $page, 'the page reads only through lib/repo/vm_history.php');
    }

    public function testTheAuditWriterIndexesThroughTheOneOwner(): void
    {
        $source = (string) file_get_contents(self::ROOT . '/lib/repo/log.php');
        preg_match('/function audit_event\(.*?\n}\n/s', $source, $match);

        self::assertNotEmpty($match, 'audit_event() is found');
        self::assertStringContainsString('audit_vm_refs_execute($connection, $stmt, $context)', $match[0]);
        self::assertStringNotContainsString('$stmt->execute()', $match[0], 'no path stores a row without its VM index');
    }

    public function testEveryEventThatRecordsVmIdsIsIndexedAndNoChronicleEventIsSecurity(): void
    {
        $withVmIds = [];
        foreach (audit_event_registry() as $code => $definition) {
            if (in_array('vm_ids', [...$definition['required'], ...$definition['optional']], true)) {
                $withVmIds[] = $code;
            }
        }
        self::assertContains(VIRTUSPHERE_AUDIT_EVENT_VM_BULK_CHANGED, $withVmIds);
        self::assertContains(VIRTUSPHERE_AUDIT_EVENT_DEPLOY_CONVERGENCE, $withVmIds);
        self::assertSame([7, 9], audit_vm_refs_ids(['vm_ids' => [7, 9, 7, 0, -1, '8']]), 'positive integer ids, folded');

        $events = repo_vm_history_audit_events();
        self::assertContains(VIRTUSPHERE_AUDIT_EVENT_VM_CHANGED, $events);
        $security = VIRTUSPHERE_LOG_TABS[VIRTUSPHERE_LOG_TAB_SECURITY];
        foreach ($events as $code) {
            self::assertNotContains(audit_event_registry()[$code]['category'], $security, $code);
        }
    }
}
