-- U13 synthetic S/T/L fixture. Caller sets @vs_profile to S, T or L.
-- This is not a migration. Use only in an exclusive disposable QA database.
SET SESSION cte_max_recursion_depth = 12000;
SET @vs_profile = UPPER(COALESCE(@vs_profile, ''));
SET @vs_prefix = CONCAT('loadu13_', LOWER(@vs_profile), '_');
SET @vs_missions = CASE @vs_profile WHEN 'S' THEN 1 WHEN 'T' THEN 10 WHEN 'L' THEN 100 ELSE 0 END;
SET @vs_target_width = CASE @vs_profile WHEN 'S' THEN 10 WHEN 'T' THEN 40 WHEN 'L' THEN 1000 ELSE 0 END;
SET @vs_other_width = CASE @vs_profile WHEN 'S' THEN 0 WHEN 'T' THEN 18 WHEN 'L' THEN 1 ELSE 0 END;
SET @vs_relations = CASE @vs_profile WHEN 'S' THEN 1 ELSE 2 END;
SET @vs_logs = CASE @vs_profile WHEN 'S' THEN 100 WHEN 'T' THEN 1000 WHEN 'L' THEN 10000 ELSE 0 END;
SET @vs_valid = @vs_missions > 0;

-- Invalid direct invocation is write-free. A validated external owner must also
-- reject every profile outside S/T/L before passing this file to MySQL.
SELECT IF(@vs_valid, 1, CAST('U13 profile must be S, T or L' AS UNSIGNED)) AS profile_guard;
START TRANSACTION;

-- Delete only rows carrying this fixture's exact binary prefix. Mission foreign
-- keys cascade to VMs, relations, the synthetic job and its log rows.
DELETE FROM deploy_missions
WHERE @vs_valid AND BINARY LEFT(mission_name, CHAR_LENGTH(@vs_prefix)) = BINARY @vs_prefix;
DELETE FROM deploy_packages
WHERE @vs_valid AND BINARY LEFT(package_name, CHAR_LENGTH(@vs_prefix)) = BINARY @vs_prefix;

INSERT INTO deploy_packages (package_name, package_basename, package_version, package_status)
WITH RECURSIVE package_numbers(n) AS (
    SELECT 1 UNION ALL SELECT n + 1 FROM package_numbers WHERE n < 3
)
SELECT CONCAT(@vs_prefix, 'package_', LPAD(n, 2, '0')),
       CONCAT('package_', LPAD(n, 2, '0')), '1.0.0', 'Aktiv'
FROM package_numbers
WHERE @vs_valid;

INSERT INTO deploy_missions (
    mission_name, mission_status, wds_vlan, hypervisor_datastorage,
    hypervisor_datacenter, domain, mission_notes
)
WITH RECURSIVE mission_numbers(n) AS (
    SELECT 1 UNION ALL SELECT n + 1 FROM mission_numbers WHERE n < @vs_missions
)
SELECT CONCAT(@vs_prefix, 'mission_', LPAD(n, 3, '0')),
       'Aktiv', 'U13-WDS', 'u13-datastore', 'U13-DC',
       'u13.invalid', 'synthetic U13 load fixture'
FROM mission_numbers
WHERE @vs_valid;

INSERT INTO deploy_vms (
    mission_id, vm_name, vm_hostname, vm_domain, vm_os, vm_ram, vm_cpu,
    vm_disk, vm_datastore, vm_datacenter, vm_guest_id, vm_notes
)
WITH RECURSIVE vm_numbers(n) AS (
    SELECT 1 UNION ALL SELECT n + 1 FROM vm_numbers WHERE n < @vs_target_width
), fixture_missions AS (
    SELECT id, CAST(SUBSTRING_INDEX(mission_name, '_', -1) AS UNSIGNED) AS mission_number
    FROM deploy_missions
    WHERE BINARY LEFT(mission_name, CHAR_LENGTH(@vs_prefix)) = BINARY @vs_prefix
)
SELECT m.id,
       CONCAT('U13', @vs_profile, 'M', LPAD(m.mission_number, 3, '0'), 'V', LPAD(v.n, 4, '0')),
       CONCAT('u13-', LOWER(@vs_profile), '-', LPAD(m.mission_number, 3, '0'), '-', LPAD(v.n, 4, '0')),
       'u13.invalid', 'Win11', '8192', '4', '64',
       'u13-datastore', 'U13-DC', 'windows9Server64Guest',
       'synthetic U13 load fixture'
FROM fixture_missions m CROSS JOIN vm_numbers v
WHERE @vs_valid
  AND v.n <= CASE WHEN m.mission_number = 1 THEN @vs_target_width ELSE @vs_other_width END;

INSERT INTO deploy_interfaces (vm_id, ip, subnet, gateway, dns1, dns2, vlan, mac, mode, type)
WITH RECURSIVE relation_numbers(n) AS (
    SELECT 1 UNION ALL SELECT n + 1 FROM relation_numbers WHERE n < @vs_relations
)
SELECT v.id, CONCAT('198.51.', MOD(v.id, 200), '.', 10 + r.n),
       '255.255.255.0', CONCAT('198.51.', MOD(v.id, 200), '.1'),
       '192.0.2.53', '',
       CASE WHEN r.n = 1 THEN 'U13-WDS' ELSE CONCAT('U13-LAN-', v.id) END,
       '', 'static', 'vmxnet3'
FROM deploy_vms v CROSS JOIN relation_numbers r
INNER JOIN deploy_missions m ON m.id = v.mission_id
WHERE @vs_valid
  AND BINARY LEFT(m.mission_name, CHAR_LENGTH(@vs_prefix)) = BINARY @vs_prefix;

INSERT INTO deploy_disks (vm_id, disk_name, disk_size, disk_type)
WITH RECURSIVE relation_numbers(n) AS (
    SELECT 1 UNION ALL SELECT n + 1 FROM relation_numbers WHERE n < @vs_relations
)
SELECT v.id, CONCAT('Disk ', r.n), 64 + (r.n * 16), 'thin'
FROM deploy_vms v CROSS JOIN relation_numbers r
INNER JOIN deploy_missions m ON m.id = v.mission_id
WHERE @vs_valid
  AND BINARY LEFT(m.mission_name, CHAR_LENGTH(@vs_prefix)) = BINARY @vs_prefix;

INSERT INTO deploy_vm_packages (vm_id, package_id)
SELECT v.id, p.id
FROM deploy_vms v
INNER JOIN deploy_missions m ON m.id = v.mission_id
CROSS JOIN deploy_packages p
WHERE @vs_valid
  AND BINARY LEFT(m.mission_name, CHAR_LENGTH(@vs_prefix)) = BINARY @vs_prefix
  AND BINARY LEFT(p.package_name, CHAR_LENGTH(@vs_prefix)) = BINARY @vs_prefix;

SET @vs_target_mission_name = IF(@vs_valid, CONCAT(@vs_prefix, 'mission_001'), NULL);
SET @vs_target_mission_id = IF(
    @vs_valid,
    (SELECT id FROM deploy_missions WHERE BINARY mission_name = BINARY @vs_target_mission_name LIMIT 1),
    NULL
);
SET @vs_target_vm_marker = IF(@vs_valid, CONCAT('U13', @vs_profile, 'M001V0001'), NULL);
SET @vs_job_id = NULL;
INSERT INTO deploy_jobs (mission_id, status, payload_json, terminal_reason_code)
SELECT @vs_target_mission_id, 'succeeded', JSON_OBJECT('mode', 'export', 'vm_ids', JSON_ARRAY()), 'completed'
WHERE @vs_valid AND @vs_target_mission_id IS NOT NULL;
SET @vs_job_id = IF(ROW_COUNT() = 1, LAST_INSERT_ID(), NULL);

INSERT INTO deploy_job_logs (job_id, seq, stream, line)
WITH RECURSIVE log_numbers(n) AS (
    SELECT 1 UNION ALL SELECT n + 1 FROM log_numbers WHERE n < @vs_logs
)
SELECT @vs_job_id, n, CASE WHEN MOD(n, 25) = 0 THEN 'stderr' ELSE 'stdout' END,
       CONCAT('U13 synthetic log line ', LPAD(n, 5, '0'), ' profile ', @vs_profile)
FROM log_numbers
WHERE @vs_valid AND @vs_job_id IS NOT NULL;
COMMIT;

-- This final row is the authoritative input contract for the k6 environment.
SELECT @vs_profile AS profile,
       @vs_valid AS valid,
       IF(@vs_valid, (SELECT COUNT(*) FROM deploy_missions WHERE BINARY LEFT(mission_name, CHAR_LENGTH(@vs_prefix)) = BINARY @vs_prefix), NULL) AS missions,
       IF(@vs_valid, (SELECT COUNT(*) FROM deploy_vms v INNER JOIN deploy_missions m ON m.id=v.mission_id WHERE BINARY LEFT(m.mission_name, CHAR_LENGTH(@vs_prefix)) = BINARY @vs_prefix), NULL) AS vms,
       IF(@vs_valid, (SELECT COUNT(*) FROM deploy_interfaces i INNER JOIN deploy_vms v ON v.id=i.vm_id INNER JOIN deploy_missions m ON m.id=v.mission_id WHERE BINARY LEFT(m.mission_name, CHAR_LENGTH(@vs_prefix)) = BINARY @vs_prefix), NULL) AS interfaces,
       IF(@vs_valid, (SELECT COUNT(*) FROM deploy_disks d INNER JOIN deploy_vms v ON v.id=d.vm_id INNER JOIN deploy_missions m ON m.id=v.mission_id WHERE BINARY LEFT(m.mission_name, CHAR_LENGTH(@vs_prefix)) = BINARY @vs_prefix), NULL) AS disks,
       IF(@vs_valid, (SELECT COUNT(*) FROM deploy_vm_packages vp INNER JOIN deploy_vms v ON v.id=vp.vm_id INNER JOIN deploy_missions m ON m.id=v.mission_id WHERE BINARY LEFT(m.mission_name, CHAR_LENGTH(@vs_prefix)) = BINARY @vs_prefix), NULL) AS vm_packages,
       IF(@vs_valid, (SELECT COUNT(*) FROM deploy_job_logs WHERE job_id=@vs_job_id), NULL) AS job_logs,
       @vs_target_mission_id AS target_mission_id,
       @vs_target_mission_name AS target_mission_name,
       IF(@vs_valid, @vs_target_width, NULL) AS target_vm_count,
       @vs_target_vm_marker AS target_vm_marker,
       @vs_job_id AS job_id;
