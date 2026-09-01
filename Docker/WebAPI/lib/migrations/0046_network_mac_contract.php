<?php

declare(strict_types=1);

function migrate_0046_network_mac_contract(mysqli $db): void
{
    $definitions = [
        ['deploy_esxi_inventory', 'name', 'VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL'],
        ['deploy_vlan', 'vlan_name', 'VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL'],
        ['deploy_missions', 'hypervisor_datacenter', 'VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NULL'],
        ['deploy_missions', 'hypervisor_datastorage', 'VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NULL'],
        ['deploy_missions', 'wds_vlan', 'VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NULL'],
        ['deploy_vms', 'vm_datacenter', 'VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NULL'],
        ['deploy_vms', 'vm_datastore', 'VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NULL'],
        ['deploy_interfaces', 'vlan', 'VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NULL'],
    ];
    $rows = 0;
    foreach ($definitions as [$table, $column, $definition]) {
        if (!migrator_table_exists($db, $table) || !migrator_column_exists($db, $table, $column)) {
            continue;
        }
        $rows += migrator_count($db, 'SELECT COUNT(*) AS c FROM `' . $table . '`'); // csp-allow: interpolated-sql
        $db->query('ALTER TABLE `' . $table . '` MODIFY COLUMN `' . $column . '` ' . $definition); // csp-allow: interpolated-sql
    }

    migrator_add_column($db, 'deploy_esxi_inventory_state', 'kind_name_semantics_json', 'JSON NULL AFTER kind_freshness_json');
    migrator_add_column($db, 'deploy_esxi_inventory_state', 'kind_observation_json', 'JSON NULL AFTER kind_name_semantics_json');
    if (migrator_check_exists($db, 'deploy_jobs', 'deploy_jobs_terminal_reason_check')) {
        $db->query('ALTER TABLE deploy_jobs DROP CHECK deploy_jobs_terminal_reason_check');
    }
    $db->query("ALTER TABLE deploy_jobs ADD CONSTRAINT deploy_jobs_terminal_reason_check CHECK (
        (terminal_reason_code IS NULL AND terminal_reason_detail IS NULL) OR
        (status = _utf8mb4'succeeded' AND terminal_reason_code = _utf8mb4'completed') OR
        (status = _utf8mb4'partial' AND terminal_reason_code = _utf8mb4'partial_result') OR
        (status = _utf8mb4'failed' AND terminal_reason_code IN (_utf8mb4'execution_failed',_utf8mb4'timeout',_utf8mb4'stale_heartbeat',_utf8mb4'ownership_lost',_utf8mb4'configuration_blocked')) OR
        (status = _utf8mb4'cancelled' AND terminal_reason_code IN (_utf8mb4'operator_cancelled',_utf8mb4'cancel_converged'))
    )");
    migrator_out('0046: exact ESXi names and per-kind evidence added; inspected rows=' . $rows);
}
