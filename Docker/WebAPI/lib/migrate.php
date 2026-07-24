<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/repo/vm_location.php';

function migrator_out(string $message): void
{
    if (PHP_SAPI === 'cli') {
        fwrite(STDOUT, $message . PHP_EOL);
        return;
    }

    echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . "<br>\n";
}

function migrator_statement_count(mysqli_stmt $stmt, string $context): int
{
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    if (!is_array($row) || !array_key_exists('c', $row)) {
        throw new RuntimeException('Migration check returned no count: ' . $context);
    }

    return (int) $row['c'];
}

function migrator_query_row(mysqli $db, string $sql, string $context): array
{
    $result = $db->query($sql);
    if (!$result instanceof mysqli_result) {
        throw new RuntimeException('Migration query did not return a result set: ' . $context);
    }

    $row = $result->fetch_assoc();
    $result->free();
    if (!is_array($row)) {
        throw new RuntimeException('Migration query returned no rows: ' . $context);
    }

    return $row;
}

function migrator_acquire_schema_lock(mysqli $db): void
{
    $row = migrator_query_row($db, "SELECT GET_LOCK('virtusphere_schema_migration', 30) AS locked", 'schema migration lock');
    if ((int) ($row['locked'] ?? 0) !== 1) {
        throw new RuntimeException('Could not acquire schema migration lock within 30 seconds.');
    }
}

function migrator_release_schema_lock(mysqli $db): void
{
    try {
        $row = migrator_query_row($db, "SELECT RELEASE_LOCK('virtusphere_schema_migration') AS released", 'schema migration lock release');
    } catch (Throwable $exception) {
        error_log('[migrate] Could not release schema migration lock: ' . $exception->getMessage());
        return;
    }

    if ((int) ($row['released'] ?? 0) !== 1) {
        error_log('[migrate] Schema migration lock release returned: ' . (string) ($row['released'] ?? 'null'));
    }
}

function migrator_table_exists(mysqli $db, string $table): bool
{
    $stmt = $db->prepare('SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->bind_param('s', $table);
    $stmt->execute();
    return migrator_statement_count($stmt, 'table exists') > 0;
}

function migrator_column_exists(mysqli $db, string $table, string $column): bool
{
    $stmt = $db->prepare('SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    return migrator_statement_count($stmt, 'column exists') > 0;
}

function migrator_index_exists(mysqli $db, string $table, string $index): bool
{
    $stmt = $db->prepare('SELECT COUNT(*) AS c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $stmt->bind_param('ss', $table, $index);
    $stmt->execute();
    return migrator_statement_count($stmt, 'index exists') > 0;
}

function migrator_fk_exists(mysqli $db, string $table, string $constraint): bool
{
    $stmt = $db->prepare('SELECT COUNT(*) AS c FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = "FOREIGN KEY"');
    $stmt->bind_param('ss', $table, $constraint);
    $stmt->execute();
    return migrator_statement_count($stmt, 'foreign key exists') > 0;
}

// csp-allow: interpolated-sql
function migrator_add_column(mysqli $db, string $table, string $column, string $definition): void
{
    if (!migrator_table_exists($db, $table) || migrator_column_exists($db, $table, $column)) {
        return;
    }

    $db->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
}

function migrator_add_index(mysqli $db, string $table, string $index, string $definition): void
{
    if (!migrator_table_exists($db, $table) || migrator_index_exists($db, $table, $index)) {
        return;
    }

    $db->query("ALTER TABLE `{$table}` ADD {$definition}");
}

function migrator_count(mysqli $db, string $sql): int
{
    $row = migrator_query_row($db, $sql, 'migration count');
    if (!array_key_exists('c', $row)) {
        throw new RuntimeException('Migration count query did not return column c.');
    }

    return (int) $row['c'];
}

/** @return array{materialize:array<int,array<string,mixed>>,skipped:array<int,array<string,mixed>>} */
function migrator_default_interface_plan(mysqli $db): array
{
    if (!migrator_table_exists($db, 'deploy_missions')
        || !migrator_table_exists($db, 'deploy_vms')
        || !migrator_table_exists($db, 'deploy_interfaces')) {
        return ['materialize' => [], 'skipped' => []];
    }

    require_once __DIR__ . '/defaults.php';
    $sql = <<<'SQL'
SELECT v.id AS vm_id, v.vm_name, m.mission_name, TRIM(COALESCE(m.wds_vlan, '')) AS wds_vlan
FROM deploy_vms v
INNER JOIN deploy_missions m ON m.id = v.mission_id
WHERE NOT EXISTS (SELECT 1 FROM deploy_interfaces i WHERE i.vm_id = v.id)
ORDER BY m.id, v.id
SQL;
    $result = $db->query($sql);
    $plan = ['materialize' => [], 'skipped' => []];
    while ($row = $result->fetch_assoc()) {
        if (mission_name_is_template((string) $row['mission_name'])) {
            continue;
        }
        $key = (string) $row['wds_vlan'] === '' ? 'skipped' : 'materialize';
        $plan[$key][] = $row;
    }
    $result->free();

    return $plan;
}

/** @param array<int,array<string,mixed>> $rows */
function migrator_report_default_interface_skips(array $rows, string $phase): void
{
    foreach ($rows as $row) {
        $mission = json_encode((string) $row['mission_name'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $vm = json_encode((string) $row['vm_name'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        migrator_out($phase . ': skipped VM ' . $vm . ' in mission ' . $mission . ' because wds_vlan is empty');
    }
}

function migrator_preflight_deploy_job_statuses(mysqli $db): void
{
    if (!migrator_table_exists($db, 'deploy_jobs') || !migrator_column_exists($db, 'deploy_jobs', 'status')) {
        return;
    }

    require_once __DIR__ . '/deploy_constants.php';
    $allowed = [
        VIRTUSPHERE_DEPLOY_STATUS_QUEUED,
        VIRTUSPHERE_DEPLOY_STATUS_RUNNING,
        VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED,
        VIRTUSPHERE_DEPLOY_STATUS_FAILED,
        VIRTUSPHERE_DEPLOY_STATUS_CANCELLED,
        VIRTUSPHERE_DEPLOY_STATUS_PARTIAL,
    ];
    $invalid = [];
    $result = $db->query('SELECT status, COUNT(*) AS c FROM deploy_jobs GROUP BY status ORDER BY status');
    while ($row = $result->fetch_assoc()) {
        $status = (string) $row['status'];
        if (!in_array($status, $allowed, true)) {
            $invalid[] = json_encode($status) . ' (' . (int) $row['c'] . ')';
        }
    }
    $result->free();

    if ($invalid !== []) {
        throw new RuntimeException('Preflight blocked: deploy_jobs.status contains unsupported value(s): ' . implode(', ', $invalid) . '.');
    }
}

function migrator_preflight(mysqli $db, bool $reportDefaultInterfaces = false): void
{
    if (migrator_table_exists($db, 'deploy_vms')) {
        $duplicateVms = migrator_count($db, 'SELECT COUNT(*) AS c FROM (SELECT mission_id, vm_name FROM deploy_vms GROUP BY mission_id, vm_name HAVING COUNT(*) > 1) duplicates');
        if ($duplicateVms > 0) {
            throw new RuntimeException('Preflight blocked: duplicate deploy_vms rows for mission_id/vm_name exist.');
        }
    }

    if (migrator_table_exists($db, 'deploy_os')) {
        $duplicateOs = migrator_count($db, 'SELECT COUNT(*) AS c FROM (SELECT os_name FROM deploy_os GROUP BY os_name HAVING COUNT(*) > 1) duplicates');
        if ($duplicateOs > 0) {
            throw new RuntimeException('Preflight blocked: duplicate deploy_os.os_name values exist.');
        }
    }

    if (migrator_table_exists($db, 'deploy_disks') && migrator_table_exists($db, 'deploy_vms')) {
        $orphans = migrator_count($db, 'SELECT COUNT(*) AS c FROM deploy_disks d LEFT JOIN deploy_vms v ON v.id = d.vm_id WHERE v.id IS NULL');
        if ($orphans > 0) {
            throw new RuntimeException('Preflight blocked: orphan deploy_disks rows exist.');
        }
    }

    migrator_preflight_deploy_job_statuses($db);

    if ($reportDefaultInterfaces) {
        $plan = migrator_default_interface_plan($db);
        migrator_out('check: 0020 default interfaces materialize=' . count($plan['materialize']) . ' skipped=' . count($plan['skipped']));
        migrator_report_default_interface_skips($plan['skipped'], 'check: 0020');
    }
}

function migrator_ensure_tracking(mysqli $db): void
{
    $db->query('CREATE TABLE IF NOT EXISTS deploy_migrations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(191) UNIQUE NOT NULL,
        applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}

function migrator_applied(mysqli $db, string $name): bool
{
    $stmt = $db->prepare('SELECT COUNT(*) AS c FROM deploy_migrations WHERE name = ?');
    $stmt->bind_param('s', $name);
    $stmt->execute();
    return migrator_statement_count($stmt, 'migration applied') > 0;
}

function migrator_mark_applied(mysqli $db, string $name): void
{
    $stmt = $db->prepare('INSERT INTO deploy_migrations (name) VALUES (?)');
    $stmt->bind_param('s', $name);
    $stmt->execute();
}

function migrator_pending_migrations(mysqli $db, array $migrations): array
{
    if (!migrator_table_exists($db, 'deploy_migrations')) {
        return array_keys($migrations);
    }

    $pending = [];
    foreach (array_keys($migrations) as $name) {
        if (!migrator_applied($db, $name)) {
            $pending[] = $name;
        }
    }

    return $pending;
}

function migrator_run_check(mysqli $db, array $migrations): void
{
    envboot_assert_secure_runtime();
    migrator_out('check: env ok');
    migrator_out('check: database ok');

    migrator_preflight($db, true);
    migrator_out('check: data preflight ok');

    $pending = migrator_pending_migrations($db, $migrations);
    migrator_out('check: migrations pending=' . count($pending));
    foreach ($pending as $name) {
        migrator_out('check: pending ' . $name);
    }

    migrator_out('check: ok');
}

$migrations = [
    '0001_runtime_columns' => function (mysqli $db): void {
        migrator_add_column($db, 'deploy_missions', 'domain', 'VARCHAR(255) NULL');
        migrator_add_column($db, 'deploy_vms', 'updated', 'TINYINT(1) NOT NULL DEFAULT 0');
        migrator_add_column($db, 'deploy_vms', 'lifecycle_state', "ENUM('initializing','ready','deploying','deployed','os_installing','os_installed','failed') NOT NULL DEFAULT 'ready'");
        migrator_add_column($db, 'deploy_vms', 'mecm_sync_state', "ENUM('not_ready','pending','submitted','registered','failed') NOT NULL DEFAULT 'not_ready'");
        migrator_add_column($db, 'deploy_users', 'role', "ENUM('admin','user') NOT NULL DEFAULT 'user'");
        migrator_add_column($db, 'deploy_users', 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1');
        migrator_add_column($db, 'deploy_users', 'must_change_password', 'TINYINT(1) NOT NULL DEFAULT 0');
        migrator_add_column($db, 'deploy_users', 'password_changed_at', 'TIMESTAMP NULL');
        migrator_add_column($db, 'deploy_users', 'last_seen_at', 'TIMESTAMP NULL');
        migrator_add_column($db, 'deploy_users', 'locked_until', 'TIMESTAMP NULL');
        migrator_add_column($db, 'deploy_logs', 'user_id', 'INT NULL');
    },
    '0002_support_tables' => function (mysqli $db): void {
        $db->query("CREATE TABLE IF NOT EXISTS deploy_login_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(191) NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            success TINYINT(1) NOT NULL DEFAULT 0,
            attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX login_attempt_lookup (username, ip_address, attempted_at),
            INDEX login_attempt_ip_lookup (ip_address, attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->query("CREATE TABLE IF NOT EXISTS deploy_credentials (
            id INT AUTO_INCREMENT PRIMARY KEY,
            type ENUM('esxi','ansible') NOT NULL,
            name VARCHAR(191) NOT NULL,
            host VARCHAR(255) NOT NULL,
            port INT NULL,
            username VARCHAR(191) NOT NULL,
            secret_ciphertext TEXT NOT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY credential_name_type_unique (type, name),
            CONSTRAINT fk_deploy_credentials_created_by FOREIGN KEY (created_by) REFERENCES deploy_users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->query("CREATE TABLE IF NOT EXISTS deploy_jobs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            mission_id INT NULL,
            user_id INT NULL,
            status ENUM('queued','running','succeeded','failed','cancelled','partial') NOT NULL DEFAULT 'queued',
            locked_at TIMESTAMP NULL,
            locked_by VARCHAR(191) NULL,
            heartbeat_at TIMESTAMP NULL,
            attempts INT NOT NULL DEFAULT 0,
            last_error TEXT NULL,
            payload_json JSON NULL,
            result_json JSON NULL,
            credential_esxi_id INT NULL,
            credential_ansible_id INT NULL,
            cancelled_at TIMESTAMP NULL,
            scheduled_at DATETIME NULL,
            group_id CHAR(12) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX deploy_jobs_mission_status (mission_id, status),
            INDEX deploy_jobs_schedule (status, scheduled_at),
            INDEX deploy_jobs_group (group_id),
            CONSTRAINT fk_deploy_jobs_mission FOREIGN KEY (mission_id) REFERENCES deploy_missions(id) ON DELETE CASCADE,
            CONSTRAINT fk_deploy_jobs_user FOREIGN KEY (user_id) REFERENCES deploy_users(id) ON DELETE SET NULL,
            CONSTRAINT fk_deploy_jobs_esxi_credential FOREIGN KEY (credential_esxi_id) REFERENCES deploy_credentials(id) ON DELETE SET NULL,
            CONSTRAINT fk_deploy_jobs_ansible_credential FOREIGN KEY (credential_ansible_id) REFERENCES deploy_credentials(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->query("CREATE TABLE IF NOT EXISTS deploy_job_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            job_id INT NOT NULL,
            seq INT NOT NULL,
            stream ENUM('stdout','stderr','system') NOT NULL DEFAULT 'stdout',
            line TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY deploy_job_logs_job_seq_unique (job_id, seq),
            CONSTRAINT fk_deploy_job_logs_job FOREIGN KEY (job_id) REFERENCES deploy_jobs(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->query("CREATE TABLE IF NOT EXISTS deploy_settings (
            setting_key VARCHAR(191) PRIMARY KEY,
            setting_value TEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->query("CREATE TABLE IF NOT EXISTS deploy_vm_status_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vm_id INT NOT NULL,
            lifecycle_state VARCHAR(64) NOT NULL,
            mecm_sync_state VARCHAR(64) NOT NULL,
            legacy_status VARCHAR(64) NOT NULL,
            note TEXT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_deploy_vm_status_events_vm FOREIGN KEY (vm_id) REFERENCES deploy_vms(id) ON DELETE CASCADE,
            CONSTRAINT fk_deploy_vm_status_events_user FOREIGN KEY (created_by) REFERENCES deploy_users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    },
    '0003_utf8mb4_indexes_fks' => function (mysqli $db): void {
        foreach (['deploy_missions', 'deploy_vms', 'deploy_interfaces', 'deploy_disks', 'deploy_packages', 'deploy_vm_packages', 'deploy_vlan', 'deploy_tokens', 'deploy_logs', 'deploy_os', 'deploy_users', 'deploy_accessToWebAPI'] as $table) {
            if (migrator_table_exists($db, $table)) {
                $db->query("ALTER TABLE `{$table}` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            }
        }

        migrator_add_index($db, 'deploy_vms', 'mission_vm_unique', 'UNIQUE INDEX mission_vm_unique (mission_id, vm_name)');
        migrator_add_index($db, 'deploy_os', 'os_name_unique', 'UNIQUE INDEX os_name_unique (os_name)');
        migrator_add_index($db, 'deploy_missions', 'mission_name_unique', 'UNIQUE INDEX mission_name_unique (mission_name)');
        migrator_add_index($db, 'deploy_packages', 'package_name_unique', 'UNIQUE INDEX package_name_unique (package_name)');
        migrator_add_index($db, 'deploy_users', 'user_name_unique', 'UNIQUE INDEX user_name_unique (name)');

        if (migrator_table_exists($db, 'deploy_disks') && migrator_table_exists($db, 'deploy_vms') && !migrator_fk_exists($db, 'deploy_disks', 'fk_deploy_disks_vm_id')) {
            $db->query('ALTER TABLE deploy_disks ADD CONSTRAINT fk_deploy_disks_vm_id FOREIGN KEY (vm_id) REFERENCES deploy_vms(id) ON DELETE CASCADE');
        }
    },
    '0004_login_attempt_ip_index' => function (mysqli $db): void {
        migrator_add_index($db, 'deploy_login_attempts', 'login_attempt_ip_lookup', 'INDEX login_attempt_ip_lookup (ip_address, attempted_at)');
    },
    '0005_interface_mac_index' => function (mysqli $db): void {
        migrator_add_index($db, 'deploy_interfaces', 'deploy_interfaces_mac_lookup', 'INDEX deploy_interfaces_mac_lookup (mac)');
    },
    '0006_log_category' => function (mysqli $db): void {
        migrator_add_column($db, 'deploy_logs', 'category', "VARCHAR(32) NOT NULL DEFAULT 'system'");
        migrator_add_index($db, 'deploy_logs', 'deploy_logs_category_lookup', 'INDEX deploy_logs_category_lookup (category)');
    },
    '0007_client_events_and_heartbeats' => function (mysqli $db): void {
        $db->query("CREATE TABLE IF NOT EXISTS deploy_client_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vm_id INT NOT NULL,
            mac VARCHAR(17) NOT NULL,
            phase VARCHAR(32) NOT NULL,
            event VARCHAR(16) NOT NULL,
            detail VARCHAR(1024) NULL,
            client_ip VARCHAR(45) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX deploy_client_events_vm_phase (vm_id, phase, id),
            INDEX deploy_client_events_created (created_at),
            CONSTRAINT fk_deploy_client_events_vm FOREIGN KEY (vm_id) REFERENCES deploy_vms(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->query("CREATE TABLE IF NOT EXISTS deploy_integration_heartbeats (
            source VARCHAR(64) PRIMARY KEY,
            last_seen_at TIMESTAMP NULL,
            last_checked_at TIMESTAMP NULL,
            last_status VARCHAR(8) NOT NULL DEFAULT 'ok',
            last_detail VARCHAR(255) NULL,
            last_ip VARCHAR(45) NOT NULL DEFAULT '',
            interval_seconds INT NOT NULL DEFAULT 60,
            beat_count BIGINT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    },
    '0008_mac_normalize_interfaces' => function (mysqli $db): void {
        // Canonicalize stored MACs (uppercase, colon-separated) so exact-match
        // lookups work regardless of the writer's format. PHP loop on purpose:
        // Cisco dotted notation cannot be regrouped with SQL string functions.
        // Unparseable legacy values stay untouched and are logged, duplicates
        // across VMs are logged but never block the upgrade.
        require_once __DIR__ . '/mac.php';

        $result = $db->query("SELECT id, mac FROM deploy_interfaces WHERE mac IS NOT NULL AND mac <> ''");
        while ($row = $result->fetch_assoc()) {
            $normalized = virtusphere_normalize_mac((string) $row['mac']);
            if ($normalized === null) {
                error_log('[migrate] 0008: interface ' . (int) $row['id'] . ' keeps unparseable mac value');
                continue;
            }
            if ($normalized !== (string) $row['mac']) {
                $stmt = $db->prepare('UPDATE deploy_interfaces SET mac = ? WHERE id = ?');
                $id = (int) $row['id'];
                $stmt->bind_param('si', $normalized, $id);
                $stmt->execute();
            }
        }

        $dupes = $db->query("SELECT mac, COUNT(DISTINCT vm_id) AS vms FROM deploy_interfaces WHERE mac IS NOT NULL AND mac <> '' GROUP BY mac HAVING COUNT(DISTINCT vm_id) > 1");
        while ($dupe = $dupes->fetch_assoc()) {
            error_log('[migrate] 0008: mac ' . (string) $dupe['mac'] . ' is shared by ' . (int) $dupe['vms'] . ' VMs - MAC lookups are ambiguous for these');
        }
    },
    '0009_catalog_versioning' => function (mysqli $db): void {
        migrator_add_column($db, 'deploy_packages', 'package_basename', "VARCHAR(255) NOT NULL DEFAULT ''");
        migrator_add_column($db, 'deploy_packages', 'retired_at', 'TIMESTAMP NULL');
        migrator_add_index($db, 'deploy_packages', 'deploy_packages_basename_lookup', 'INDEX deploy_packages_basename_lookup (package_basename)');
        migrator_add_column($db, 'deploy_os', 'retired_at', 'TIMESTAMP NULL');

        // Backfill: split "Name-Version" at the LAST hyphen (versions are
        // hyphen-free by convention); names without a hyphen keep the full
        // name as basename with an empty version.
        require_once __DIR__ . '/repo/catalog.php';
        $result = $db->query("SELECT id, package_name FROM deploy_packages WHERE package_basename = ''");
        while ($row = $result->fetch_assoc()) {
            $split = repo_package_split_name((string) $row['package_name']);
            $stmt = $db->prepare('UPDATE deploy_packages SET package_basename = ?, package_version = ? WHERE id = ?');
            $id = (int) $row['id'];
            $stmt->bind_param('ssi', $split['basename'], $split['version'], $id);
            $stmt->execute();
        }
    },
    '0010_legacy_token_user' => function (mysqli $db): void {
        // Bind each legacy API token to the issuing user so access.php can gate
        // mutating actions by the same RBAC permission the portal enforces.
        migrator_add_column($db, 'deploy_tokens', 'user_id', 'INT NULL');
    },
    '0011_deploy_job_scheduling' => function (mysqli $db): void {
        // Deploy scheduling (ADR-0022): scheduled_at is a UTC run-not-before
        // time; group_id ties a staggered batch of per-VM jobs together. Both
        // nullable so unscheduled jobs behave exactly as before.
        migrator_add_column($db, 'deploy_jobs', 'scheduled_at', 'DATETIME NULL');
        migrator_add_column($db, 'deploy_jobs', 'group_id', 'CHAR(12) NULL');
        migrator_add_index($db, 'deploy_jobs', 'deploy_jobs_schedule', 'INDEX deploy_jobs_schedule (status, scheduled_at)');
        migrator_add_index($db, 'deploy_jobs', 'deploy_jobs_group', 'INDEX deploy_jobs_group (group_id)');
    },
    '0012_vm_hotplug' => function (mysqli $db): void {
        // CPU/RAM hot-add options (Paket F); default on, applied only at VM
        // creation. Existing (incl. legacy-API-created) rows get the default.
        migrator_add_column($db, 'deploy_vms', 'cpu_hotplug', 'TINYINT(1) NOT NULL DEFAULT 1');
        migrator_add_column($db, 'deploy_vms', 'ram_hotplug', 'TINYINT(1) NOT NULL DEFAULT 1');
    },
    '0013_esxi_inventory' => function (mysqli $db): void {
        // ESXi inventory (ADR-0023). System jobs run without a mission, so
        // mission_id becomes nullable (FK + cascade stay for real deploy jobs).
        if (migrator_column_exists($db, 'deploy_jobs', 'mission_id')) {
            $db->query('ALTER TABLE deploy_jobs MODIFY mission_id INT NULL');
        }
        // VLAN catalog becomes ESXi-owned: retire instead of delete.
        migrator_add_column($db, 'deploy_vlan', 'retired_at', 'TIMESTAMP NULL');

        $db->query("CREATE TABLE IF NOT EXISTS deploy_esxi_inventory (
            id INT AUTO_INCREMENT PRIMARY KEY,
            credential_id INT NOT NULL,
            kind ENUM('datacenter','datastore','network','host') NOT NULL,
            name VARCHAR(191) NOT NULL,
            capacity_bytes BIGINT NULL,
            free_bytes BIGINT NULL,
            meta_json JSON NULL,
            fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY deploy_esxi_inventory_unique (credential_id, kind, name),
            CONSTRAINT fk_deploy_esxi_inventory_credential FOREIGN KEY (credential_id) REFERENCES deploy_credentials(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->query("CREATE TABLE IF NOT EXISTS deploy_esxi_inventory_state (
            credential_id INT PRIMARY KEY,
            last_success_at TIMESTAMP NULL,
            last_attempt_at TIMESTAMP NULL,
            last_status VARCHAR(32) NULL,
            last_error_category VARCHAR(32) NULL,
            failure_streak INT NOT NULL DEFAULT 0,
            paused_until_credential_change TINYINT(1) NOT NULL DEFAULT 0,
            CONSTRAINT fk_deploy_esxi_inventory_state_credential FOREIGN KEY (credential_id) REFERENCES deploy_credentials(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    },
    '0014_normalize_vm_location_overrides' => function (mysqli $db): void {
        // Data-only, no DDL: the deploy now resolves vm_datastore/vm_datacenter
        // ahead of the mission value, so the copies the old VM editor left behind
        // have to become empty ("inherit") again. See repo/vm_location.php.
        if (!migrator_column_exists($db, 'deploy_vms', 'vm_datastore')) {
            return;
        }
        $cleared = repo_normalize_vm_location_overrides($db);
        migrator_out('0014: cleared ' . $cleared['datastore'] . ' datastore, ' . $cleared['datacenter'] . ' datacenter copies');
    },
    '0015_mission_creator' => function (mysqli $db): void {
        // Provenance for missions, mirroring deploy_vms.vm_creator: a username
        // snapshot, not an FK, so it survives a user being deleted or renamed.
        // Nullable with no backfill on purpose - the audit log records the author
        // by mission *name*, which renames break, so any backfill would be a guess
        // presented as a fact. Existing rows stay NULL and render as unknown.
        migrator_add_column($db, 'deploy_missions', 'mission_creator', 'VARCHAR(255) NULL');
    },
    '0016_esxi_autostart_and_capabilities' => function (mysqli $db): void {
        // ESXi autostart policy (ADR-0025). Opt-in: every existing mission and VM
        // gets autostart_enabled = 0, so nothing on any host changes until an
        // operator turns it on and runs the mode. The mission columns mirror the
        // host's system_defaults, the VM columns its per-VM power_info.
        migrator_add_column($db, 'deploy_missions', 'autostart_enabled', 'TINYINT(1) NOT NULL DEFAULT 0');
        migrator_add_column($db, 'deploy_missions', 'autostart_start_delay', 'INT NOT NULL DEFAULT 120');
        migrator_add_column($db, 'deploy_missions', 'autostart_stop_delay', 'INT NOT NULL DEFAULT 120');
        migrator_add_column($db, 'deploy_missions', 'autostart_stop_action', "ENUM('guestShutdown','powerOff','suspend','none') NOT NULL DEFAULT 'guestShutdown'");
        migrator_add_column($db, 'deploy_missions', 'autostart_wait_for_heartbeat', 'TINYINT(1) NOT NULL DEFAULT 0');

        // -1 = inherit the mission default (what vmware_host_auto_start reads as
        // "use system defaults"). Existing rows therefore inherit, which is the
        // only defensible default: a backfilled 0 would mean "start immediately".
        migrator_add_column($db, 'deploy_vms', 'autostart_enabled', 'TINYINT(1) NOT NULL DEFAULT 0');
        migrator_add_column($db, 'deploy_vms', 'autostart_start_delay', 'INT NOT NULL DEFAULT -1');
        migrator_add_column($db, 'deploy_vms', 'autostart_stop_delay', 'INT NOT NULL DEFAULT -1');

        // Capability facts of the last successful inventory pull (ADR-0023
        // amendment 3). NULL = not known; the next pull fills them in. They are
        // not error categories and never colour a fetch as failed.
        migrator_add_column($db, 'deploy_esxi_inventory_state', 'api_type', 'VARCHAR(32) NULL');
        migrator_add_column($db, 'deploy_esxi_inventory_state', 'product_version', 'VARCHAR(191) NULL');
        migrator_add_column($db, 'deploy_esxi_inventory_state', 'license_product', 'VARCHAR(191) NULL');
        migrator_add_column($db, 'deploy_esxi_inventory_state', 'license_free', 'TINYINT(1) NULL');
        migrator_add_column($db, 'deploy_esxi_inventory_state', 'in_ha_cluster', 'TINYINT(1) NULL');
        migrator_add_column($db, 'deploy_esxi_inventory_state', 'in_maintenance', 'TINYINT(1) NULL');
    },
    '0017_normalize_catalog_status' => function (mysqli $db): void {
        // Data-only, no DDL. The catalog status columns are free text; the MECM
        // sync writes 'Aktiv'/'Retired', but legacy-API rows may hold lowercase
        // or 'active'. Fold the known synonyms onto the two canonical values so
        // every reader (and the status badge) sees one spelling. New writes are
        // normalized at the repo layer (catalog_normalize_status); this cleans
        // up existing rows. Idempotent: the <> guard makes a re-run a no-op, and
        // unknown free-text values are left untouched (narrowing them is E3).
        require_once __DIR__ . '/constants.php';
        $active = VIRTUSPHERE_CATALOG_STATUS_DEFAULT;
        $retired = VIRTUSPHERE_CATALOG_STATUS_RETIRED;
        foreach ([['deploy_os', 'os_status'], ['deploy_packages', 'package_status']] as [$table, $column]) {
            if (!migrator_column_exists($db, $table, $column)) {
                continue;
            }
            // csp-allow: interpolated-sql (table/column are code literals, values bound)
            $stmt = $db->prepare("UPDATE `{$table}` SET `{$column}` = ? WHERE LOWER(`{$column}`) IN ('aktiv', 'active') AND `{$column}` <> ?");
            $stmt->bind_param('ss', $active, $active);
            $stmt->execute();
            $stmt = $db->prepare("UPDATE `{$table}` SET `{$column}` = ? WHERE LOWER(`{$column}`) = 'retired' AND `{$column}` <> ?");
            $stmt->bind_param('ss', $retired, $retired);
            $stmt->execute();
            migrator_out('0017: normalized ' . $table . '.' . $column . ' (' . $db->affected_rows . ' rows in last step)');
        }
    },
    '0018_renormalize_interface_macs' => function (mysqli $db): void {
        // Data-only, no DDL. 0008 canonicalized this column once, but the portal
        // write path kept storing the operator's notation verbatim, so every MAC
        // typed in since then in hyphen or Cisco dotted form is invisible to the
        // MAC lookups of MECM and db_importMAC. Validator::mac() now canonicalizes
        // on write; this repairs the rows that drifted in the meantime. Same shape
        // as 0008 and idempotent: the <> guard makes a re-run a no-op, unparseable
        // values are left untouched.
        require_once __DIR__ . '/mac.php';

        $updated = 0;
        $result = $db->query("SELECT id, mac FROM deploy_interfaces WHERE mac IS NOT NULL AND mac <> ''");
        while ($row = $result->fetch_assoc()) {
            $normalized = virtusphere_normalize_mac((string) $row['mac']);
            if ($normalized === null || $normalized === (string) $row['mac']) {
                continue;
            }
            $stmt = $db->prepare('UPDATE deploy_interfaces SET mac = ? WHERE id = ?');
            $id = (int) $row['id'];
            $stmt->bind_param('si', $normalized, $id);
            $stmt->execute();
            $updated++;
        }

        migrator_out('0018: re-normalized ' . $updated . ' interface MAC(s) that drifted after 0008');
    },
    '0019_deploy_partial_results' => function (mysqli $db): void {
        // The status values are an order-exact mirror of deploy_constants.php.
        // Preflight rejects unsupported stored values before this DDL, so MySQL
        // can never coerce an unknown status while changing the ENUM.
        if (migrator_table_exists($db, 'deploy_jobs') && migrator_column_exists($db, 'deploy_jobs', 'status')) {
            $sql = <<<'SQL'
ALTER TABLE deploy_jobs
MODIFY COLUMN status ENUM('queued','running','succeeded','failed','cancelled','partial') NOT NULL DEFAULT 'queued'
SQL;
            $db->query($sql);
        }
        migrator_add_column($db, 'deploy_jobs', 'result_json', 'JSON NULL AFTER payload_json');
    },
    '0020_materialize_default_interfaces' => function (mysqli $db): void {
        // Materialize the exact interface ansible_vm_interfaces() used to invent
        // only in YAML. Empty mission VLANs are reported and never guessed.
        require_once __DIR__ . '/defaults.php';
        $plan = migrator_default_interface_plan($db);
        $sql = <<<'SQL'
INSERT INTO deploy_interfaces
    (vm_id, ip, subnet, gateway, dns1, dns2, vlan, mac, mode, type)
SELECT ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
WHERE NOT EXISTS (SELECT 1 FROM deploy_interfaces WHERE vm_id = ?)
SQL;
        $insert = $db->prepare($sql);
        $empty = '';
        $mode = VIRTUSPHERE_VM_DEFAULTS['interface_mode'];
        $type = VIRTUSPHERE_VM_DEFAULTS['interface_type'];
        $materialized = 0;
        foreach ($plan['materialize'] as $row) {
            $vmId = (int) $row['vm_id'];
            $vlan = (string) $row['wds_vlan'];
            $insert->bind_param(
                'isssssssssi',
                $vmId,
                $empty,
                $empty,
                $empty,
                $empty,
                $empty,
                $vlan,
                $empty,
                $mode,
                $type,
                $vmId
            );
            $insert->execute();
            $materialized += $insert->affected_rows;
        }

        migrator_out('0020: materialized ' . $materialized . ' default interface(s); skipped ' . count($plan['skipped']) . ' VM(s) with empty wds_vlan');
        migrator_report_default_interface_skips($plan['skipped'], '0020');
    },
    '0021_deploy_tokens_user_fk' => function (mysqli $db): void {
        // Migration 0010 added deploy_tokens.user_id but never the FK that
        // struktur.sql grew alongside it, so every pre-0010 install diverges
        // from a fresh schema. Found by the restore drill's schema fingerprint
        // (AP6). Preflight per database.md: orphaned user_id values would block
        // the DDL; NULL them first, which is exactly what ON DELETE SET NULL
        // would have done had the FK existed when the user was removed.
        if (migrator_fk_exists($db, 'deploy_tokens', 'fk_deploy_tokens_user')) {
            return;
        }

        $orphaned = 0;
        if (migrator_column_exists($db, 'deploy_tokens', 'user_id')) {
            $db->query('UPDATE deploy_tokens t LEFT JOIN deploy_users u ON u.id = t.user_id SET t.user_id = NULL WHERE t.user_id IS NOT NULL AND u.id IS NULL');
            $orphaned = $db->affected_rows;
        } else {
            migrator_add_column($db, 'deploy_tokens', 'user_id', 'INT NULL');
        }

        $db->query('ALTER TABLE deploy_tokens ADD CONSTRAINT fk_deploy_tokens_user FOREIGN KEY (user_id) REFERENCES deploy_users(id) ON DELETE SET NULL');
        migrator_out('0021: added fk_deploy_tokens_user; nulled ' . $orphaned . ' orphaned token user reference(s)');
    },
    '0022_correlation_ids' => function (mysqli $db): void {
        // ADR-0032: purely diagnostic, additive NULL columns. NULL reads as
        // "predates the correlation id"; no backfill, no index (lookups are
        // grep-shaped operator work, not hot paths).
        migrator_add_column($db, 'deploy_jobs', 'correlation_id', 'VARCHAR(32) NULL AFTER group_id');
        migrator_add_column($db, 'deploy_logs', 'correlation_id', 'VARCHAR(32) NULL AFTER user_id');
        migrator_add_column($db, 'deploy_job_logs', 'correlation_id', 'VARCHAR(32) NULL AFTER line');
        migrator_out('0022: added correlation_id to deploy_jobs, deploy_logs and deploy_job_logs (ADR-0032)');
    },
    '0023_ansible_preflight_state' => function (mysqli $db): void {
        // Persist the on-demand Ansible preflight result so the credential row and
        // the system status page can show a badge instead of a one-shot flash.
        // On-demand only (no scheduler): last_checked_at is shown verbatim and the
        // reader judges staleness. last_status is a plain VARCHAR (the
        // 'ok'/'warning'/'failed' set lives in lib/repo/ansible_preflight.php),
        // not a DB ENUM, so no ADR-0016 mirror is owed. Additive, idempotent.
        $db->query("CREATE TABLE IF NOT EXISTS deploy_ansible_preflight_state (
            credential_id INT PRIMARY KEY,
            last_status VARCHAR(16) NOT NULL,
            last_checked_at TIMESTAMP NULL,
            last_component VARCHAR(64) NULL,
            CONSTRAINT fk_deploy_ansible_preflight_state_credential FOREIGN KEY (credential_id) REFERENCES deploy_credentials(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        migrator_out('0023: created deploy_ansible_preflight_state');
    },
    '0024_mecm_probe_detail_context' => function (mysqli $db): void {
        // The probe now persists a versioned, redacted JSON context instead of
        // one legacy error sentence. Keep existing rows unchanged; widening is
        // additive and old plain text remains readable through the fallback.
        if (migrator_table_exists($db, 'deploy_integration_heartbeats')
            && migrator_column_exists($db, 'deploy_integration_heartbeats', 'last_detail')
        ) {
            $db->query('ALTER TABLE deploy_integration_heartbeats MODIFY COLUMN last_detail VARCHAR(2048) NULL');
        }
        migrator_out('0024: widened integration heartbeat detail for versioned MECM probe context');
    },
    '0025_mecm_result_reporting' => function (mysqli $db): void {
        // The MECM sync tasks and the new site-health reporter announce the
        // actual outcome of each run (mecm_report.php?action=reportRun) instead
        // of a bare heartbeat. deploy_integration_heartbeats stays one summary
        // row per source and is widened additively; there is no backfill, so
        // existing rows keep report_version=1 and all V2 columns stay NULL until
        // a V2 event fills them. `last_event` alone drives the display semantics
        // (legacy vs V2, running vs completed); `report_version` is only an
        // upward ratchet for the one-time legacy->V2 log entry.
        migrator_add_column($db, 'deploy_integration_heartbeats', 'report_version', 'TINYINT UNSIGNED NOT NULL DEFAULT 1');
        migrator_add_column($db, 'deploy_integration_heartbeats', 'last_event', "VARCHAR(16) NOT NULL DEFAULT 'heartbeat'");
        migrator_add_column($db, 'deploy_integration_heartbeats', 'last_run_id', 'VARCHAR(32) NULL');
        migrator_add_column($db, 'deploy_integration_heartbeats', 'last_attempt_at', 'TIMESTAMP NULL');
        migrator_add_column($db, 'deploy_integration_heartbeats', 'last_result_at', 'TIMESTAMP NULL');
        migrator_add_column($db, 'deploy_integration_heartbeats', 'last_success_at', 'TIMESTAMP NULL');
        migrator_add_column($db, 'deploy_integration_heartbeats', 'last_failure_at', 'TIMESTAMP NULL');
        migrator_add_column($db, 'deploy_integration_heartbeats', 'last_error_category', 'VARCHAR(64) NULL');
        migrator_add_column($db, 'deploy_integration_heartbeats', 'last_duration_ms', 'INT UNSIGNED NULL');
        migrator_add_column($db, 'deploy_integration_heartbeats', 'failure_streak', 'INT UNSIGNED NOT NULL DEFAULT 0');
        migrator_add_column($db, 'deploy_integration_heartbeats', 'last_summary', 'JSON NULL');
        migrator_add_column($db, 'deploy_integration_heartbeats', 'last_script_version', 'VARCHAR(32) NULL');
        migrator_out('0025: added MECM result-reporting columns to deploy_integration_heartbeats');
    },
];

try {
    $db = db();
    $checkOnly = in_array('--check', $argv ?? [], true);
    $statusOnly = in_array('--status', $argv ?? [], true);

    if ($checkOnly) {
        migrator_run_check($db, $migrations);
        exit(0);
    }

    migrator_ensure_tracking($db);

    if ($statusOnly) {
        foreach (array_keys($migrations) as $name) {
            migrator_out(($name) . ': ' . (migrator_applied($db, $name) ? 'applied' : 'pending'));
        }
        exit(0);
    }

    migrator_preflight($db);

    foreach ($migrations as $name => $migration) {
        if (migrator_applied($db, $name)) {
            migrator_out($name . ': skipped');
            continue;
        }

        migrator_acquire_schema_lock($db);
        try {
            $migration($db);
            migrator_mark_applied($db, $name);
            migrator_out($name . ': applied');
        } finally {
            migrator_release_schema_lock($db);
        }
    }

    migrator_out('migrations: ok');
} catch (Throwable $exception) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'migrations: failed: ' . $exception->getMessage() . PHP_EOL);
    } else {
        http_response_code(500);
        echo htmlspecialchars('migrations: failed: ' . $exception->getMessage(), ENT_QUOTES, 'UTF-8');
    }
    exit(1);
}
