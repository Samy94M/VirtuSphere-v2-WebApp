-- Fresh schema must converge with Docker/WebAPI/lib/migrate.php.
-- ENUM/default literals below mirror PHP SSoT constants; future enum value
-- changes need both this file and an explicit MODIFY migration.
-- Tables are not declared in strict foreign-key order (deploy_esxi_inventory
-- references deploy_credentials before it is created), so defer FK validation
-- for the whole load, as mysqldump does; checks are re-enabled at the end.
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS deploy_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    role ENUM('admin','user') NOT NULL DEFAULT 'user',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    password_changed_at TIMESTAMP NULL,
    last_seen_at TIMESTAMP NULL,
    locked_until TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY user_name_unique (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deploy_missions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    mission_name VARCHAR(255) NOT NULL,
    mission_status VARCHAR(255) NOT NULL,
    mission_notes TEXT,
    wds_vlan VARCHAR(255),
    hypervisor_datastorage VARCHAR(255),
    hypervisor_datacenter VARCHAR(255),
    domain VARCHAR(255),
    -- Provenance snapshot (username, not an FK), mirroring deploy_vms.vm_creator.
    -- Stamped from the session on create; NULL for rows that predate migration 0015.
    mission_creator VARCHAR(255),
    -- ESXi autostart defaults (ADR-0025). They become the host's system_defaults;
    -- a VM inherits them unless it stores an own value. autostart_stop_action
    -- mirrors VIRTUSPHERE_AUTOSTART_STOP_ACTIONS (lib/deploy_constants.php, SSoT).
    autostart_enabled TINYINT(1) NOT NULL DEFAULT 0,
    autostart_start_delay INT NOT NULL DEFAULT 120,
    autostart_stop_delay INT NOT NULL DEFAULT 120,
    autostart_stop_action ENUM('guestShutdown','powerOff','suspend','none') NOT NULL DEFAULT 'guestShutdown',
    autostart_wait_for_heartbeat TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY mission_name_unique (mission_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deploy_vms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    mission_id INT NOT NULL,
    vm_name VARCHAR(255) NOT NULL,
    vm_hostname VARCHAR(255) NOT NULL,
    vm_domain VARCHAR(255),
    vm_os VARCHAR(255),
    vm_ram VARCHAR(255),
    vm_cpu VARCHAR(255),
    vm_disk VARCHAR(255),
    vm_datastore VARCHAR(255),
    vm_datacenter VARCHAR(255),
    vm_guest_id VARCHAR(255),
    -- CPU/RAM hot-add options, applied only at VM creation (Paket F). Default on.
    cpu_hotplug TINYINT(1) NOT NULL DEFAULT 1,
    ram_hotplug TINYINT(1) NOT NULL DEFAULT 1,
    -- Per-VM ESXi autostart override (ADR-0025). -1 = inherit the mission default;
    -- 0 = no wait. The two are NOT interchangeable, see lib/deploy_constants.php.
    autostart_enabled TINYINT(1) NOT NULL DEFAULT 0,
    autostart_start_delay INT NOT NULL DEFAULT -1,
    autostart_stop_delay INT NOT NULL DEFAULT -1,
    vm_creator VARCHAR(255),
    -- Mirrors VIRTUSPHERE_STATUS_REGISTERED (lib/constants.php, the SSoT). Free-text
    -- status, so it sits outside the ENUM-sync check; keep both in step by hand.
    vm_status VARCHAR(64) NOT NULL DEFAULT '2/5 Registered',
    lifecycle_state ENUM('initializing','ready','deploying','deployed','os_installing','os_installed','failed') NOT NULL DEFAULT 'ready',
    mecm_sync_state ENUM('not_ready','pending','registered','failed') NOT NULL DEFAULT 'not_ready',
    mecm_id VARCHAR(255),
    updated TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    vm_notes TEXT,
    UNIQUE KEY mission_vm_unique (mission_id, vm_name),
    CONSTRAINT fk_deploy_vms_mission FOREIGN KEY (mission_id) REFERENCES deploy_missions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deploy_interfaces (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vm_id INT NOT NULL,
    ip VARCHAR(255) NOT NULL,
    subnet VARCHAR(255) NOT NULL,
    gateway VARCHAR(255) NOT NULL,
    dns1 VARCHAR(255),
    dns2 VARCHAR(255),
    vlan VARCHAR(255),
    mac VARCHAR(255),
    mode VARCHAR(255),
    type VARCHAR(255),
    KEY deploy_interfaces_mac_lookup (mac),
    CONSTRAINT fk_deploy_interfaces_vm FOREIGN KEY (vm_id) REFERENCES deploy_vms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deploy_disks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vm_id INT NOT NULL,
    disk_name VARCHAR(255) NOT NULL,
    disk_size BIGINT NOT NULL,
    disk_type VARCHAR(255) NOT NULL,
    CONSTRAINT fk_deploy_disks_vm_id FOREIGN KEY (vm_id) REFERENCES deploy_vms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deploy_packages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    package_name VARCHAR(255) NOT NULL,
    package_basename VARCHAR(255) NOT NULL DEFAULT '',
    package_version VARCHAR(255) NOT NULL,
    package_status VARCHAR(255) NOT NULL,
    retired_at TIMESTAMP NULL,
    -- Set when the version relink moved this row's VM assignments to a successor.
    -- The purge additionally requires NULL here: the relink removes the very
    -- reference the purge protection reads, so without this marker the row lost
    -- its protection through the one mechanism that made it worth protecting.
    assignments_relinked_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY package_name_unique (package_name),
    INDEX deploy_packages_basename_lookup (package_basename)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deploy_vm_packages (
    vm_id INT NOT NULL,
    package_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (vm_id, package_id),
    CONSTRAINT fk_deploy_vm_packages_vm FOREIGN KEY (vm_id) REFERENCES deploy_vms(id) ON DELETE CASCADE,
    CONSTRAINT fk_deploy_vm_packages_package FOREIGN KEY (package_id) REFERENCES deploy_packages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deploy_vlan (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vlan_name VARCHAR(255) NOT NULL,
    -- VLAN catalog becomes ESXi-owned (ADR-0023): retire instead of delete when a
    -- portgroup disappears from every host with a fresh successful fetch.
    retired_at TIMESTAMP NULL,
    UNIQUE KEY vlan_name_unique (vlan_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ESXi inventory cache (ADR-0023): a read-only mirror of what the registered
-- ESXi credentials report. ESXi is the source; this is only a display/warning
-- cache, never a block. kind ENUM is mirrored from VIRTUSPHERE_INVENTORY_KINDS.
CREATE TABLE IF NOT EXISTS deploy_esxi_inventory (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deploy_esxi_inventory_state (
    credential_id INT PRIMARY KEY,
    last_success_at TIMESTAMP NULL,
    last_attempt_at TIMESTAMP NULL,
    last_status VARCHAR(32) NULL,
    last_error_category VARCHAR(32) NULL,
    -- Exact job that produced last_status/last_attempt_at. The job and its log
    -- remain authoritative; deleting them at the retention boundary clears only
    -- this pointer, so System status never renders a dead link.
    last_job_id INT NULL,
    failure_streak INT NOT NULL DEFAULT 0,
    paused_until_credential_change TINYINT(1) NOT NULL DEFAULT 0,
    -- Capability facts of the last SUCCESSFUL pull (ADR-0023 amendment 3). All
    -- nullable, because NULL means "not known" (never pulled, or the module did
    -- not report it) and must never be read as false. A failed pull leaves them
    -- untouched: they describe the host, not the fetch.
    api_type VARCHAR(32) NULL,
    product_version VARCHAR(191) NULL,
    license_product VARCHAR(191) NULL,
    license_free TINYINT(1) NULL,
    in_ha_cluster TINYINT(1) NULL,
    in_maintenance TINYINT(1) NULL,
    -- Per-kind answered-at stamps (migration 0030): {"datastore": "...", ...},
    -- written for kinds whose every inventory query ANSWERED in a pull. Covers
    -- the two states row timestamps cannot: a kind known empty as of T, and a
    -- kind whose frozen rows outlived its failing query. Keys are validated in
    -- PHP against VIRTUSPHERE_INVENTORY_KINDS (deliberately no second ENUM).
    kind_freshness_json JSON NULL,
    INDEX deploy_esxi_inventory_state_last_job (last_job_id),
    CONSTRAINT fk_deploy_esxi_inventory_state_credential FOREIGN KEY (credential_id) REFERENCES deploy_credentials(id) ON DELETE CASCADE,
    CONSTRAINT fk_deploy_esxi_inventory_state_last_job FOREIGN KEY (last_job_id) REFERENCES deploy_jobs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- On-demand Ansible preflight result (migration 0023). Unlike the ESXi inventory
-- state above there is no scheduler: a row is written only when an operator hits
-- "Test", so last_checked_at is shown verbatim and the reader judges staleness.
-- last_status is a plain VARCHAR ('ok'/'warning'/'failed'), not a DB ENUM.
CREATE TABLE IF NOT EXISTS deploy_ansible_preflight_state (
    credential_id INT PRIMARY KEY,
    last_status VARCHAR(16) NOT NULL,
    last_checked_at TIMESTAMP NULL,
    last_component VARCHAR(64) NULL,
    CONSTRAINT fk_deploy_ansible_preflight_state_credential FOREIGN KEY (credential_id) REFERENCES deploy_credentials(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- deploy_tokens fiel mit dem Desktop-Client (ADR-0035, Migration 0034).

CREATE TABLE IF NOT EXISTS deploy_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(255) NOT NULL,
    category VARCHAR(32) NOT NULL DEFAULT 'system',
    log_message TEXT NOT NULL,
    user_id INT NULL,
    -- ADR-0032: request correlation, diagnostic only.
    correlation_id VARCHAR(32) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX deploy_logs_category_lookup (category),
    CONSTRAINT fk_deploy_logs_user FOREIGN KEY (user_id) REFERENCES deploy_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deploy_os (
    id INT AUTO_INCREMENT PRIMARY KEY,
    os_name VARCHAR(255) NOT NULL,
    os_status VARCHAR(255) NOT NULL,
    retired_at TIMESTAMP NULL,
    UNIQUE KEY os_name_unique (os_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deploy_accessToWebAPI (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ipAddress VARCHAR(45) NOT NULL,
    description VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deploy_migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(191) UNIQUE NOT NULL,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deploy_login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(191) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX login_attempt_lookup (username, ip_address, attempted_at),
    INDEX login_attempt_ip_lookup (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deploy_credentials (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deploy_jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    -- NULL for system jobs (e.g. ESXi inventory) that run without a mission (ADR-0023).
    mission_id INT NULL,
    user_id INT NULL,
    -- cancelling (migration 0031, ADR-0033): a running job whose cancel was
    -- requested; it keeps lock/heartbeat until the worker confirms at a step
    -- boundary or the reaper converges a dead worker to cancelled.
    status ENUM('queued','running','cancelling','succeeded','failed','cancelled','partial') NOT NULL DEFAULT 'queued',
    locked_at TIMESTAMP NULL,
    locked_by VARCHAR(191) NULL,
    heartbeat_at TIMESTAMP NULL,
    attempts INT NOT NULL DEFAULT 0,
    last_error TEXT NULL,
    payload_json JSON NULL,
    result_json JSON NULL,
    credential_esxi_id INT NULL,
    credential_ansible_id INT NULL,
    -- cancelled_at names the CONFIRMED end state only; the wish carries its
    -- own timestamp and actor below (migration 0031). cancel_requested_by is
    -- the historical user id, deliberately without FK: a deleted account must
    -- not erase who asked, and the job log names the user anyway.
    cancelled_at TIMESTAMP NULL,
    cancel_requested_at TIMESTAMP NULL,
    cancel_requested_by INT NULL,
    -- Scheduling (ADR-0022): scheduled_at is a UTC run-not-before time;
    -- group_id ties a staggered batch of per-VM jobs together.
    scheduled_at DATETIME NULL,
    group_id CHAR(12) NULL,
    -- Diagnostic correlation id (ADR-0032): ties the job to the portal request
    -- that enqueued it. Opaque, never authorization; NULL predates the id.
    correlation_id VARCHAR(32) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX deploy_jobs_mission_status (mission_id, status),
    INDEX deploy_jobs_schedule (status, scheduled_at),
    INDEX deploy_jobs_group (group_id),
    CONSTRAINT fk_deploy_jobs_mission FOREIGN KEY (mission_id) REFERENCES deploy_missions(id) ON DELETE CASCADE,
    CONSTRAINT fk_deploy_jobs_user FOREIGN KEY (user_id) REFERENCES deploy_users(id) ON DELETE SET NULL,
    CONSTRAINT fk_deploy_jobs_esxi_credential FOREIGN KEY (credential_esxi_id) REFERENCES deploy_credentials(id) ON DELETE SET NULL,
    CONSTRAINT fk_deploy_jobs_ansible_credential FOREIGN KEY (credential_ansible_id) REFERENCES deploy_credentials(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deploy_job_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_id INT NOT NULL,
    seq INT NOT NULL,
    stream ENUM('stdout','stderr','system') NOT NULL DEFAULT 'stdout',
    line TEXT NOT NULL,
    -- ADR-0032: the owning job's correlation id, filled by the insert helper.
    correlation_id VARCHAR(32) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY deploy_job_logs_job_seq_unique (job_id, seq),
    CONSTRAINT fk_deploy_job_logs_job FOREIGN KEY (job_id) REFERENCES deploy_jobs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deploy_settings (
    setting_key VARCHAR(191) PRIMARY KEY,
    setting_value TEXT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deploy_vm_status_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vm_id INT NOT NULL,
    lifecycle_state VARCHAR(64) NOT NULL,
    mecm_sync_state VARCHAR(64) NOT NULL,
    legacy_status VARCHAR(64) NOT NULL,
    note TEXT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    -- Retention scans by age (migration 0032); without the index the hourly
    -- purge full-scans a table that gets a row per state transition.
    INDEX deploy_vm_status_events_created (created_at),
    CONSTRAINT fk_deploy_vm_status_events_vm FOREIGN KEY (vm_id) REFERENCES deploy_vms(id) ON DELETE CASCADE,
    CONSTRAINT fk_deploy_vm_status_events_user FOREIGN KEY (created_by) REFERENCES deploy_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- MECM membership provenance (migration 0033, ADR-0034): which direct
-- membership rules are VirtuSphere's OWN (origin created, or explicitly
-- adopted through the portal). Reconciliation may remove only owned rules;
-- manual MECM rules stay untouched. Rows die with their VM (decision 1: a VM
-- delete is purely local, the MECM rules stand). collection_type and origin
-- are PHP-validated vocabularies (VIRTUSPHERE_MECM_RULE_*), deliberately no
-- ENUM mirror.
CREATE TABLE IF NOT EXISTS deploy_vm_mecm_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vm_id INT NOT NULL,
    collection_id VARCHAR(16) NOT NULL,
    collection_name VARCHAR(255) NOT NULL,
    collection_type VARCHAR(16) NOT NULL,
    origin VARCHAR(32) NOT NULL DEFAULT 'created',
    actor VARCHAR(191) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY deploy_vm_mecm_rules_unique (vm_id, collection_id),
    CONSTRAINT fk_deploy_vm_mecm_rules_vm FOREIGN KEY (vm_id) REFERENCES deploy_vms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deploy_client_events (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deploy_integration_heartbeats (
    source VARCHAR(64) PRIMARY KEY,
    last_seen_at TIMESTAMP NULL,
    last_checked_at TIMESTAMP NULL,
    last_status VARCHAR(8) NOT NULL DEFAULT 'ok',
    last_detail VARCHAR(2048) NULL,
    last_ip VARCHAR(45) NOT NULL DEFAULT '',
    interval_seconds INT NOT NULL DEFAULT 60,
    beat_count BIGINT NOT NULL DEFAULT 0,
    report_version TINYINT UNSIGNED NOT NULL DEFAULT 1,
    last_event VARCHAR(16) NOT NULL DEFAULT 'heartbeat',
    last_run_id VARCHAR(32) NULL,
    last_attempt_at TIMESTAMP NULL,
    last_result_at TIMESTAMP NULL,
    last_success_at TIMESTAMP NULL,
    last_failure_at TIMESTAMP NULL,
    last_error_category VARCHAR(64) NULL,
    last_duration_ms INT UNSIGNED NULL,
    failure_streak INT UNSIGNED NOT NULL DEFAULT 0,
    last_summary JSON NULL,
    last_script_version VARCHAR(32) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rate-limit store for machine_api_audit_warning(), not an audit trail. Keyed by
-- (category, tag, scope) so one noisy caller cannot silence the same tag for
-- every other client; `suppressed` keeps the count a bare timestamp loses.
CREATE TABLE IF NOT EXISTS deploy_audit_throttle (
    category VARCHAR(32) NOT NULL,
    tag VARCHAR(64) NOT NULL,
    scope VARCHAR(64) NOT NULL DEFAULT '',
    last_written_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    suppressed INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (category, tag, scope)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO deploy_accessToWebAPI (ipAddress, description)
VALUES ('127.0.0.1', 'Lokaler Host')
ON DUPLICATE KEY UPDATE description = VALUES(description);

SET FOREIGN_KEY_CHECKS = 1;
