<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * The VM index of the audit log (SP3, migration 0069). An audit row whose
 * normalized context carries `vm_ids` is stored together with one
 * deploy_log_vm_refs row per named VM, so the VM chronicle finds a bulk change
 * or a convergence sweep without reading the JSON of every log row.
 *
 * Derived from the context, never from a list of events: any event that records
 * `vm_ids` is indexed. Row and references commit together; a row without
 * `vm_ids` takes the unchanged single-statement path and opens no transaction,
 * so the raw request transactions of the machine API (webapi R3/R4) never meet a
 * repo_transaction() from here.
 */

/**
 * The positive, distinct VM ids of a normalized audit context.
 *
 * @param array<string,mixed> $context
 * @return list<int>
 */
function audit_vm_refs_ids(array $context): array
{
    $ids = [];
    foreach (is_array($context['vm_ids'] ?? null) ? $context['vm_ids'] : [] as $id) {
        if (is_int($id) && $id > 0) {
            $ids[$id] = $id;
        }
    }

    return array_values($ids);
}

/**
 * Executes the prepared audit insert of audit_event() and, when the context names
 * VMs, stores their references in the same transaction.
 *
 * @param array<string,mixed> $context
 */
function audit_vm_refs_execute(mysqli $db, mysqli_stmt $insert, array $context): bool
{
    $vmIds = audit_vm_refs_ids($context);
    if ($vmIds === []) {
        return $insert->execute();
    }

    return repo_transaction($db, static function () use ($db, $insert, $vmIds): bool {
        if (!$insert->execute()) {
            return false;
        }
        $logId = (int) $insert->insert_id;
        $stmt = $db->prepare('INSERT INTO deploy_log_vm_refs (vm_id, log_id) VALUES ' . implode(', ', array_fill(0, count($vmIds), '(?, ?)')));
        $params = [];
        foreach ($vmIds as $vmId) {
            $params[] = $vmId;
            $params[] = $logId;
        }
        $stmt->bind_param(str_repeat('i', count($params)), ...$params);

        return $stmt->execute();
    });
}
