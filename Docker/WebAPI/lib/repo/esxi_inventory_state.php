<?php

declare(strict_types=1);

/**
 * Records a successful fetch: clears the failure streak and pause, and stores
 * the capability facts of this pull.
 *
 * $capabilities may be null (a caller that has none) or carry nulls of its own
 * (the module did not report a field). Both write SQL NULL, which the portal
 * reads as "not known". They are overwritten wholesale on every success rather
 * than merged, because a host that stopped reporting a fact no longer supports
 * the claim the old value made.
 *
 * @param array{api_type?:?string, product_version?:?string, license_product?:?string, license_free?:?bool, in_ha_cluster?:?bool, in_maintenance?:?bool}|null $capabilities
 */
function repo_esxi_inventory_record_success(mysqli $db, int $credentialId, ?array $capabilities = null, ?int $jobId = null): void
{
    $ok = 'ok';
    $jobId = $jobId !== null && $jobId > 0 ? $jobId : null;
    $capabilities ??= [];
    $apiType = $capabilities['api_type'] ?? null;
    $productVersion = $capabilities['product_version'] ?? null;
    $licenseProduct = $capabilities['license_product'] ?? null;
    // mysqli binds a PHP null as SQL NULL; a bool has to become 0/1 first, and
    // must stay null when the fact is unknown.
    $toFlag = static fn (mixed $value): ?int => $value === null ? null : ((bool) $value ? 1 : 0);
    $licenseFree = $toFlag($capabilities['license_free'] ?? null);
    $inHaCluster = $toFlag($capabilities['in_ha_cluster'] ?? null);
    $inMaintenance = $toFlag($capabilities['in_maintenance'] ?? null);

    $stmt = $db->prepare(
        'INSERT INTO deploy_esxi_inventory_state (credential_id, last_success_at, last_attempt_at, last_status, last_error_category, last_job_id, failure_streak, paused_until_credential_change, api_type, product_version, license_product, license_free, in_ha_cluster, in_maintenance)
         VALUES (?, NOW(), NOW(), ?, NULL, ?, 0, 0, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE last_success_at = NOW(), last_attempt_at = NOW(), last_status = ?, last_error_category = NULL, last_job_id = ?, failure_streak = 0, paused_until_credential_change = 0,
             api_type = ?, product_version = ?, license_product = ?, license_free = ?, in_ha_cluster = ?, in_maintenance = ?'
    );
    // INSERT: credential, status, job, three text facts and three flags;
    // UPDATE: status, job, the same three text facts and three flags.
    $stmt->bind_param(
        'isisssiiisisssiii',
        $credentialId,
        $ok,
        $jobId,
        $apiType,
        $productVersion,
        $licenseProduct,
        $licenseFree,
        $inHaCluster,
        $inMaintenance,
        $ok,
        $jobId,
        $apiType,
        $productVersion,
        $licenseProduct,
        $licenseFree,
        $inHaCluster,
        $inMaintenance
    );
    $stmt->execute();
}

/**
 * Records a failed fetch. Auth failures pause the auto-pull until the credential
 * changes (protects the ESXi account from lockout).
 */
function repo_esxi_inventory_record_failure(mysqli $db, int $credentialId, string $category, ?int $jobId = null): void
{
    $pause = inventory_error_pauses_credential($category) ? 1 : 0;
    $failed = 'failed';
    $jobId = $jobId !== null && $jobId > 0 ? $jobId : null;
    $stmt = $db->prepare(
        'INSERT INTO deploy_esxi_inventory_state (credential_id, last_attempt_at, last_status, last_error_category, last_job_id, failure_streak, paused_until_credential_change)
         VALUES (?, NOW(), ?, ?, ?, 1, ?)
         ON DUPLICATE KEY UPDATE last_attempt_at = NOW(), last_status = ?, last_error_category = ?, last_job_id = ?, failure_streak = failure_streak + 1, paused_until_credential_change = GREATEST(paused_until_credential_change, ?)'
    );
    $stmt->bind_param('issiissii', $credentialId, $failed, $category, $jobId, $pause, $failed, $category, $jobId, $pause);
    $stmt->execute();
}

/** Clears the auth pause + failure streak when a credential is (re)saved. */
function repo_esxi_inventory_clear_pause(mysqli $db, int $credentialId): void
{
    repo_execute($db, 'UPDATE deploy_esxi_inventory_state SET paused_until_credential_change = 0, failure_streak = 0 WHERE credential_id = ?', 'i', [$credentialId]);
}

/** @return array<string, mixed>|null */
function repo_esxi_inventory_state(mysqli $db, int $credentialId): ?array
{
    return repo_fetch_one($db, 'SELECT * FROM deploy_esxi_inventory_state WHERE credential_id = ? LIMIT 1', 'i', [$credentialId]);
}

/** @return array<int, array<string,mixed>> */
function repo_esxi_inventory_states(mysqli $db): array
{
    $stmt = $db->prepare('SELECT * FROM deploy_esxi_inventory_state');
    $stmt->execute();

    $states = [];
    foreach (repo_fetch_all($stmt->get_result()) as $row) {
        $states[(int) $row['credential_id']] = $row;
    }

    return $states;
}

/**
 * Counts all inventory kinds for every credential in one query.
 *
 * @return array<int,array<string,int>>
 */
function repo_esxi_inventory_counts(mysqli $db): array
{
    $stmt = $db->prepare('SELECT credential_id, kind, COUNT(*) AS item_count FROM deploy_esxi_inventory GROUP BY credential_id, kind');
    $stmt->execute();

    $counts = [];
    foreach (repo_fetch_all($stmt->get_result()) as $row) {
        $counts[(int) $row['credential_id']][(string) $row['kind']] = (int) $row['item_count'];
    }

    return $counts;
}

/**
 * Active inventory job per ESXi credential. The unique enqueue transaction
 * guarantees at most one queued/running row per credential.
 *
 * @return array<int,array<string,mixed>>
 */
function repo_esxi_inventory_pending_jobs(mysqli $db): array
{
    // The active SSoT (queued/running/cancelling, ADR-0033): a cancelling pull
    // still owns its credential and its link, and the enqueue dedupe reads
    // this same predicate through repo_create_system_job.
    $active = VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES;
    $placeholders = implode(', ', array_fill(0, count($active), '?'));
    $stmt = $db->prepare(
        'SELECT id, credential_esxi_id, status, correlation_id, created_at, locked_at
         FROM deploy_jobs
         WHERE mission_id IS NULL
           AND status IN (' . $placeholders . ')
           AND cancelled_at IS NULL
         ORDER BY id DESC'
    );
    $stmt->bind_param(str_repeat('s', count($active)), ...$active);
    $stmt->execute();

    $jobs = [];
    foreach (repo_fetch_all($stmt->get_result()) as $row) {
        $credentialId = (int) $row['credential_esxi_id'];
        if ($credentialId > 0 && !isset($jobs[$credentialId])) {
            $jobs[$credentialId] = $row;
        }
    }

    return $jobs;
}
