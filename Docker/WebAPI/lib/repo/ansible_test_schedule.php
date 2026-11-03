<?php

declare(strict_types=1);

require_once __DIR__ . '/credentials.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/ansible_preflight.php';
require_once __DIR__ . '/deploy_job_service_state.php';
require_once __DIR__ . '/../ansible_test_config.php';

/** Claim at most one due credential; no database lock survives external work. */
function repo_ansible_test_claim_due(mysqli $db): ?array
{
    $hours = ansible_test_interval_hours($db);
    if ($hours === 0) {
        return null;
    }
    $candidate = repo_fetch_one($db,
        'SELECT c.id FROM deploy_credentials c LEFT JOIN deploy_ansible_preflight_state s ON s.credential_id = c.id
         WHERE c.type = ? AND (COALESCE(c.ansible_test_started_at, s.last_checked_at) IS NULL
         OR COALESCE(c.ansible_test_started_at, s.last_checked_at) <= TIMESTAMPADD(HOUR, -?, UTC_TIMESTAMP()))
         ORDER BY COALESCE(c.ansible_test_started_at, s.last_checked_at), c.id LIMIT 1',
        'si', [VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE, $hours]
    );
    if ($candidate === null) {
        return null;
    }
    return repo_transaction($db, static function () use ($db, $candidate): ?array {
        $id = (int) $candidate['id'];
        // Manual begin and credential edits lock this same row first.
        $locked = repo_fetch_one($db, 'SELECT id FROM deploy_credentials WHERE id = ? FOR UPDATE', 'i', [$id]);
        $hours = ansible_test_interval_hours($db);
        if ($locked === null || $hours === 0
            || !deploy_claim_state_allows_new_work(repo_deploy_claim_state($db)['state'])) {
            return null;
        }
        $due = repo_fetch_one($db,
            'SELECT c.config_revision FROM deploy_credentials c LEFT JOIN deploy_ansible_preflight_state s ON s.credential_id = c.id
             WHERE c.id = ? AND c.type = ? AND (COALESCE(c.ansible_test_started_at, s.last_checked_at) IS NULL
             OR COALESCE(c.ansible_test_started_at, s.last_checked_at) <= TIMESTAMPADD(HOUR, -?, UTC_TIMESTAMP()))',
            'isi', [$id, VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE, $hours]
        );
        if ($due === null) {
            return null;
        }
        $generation = repo_ansible_preflight_begin($db, $id, (int) $due['config_revision']);
        return ['credential' => repo_credential($db, $id, true), 'generation' => $generation];
    });
}
