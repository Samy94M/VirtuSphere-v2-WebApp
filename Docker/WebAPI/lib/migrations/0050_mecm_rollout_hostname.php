<?php

declare(strict_types=1);

/**
 * The rollout hostname MECM actually receives (Etappe 14D, ADR-0043).
 *
 * Until now the device-sync imported `vm_name`, the ESXi identity, as the MECM
 * device name; `vm_hostname` was a desired value the client applied later by
 * renaming Windows. Two consequences made that untenable: the MECM object was
 * named after the hypervisor rather than after the machine, and `vm_hostname`
 * was live-editable, so a correction typed after the hand-off silently
 * reinterpreted a device MECM had already accepted.
 *
 * Three internal runtime columns fix that, and none of them is a second desired
 * value. `deploy_vms.vm_hostname` remains the ONE editable business SSoT.
 *
 *  - `mecm_rollout_hostname` is the name VirtuSphere hands THIS rollout to MECM
 *    and the PXE client. It is never a claimed live Windows name and never a
 *    read-back from MECM.
 *  - `mecm_rollout_revision` is the monotone fence over every mutating callback
 *    of this rollout, so a late `updateDevice`, `reportMembership` or client ACK
 *    from the previous run cannot close the queue the reset just opened.
 *  - `mecm_previous_id` is a delete tombstone. After a reset it keeps the
 *    hand-off fail-closed until the administrator has really removed the old
 *    device in MECM; VirtuSphere never deletes there.
 *
 * `deploy_vm_hostname_claims` makes the effective name globally unique inside
 * the transaction that assigns it. A preceding `SELECT` cannot: two missions
 * created in parallel both read "free" and both write. The primary key does the
 * deciding, so the loser gets a duplicate-key error rather than a second device
 * with the same Windows name.
 */
function migrate_0050_mecm_rollout_hostname(mysqli $db): void
{
    // --- Preflight BEFORE any DDL ------------------------------------------
    //
    // Reported, not repaired. A collision is a real operator decision about
    // which of two VMs keeps the name, and picking one here would silently
    // rename somebody's machine.
    migrate_0050_preflight($db);

    migrator_add_column($db, 'deploy_vms', 'mecm_rollout_hostname', 'VARCHAR(255) NULL AFTER mecm_id');
    migrator_add_column($db, 'deploy_vms', 'mecm_rollout_revision', 'BIGINT UNSIGNED NULL AFTER mecm_rollout_hostname');
    migrator_add_column($db, 'deploy_vms', 'mecm_previous_id', 'VARCHAR(255) NULL AFTER mecm_rollout_revision');

    if (!migrator_table_exists($db, 'deploy_vm_hostname_claims')) {
        // `hostname_key` is the primary key and carries the case-insensitive
        // decision in the DATA, not in a collation: the key stored here is
        // already the output of mecm_hostname_key(), so an ascii_bin column
        // compares exactly what PHP compared. Relying on a case-insensitive
        // collation instead would put the folding rule in two places, and the
        // two would answer differently for the Turkish dotless i the moment
        // anybody changed the column's collation.
        //
        // One VM may hold two rows: its frozen rollout name and its new desired
        // name. A key belongs to exactly one VM, which is the whole point.
        $db->query(
            'CREATE TABLE deploy_vm_hostname_claims (
                hostname_key VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                vm_id INT NOT NULL,
                desired_claim TINYINT(1) NOT NULL DEFAULT 0,
                rollout_claim TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (hostname_key),
                INDEX deploy_vm_hostname_claims_vm (vm_id),
                CONSTRAINT deploy_vm_hostname_claims_purpose CHECK (desired_claim = 1 OR rollout_claim = 1),
                CONSTRAINT fk_deploy_vm_hostname_claims_vm FOREIGN KEY (vm_id) REFERENCES deploy_vms(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    migrate_0050_backfill($db);

    migrator_out('0050: MECM receives the frozen rollout hostname, fenced by a revision and a globally unique claim');
}

/**
 * Blocks on a collision of two VALID hostnames and merely names the rest.
 *
 * An invalid legacy value is deliberately not a blocker: it is allowed to
 * migrate as existing stock, it gets no claim, and it is excluded from the next
 * reset or first import until somebody corrects it. Refusing the whole
 * migration for it would make an unrelated schema upgrade hostage to a typo
 * from years ago.
 */
function migrate_0050_preflight(mysqli $db): void
{
    if (!migrator_column_exists($db, 'deploy_vms', 'vm_hostname')) {
        return;
    }

    $prefix = VIRTUSPHERE_TEMPLATE_PREFIX;
    $stmt = $db->prepare(
        'SELECT v.id, v.vm_name, v.vm_hostname, m.mission_name
           FROM deploy_vms v
           JOIN deploy_missions m ON m.id = v.mission_id
          WHERE m.mission_name NOT LIKE CONCAT(?, ?)
          ORDER BY v.id'
    );
    $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix);
    $wildcard = '%';
    $stmt->bind_param('ss', $escaped, $wildcard);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    /** @var array<string, array{vm_name:string, mission_name:string}> $seen */
    $seen = [];
    $collisions = [];
    $unusable = [];
    foreach ($rows as $row) {
        $hostname = (string) $row['vm_hostname'];
        if (!mecm_hostname_is_rollout_valid($hostname)) {
            $unusable[] = sprintf(
                'VM %s (Mission %s, id %d): hostname %s',
                (string) $row['vm_name'],
                (string) $row['mission_name'],
                (int) $row['id'],
                $hostname === '' ? '<empty>' : $hostname
            );
            continue;
        }

        $key = mecm_hostname_key($hostname);
        if (isset($seen[$key])) {
            $collisions[] = sprintf(
                'hostname %s: VM %s (Mission %s) and VM %s (Mission %s)',
                $hostname,
                $seen[$key]['vm_name'],
                $seen[$key]['mission_name'],
                (string) $row['vm_name'],
                (string) $row['mission_name']
            );
            continue;
        }
        $seen[$key] = ['vm_name' => (string) $row['vm_name'], 'mission_name' => (string) $row['mission_name']];
    }

    // Bounded on purpose: a list of every row in a large estate scrolls the
    // actionable head off the operator's screen, and the count says how much is
    // behind it.
    if ($unusable !== []) {
        migrator_out(sprintf(
            '0050: %d VM(s) keep a hostname that cannot start a MECM rollout; they migrate as existing stock, get no claim and stay excluded from reset/first import until corrected:',
            count($unusable)
        ));
        foreach (array_slice($unusable, 0, VIRTUSPHERE_MECM_ROLLOUT_PREFLIGHT_REPORT_LIMIT) as $line) {
            migrator_out('  - ' . $line);
        }
        if (count($unusable) > VIRTUSPHERE_MECM_ROLLOUT_PREFLIGHT_REPORT_LIMIT) {
            migrator_out(sprintf('  - ... and %d more', count($unusable) - VIRTUSPHERE_MECM_ROLLOUT_PREFLIGHT_REPORT_LIMIT));
        }
    }

    if ($collisions === []) {
        return;
    }

    $message = sprintf(
        '0050 aborted: %d valid hostname(s) are claimed by more than one non-template VM. The rollout name must be globally unique, so one of each pair has to be corrected in the portal before this migration can run:',
        count($collisions)
    );
    foreach (array_slice($collisions, 0, VIRTUSPHERE_MECM_ROLLOUT_PREFLIGHT_REPORT_LIMIT) as $line) {
        $message .= "\n  - " . $line;
    }
    if (count($collisions) > VIRTUSPHERE_MECM_ROLLOUT_PREFLIGHT_REPORT_LIMIT) {
        $message .= sprintf("\n  - ... and %d more", count($collisions) - VIRTUSPHERE_MECM_ROLLOUT_PREFLIGHT_REPORT_LIMIT);
    }

    throw new RuntimeException($message);
}

/**
 * Existing stock starts at revision 1 with the snapshot equal to the desired
 * value and no tombstone: that is the state the pre-cutover compatibility
 * branch accepts, so an estate that migrates today keeps working with the MECM
 * scripts and client packages it already has.
 *
 * Templates get NULL everywhere. They have no rollout, no revision and nothing
 * to reset, and a snapshot on a template would be copied into every clone.
 */
function migrate_0050_backfill(mysqli $db): void
{
    $prefix = VIRTUSPHERE_TEMPLATE_PREFIX;
    $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix);
    $wildcard = '%';
    $initialRevision = VIRTUSPHERE_MECM_ROLLOUT_REVISION_INITIAL;

    $stmt = $db->prepare(
        'UPDATE deploy_vms v
           JOIN deploy_missions m ON m.id = v.mission_id
            SET v.mecm_rollout_hostname = v.vm_hostname,
                v.mecm_rollout_revision = ?,
                v.mecm_previous_id = NULL,
                v.updated_at = v.updated_at
          WHERE m.mission_name NOT LIKE CONCAT(?, ?)
            AND v.mecm_rollout_revision IS NULL'
    );
    $stmt->bind_param('iss', $initialRevision, $escaped, $wildcard);
    $stmt->execute();
    $adopted = $stmt->affected_rows;

    // Templates: explicit rather than "whatever the column default happens to
    // be", so a re-run after a partially applied migration converges.
    $stmt = $db->prepare(
        'UPDATE deploy_vms v
           JOIN deploy_missions m ON m.id = v.mission_id
            SET v.mecm_rollout_hostname = NULL,
                v.mecm_rollout_revision = NULL,
                v.mecm_previous_id = NULL,
                v.updated_at = v.updated_at
          WHERE m.mission_name LIKE CONCAT(?, ?)'
    );
    $stmt->bind_param('ss', $escaped, $wildcard);
    $stmt->execute();

    // Claims for everything that can actually start a rollout. Desired and
    // rollout are the same value here, so one row carries both flags; they only
    // diverge once an edit lands on a VM whose snapshot is already frozen.
    $result = $db->query(
        "SELECT v.id, v.vm_hostname
           FROM deploy_vms v
           JOIN deploy_missions m ON m.id = v.mission_id
          WHERE v.mecm_rollout_revision IS NOT NULL
          ORDER BY v.id"
    );
    $claimed = 0;
    $stmt = $db->prepare(
        'INSERT INTO deploy_vm_hostname_claims (hostname_key, vm_id, desired_claim, rollout_claim)
         VALUES (?, ?, 1, 1)
         ON DUPLICATE KEY UPDATE vm_id = VALUES(vm_id), desired_claim = 1, rollout_claim = 1'
    );
    while ($row = $result->fetch_assoc()) {
        if (!mecm_hostname_is_rollout_valid((string) $row['vm_hostname'])) {
            continue;
        }
        $key = mecm_hostname_key((string) $row['vm_hostname']);
        $vmId = (int) $row['id'];
        $stmt->bind_param('si', $key, $vmId);
        $stmt->execute();
        $claimed++;
    }

    migrator_out(sprintf('0050: %d VM(s) adopted their desired hostname as rollout snapshot, %d hostname claim(s) recorded', $adopted, $claimed));
}
