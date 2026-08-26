<?php

declare(strict_types=1);

/** Additive structured audit metadata; historical descriptions stay untouched. */
function migrate_0044_structured_audit_events(mysqli $db): void
{
    migrator_add_column($db, 'deploy_logs', 'event_code', 'VARCHAR(96) NULL AFTER correlation_id');
    migrator_add_column($db, 'deploy_logs', 'object_type', 'VARCHAR(64) NULL AFTER event_code');
    migrator_add_column($db, 'deploy_logs', 'object_id', 'VARCHAR(191) NULL AFTER object_type');
    migrator_add_column($db, 'deploy_logs', 'result', 'VARCHAR(16) NULL AFTER object_id');
    migrator_add_column($db, 'deploy_logs', 'context_json', 'LONGTEXT NULL AFTER result');
    migrator_add_index(
        $db,
        'deploy_logs',
        'deploy_logs_event_object_time',
        'INDEX deploy_logs_event_object_time (event_code, object_type, object_id, created_at)'
    );

    if (!migrator_check_exists($db, 'deploy_logs', 'deploy_logs_structured_audit_check')) {
        $db->query('ALTER TABLE deploy_logs ADD CONSTRAINT deploy_logs_structured_audit_check CHECK (
            (event_code IS NULL AND object_type IS NULL AND object_id IS NULL AND result IS NULL AND context_json IS NULL) OR
            (event_code IS NOT NULL AND object_type IS NOT NULL AND result IS NOT NULL AND
                (context_json IS NULL OR (JSON_VALID(context_json) = 1 AND JSON_TYPE(context_json) = _utf8mb4\'OBJECT\' AND OCTET_LENGTH(context_json) <= 4096)))
        )');
    }

    migrator_out('0044: structured audit fields and lookup index added without historical backfill');
}
