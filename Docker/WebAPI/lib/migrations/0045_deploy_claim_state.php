<?php

declare(strict_types=1);

/**
 * The persisted claim axis of the deploy service (Etappe 13R).
 *
 * It lives on the existing singleton runtime row rather than in a settings key
 * because it is runtime state with a state machine, not configuration: the
 * worker itself performs one of its transitions (pause_after_current -> paused)
 * and it has to do that with a compare-and-swap against the same row it already
 * reads for the supervisor contract.
 *
 * Additive and defaulted to `accepting`, so an existing installation keeps
 * taking jobs exactly as before this migration. A pause is only ever an
 * operator's decision; nothing here may start one.
 */
function migrate_0045_deploy_claim_state(mysqli $db): void
{
    migrator_add_column(
        $db,
        'deploy_runtime_identity',
        'claim_state',
        "VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'accepting' AFTER supervisor_contract"
    );
    migrator_add_column($db, 'deploy_runtime_identity', 'claim_changed_at', 'TIMESTAMP NULL AFTER claim_state');
    migrator_add_column($db, 'deploy_runtime_identity', 'claim_changed_by', 'INT NULL AFTER claim_changed_at');

    if (!migrator_check_exists($db, 'deploy_runtime_identity', 'deploy_runtime_identity_claim_check')) {
        $db->query("ALTER TABLE deploy_runtime_identity ADD CONSTRAINT deploy_runtime_identity_claim_check
            CHECK (claim_state IN ('accepting','pause_after_current','paused'))");
    }
    // ON DELETE SET NULL, like every other actor reference: a deleted account
    // must not take the pause with it, and the presenter already has a fallback
    // for an actor that no longer exists.
    if (!migrator_foreign_key_exists($db, 'deploy_runtime_identity', 'fk_deploy_runtime_identity_claim_by')) {
        $db->query('ALTER TABLE deploy_runtime_identity ADD CONSTRAINT fk_deploy_runtime_identity_claim_by
            FOREIGN KEY (claim_changed_by) REFERENCES deploy_users(id) ON DELETE SET NULL');
    }

    migrator_out('0045: deploy claim state added, defaulting to accepting');
}
