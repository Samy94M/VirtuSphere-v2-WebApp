<?php

declare(strict_types=1);

/**
 * Replace the category-only audit index with the measured keyset index.
 *
 * Keeping both indexes made MariaDB prefer the old one and inspect 2,193 rows
 * for a 51-row window. Reusing the existing name removes that ambiguous path.
 * The information-schema comparison makes the migration idempotent for both a
 * live upgrade and a fresh schema that already carries the final definition.
 */
function migrate_0054_log_keyset_pagination(mysqli $db): void
{
    if (!migrator_table_exists($db, 'deploy_logs')) {
        return;
    }

    $name = 'deploy_logs_category_lookup';
    $stmt = $db->prepare(
        'SELECT COLUMN_NAME FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
         ORDER BY SEQ_IN_INDEX'
    );
    $table = 'deploy_logs';
    $stmt->bind_param('ss', $table, $name);
    $stmt->execute();
    $columns = [];
    $result = $stmt->get_result();
    while (($row = $result->fetch_assoc()) !== null) {
        $columns[] = (string) $row['COLUMN_NAME'];
    }

    if ($columns === ['category', 'id']) {
        return;
    }

    if ($columns === []) {
        $db->query('ALTER TABLE deploy_logs ADD INDEX deploy_logs_category_lookup (category, id)');
    } else {
        $db->query(
            'ALTER TABLE deploy_logs DROP INDEX deploy_logs_category_lookup,
             ADD INDEX deploy_logs_category_lookup (category, id)'
        );
    }

    migrator_out('0054: audit-log category keyset index');
}
