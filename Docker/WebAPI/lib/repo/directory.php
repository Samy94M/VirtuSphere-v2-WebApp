<?php

declare(strict_types=1);

require_once __DIR__ . '/../directory_constants.php';
require_once __DIR__ . '/directory_users.php';
require_once __DIR__ . '/directory_state.php';
require_once __DIR__ . '/helpers.php';
/** @return array<string,mixed>|null */
function repo_directory_config(mysqli $db, bool $forUpdate = false): ?array
{
    $suffix = $forUpdate ? ' FOR UPDATE' : '';

    return repo_fetch_one($db, 'SELECT * FROM deploy_ad_config WHERE id = 1' . $suffix);
}

/** @return list<array<string,mixed>> */
function repo_directory_controllers(mysqli $db): array
{
    $stmt = $db->prepare(
        'SELECT c.*, s.config_revision AS state_config_revision, s.last_attempt_at,
                s.last_success_at, s.last_outcome, s.consecutive_transport_failures,
                s.retry_after, s.certificate_sha256, s.certificate_not_after
         FROM deploy_ad_controllers c
         LEFT JOIN deploy_ad_controller_state s ON s.controller_id = c.id
         WHERE c.config_id = 1
         ORDER BY c.priority, c.id'
    );
    $stmt->execute();

    return repo_fetch_all($stmt->get_result());
}

/** @return array<string,mixed>|null */
function repo_directory_controller(mysqli $db, int $controllerId): ?array
{
    return repo_fetch_one(
        $db,
        'SELECT c.*, s.config_revision AS state_config_revision, s.last_attempt_at,
                s.last_success_at, s.last_outcome, s.consecutive_transport_failures,
                s.retry_after, s.certificate_sha256, s.certificate_not_after
         FROM deploy_ad_controllers c
         LEFT JOIN deploy_ad_controller_state s ON s.controller_id = c.id
         WHERE c.id = ? AND c.config_id = 1 LIMIT 1',
        'i',
        [$controllerId]
    );
}

/**
 * Controllers admitted by a successful manual test for the active revision.
 * A runtime cooldown only moves a controller behind healthy candidates; if all
 * are cooling down, the least stale one remains available for a recovery try.
 *
 * @return list<array<string,mixed>>
 */
function repo_directory_login_controllers(mysqli $db, int $revision): array
{
    $stmt = $db->prepare(
        'SELECT c.*, s.last_outcome, s.last_attempt_at, s.last_success_at,
                s.consecutive_transport_failures, s.retry_after,
                (s.retry_after IS NOT NULL AND s.retry_after > NOW()) AS is_cooling
         FROM deploy_ad_controllers c
         LEFT JOIN deploy_ad_controller_state s ON s.controller_id = c.id
         WHERE c.config_id = 1 AND c.enabled = 1 AND c.validated_revision = ?
         ORDER BY (s.retry_after IS NOT NULL AND s.retry_after > NOW()) ASC,
                  COALESCE(s.retry_after, \'1970-01-01 00:00:00\') ASC,
                  c.priority ASC, c.id ASC'
    );
    $stmt->bind_param('i', $revision);
    $stmt->execute();

    return directory_select_login_controllers(repo_fetch_all($stmt->get_result()));
}

/**
 * Never fan one request through every controller that is deliberately cooling
 * down. Ready controllers retain priority order; if none is ready, exactly the
 * least-stale candidate gets one recovery attempt.
 *
 * @param list<array<string,mixed>> $controllers
 * @return list<array<string,mixed>>
 */
function directory_select_login_controllers(array $controllers): array
{
    $ready = array_values(array_filter(
        $controllers,
        static fn (array $controller): bool => (int) ($controller['is_cooling'] ?? 0) === 0
    ));
    if ($ready !== []) {
        return $ready;
    }

    return $controllers === [] ? [] : [$controllers[0]];
}

function repo_directory_pause_controllers_for_bind_rejection(mysqli $db, int $revision): void
{
    $reason = VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_BIND_REJECTED;
    $stmt = $db->prepare(
        'UPDATE deploy_ad_config
         SET automatic_bind_blocked_revision = ?, automatic_bind_blocked_at = NOW(),
             automatic_bind_blocked_reason = ?, updated_at = updated_at
         WHERE id = 1 AND revision = ?'
    );
    $stmt->bind_param('isi', $revision, $reason, $revision);
    $stmt->execute();
}

function repo_directory_clear_bind_block(mysqli $db, int $revision): void
{
    $stmt = $db->prepare(
        'UPDATE deploy_ad_config
         SET automatic_bind_blocked_revision = NULL, automatic_bind_blocked_at = NULL,
             automatic_bind_blocked_reason = NULL, updated_at = updated_at
         WHERE id = 1 AND revision = ?'
    );
    $stmt->bind_param('i', $revision);
    $stmt->execute();
}

/**
 * Saves the complete candidate as one revision. The caller has already
 * validated/encrypted it and, while AD is active, tested it before this write.
 *
 * @param array{bind_upn:string,bind_secret_ciphertext:string,ca_certificate_pem:string,user_search_base_dn:string,default_naming_context:string} $candidate
 */
function repo_directory_save_config(mysqli $db, array $candidate, int $actorId, ?int $testedControllerId = null, int $expectedRevision = 0): int
{
    return repo_transaction($db, function () use ($db, $candidate, $actorId, $testedControllerId, $expectedRevision): int {
        $current = repo_directory_config($db, true);
        $currentRevision = $current === null ? 0 : (int) $current['revision'];
        if ($expectedRevision !== $currentRevision) {
            throw new RuntimeException('directory_config_stale');
        }
        $revision = $current === null ? 1 : (int) $current['revision'] + 1;
        if ($current === null) {
            $enabled = 0;
            $id = 1;
            $stmt = $db->prepare(
                'INSERT INTO deploy_ad_config
                    (id, enabled, revision, default_naming_context, user_search_base_dn,
                     bind_upn, bind_secret_ciphertext, ca_certificate_pem, created_by, updated_by)
                 VALUES (?, ?, ?, NULLIF(?, \'\'), NULLIF(?, \'\'), ?, ?, ?, ?, ?)'
            );
            $stmt->bind_param(
                'iiisssssii',
                $id,
                $enabled,
                $revision,
                $candidate['default_naming_context'],
                $candidate['user_search_base_dn'],
                $candidate['bind_upn'],
                $candidate['bind_secret_ciphertext'],
                $candidate['ca_certificate_pem'],
                $actorId,
                $actorId
            );
            $stmt->execute();
        } else {
            $stmt = $db->prepare(
                'UPDATE deploy_ad_config
                 SET revision = ?, default_naming_context = NULLIF(?, \'\'),
                     user_search_base_dn = NULLIF(?, \'\'), bind_upn = ?,
                     bind_secret_ciphertext = ?, ca_certificate_pem = ?,
                     automatic_bind_blocked_revision = NULL,
                     automatic_bind_blocked_at = NULL,
                     automatic_bind_blocked_reason = NULL,
                     updated_by = ?, updated_at = NOW()
                 WHERE id = 1'
            );
            $stmt->bind_param(
                'isssssi',
                $revision,
                $candidate['default_naming_context'],
                $candidate['user_search_base_dn'],
                $candidate['bind_upn'],
                $candidate['bind_secret_ciphertext'],
                $candidate['ca_certificate_pem'],
                $actorId
            );
            $stmt->execute();
        }

        if ($testedControllerId !== null && $testedControllerId > 0) {
            $stmt = $db->prepare('UPDATE deploy_ad_controllers SET validated_revision = ?, validated_at = NOW(), updated_by = ?, updated_at = NOW() WHERE id = ? AND config_id = 1');
            $stmt->bind_param('iii', $revision, $actorId, $testedControllerId);
            $stmt->execute();
        }

        return $revision;
    });
}

function repo_directory_set_config_enabled(mysqli $db, bool $enabled, int $actorId): void
{
    $enabledInt = $enabled ? 1 : 0;
    $stmt = $db->prepare('UPDATE deploy_ad_config SET enabled = ?, updated_by = ?, updated_at = NOW() WHERE id = 1');
    $stmt->bind_param('ii', $enabledInt, $actorId);
    $stmt->execute();
}

function repo_directory_set_discovered_naming_context(mysqli $db, string $namingContext, int $actorId): void
{
    $stmt = $db->prepare(
        'UPDATE deploy_ad_config
         SET default_naming_context = ?,
             user_search_base_dn = COALESCE(user_search_base_dn, ?),
             updated_by = ?, updated_at = NOW()
         WHERE id = 1 AND default_naming_context IS NULL'
    );
    $stmt->bind_param('ssi', $namingContext, $namingContext, $actorId);
    $stmt->execute();
}

function repo_directory_delete_config(mysqli $db): void
{
    $db->query('DELETE FROM deploy_ad_config WHERE id = 1');
}

function repo_directory_add_controller(mysqli $db, string $host, int $port, int $actorId): int
{
    return repo_transaction($db, function () use ($db, $host, $port, $actorId): int {
        $nextPriority = (int) repo_scalar($db, 'SELECT COALESCE(MAX(priority), 0) + 1 FROM deploy_ad_controllers WHERE config_id = 1 FOR UPDATE');
        $configId = 1;
        $enabled = 0;
        $stmt = $db->prepare('INSERT INTO deploy_ad_controllers (config_id, host, port, priority, enabled, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('isiiiii', $configId, $host, $port, $nextPriority, $enabled, $actorId, $actorId);
        $stmt->execute();

        return (int) $db->insert_id;
    });
}

function repo_directory_mark_controller_validated(mysqli $db, int $controllerId, int $revision, int $actorId): void
{
    $stmt = $db->prepare('UPDATE deploy_ad_controllers SET validated_revision = ?, validated_at = NOW(), updated_by = ?, updated_at = NOW() WHERE id = ? AND config_id = 1');
    $stmt->bind_param('iii', $revision, $actorId, $controllerId);
    $stmt->execute();
}

function repo_directory_apply_controller_test_success(mysqli $db, int $controllerId, int $revision, string $namingContext, int $actorId): bool
{
    return repo_transaction($db, function () use ($db, $controllerId, $revision, $namingContext, $actorId): bool {
        $config = repo_directory_config($db, true);
        if ($config === null || (int) $config['revision'] !== $revision) {
            return false;
        }
        repo_directory_set_discovered_naming_context($db, $namingContext, $actorId);
        repo_directory_mark_controller_validated($db, $controllerId, $revision, $actorId);
        repo_directory_clear_bind_block($db, $revision);

        return true;
    });
}

function repo_directory_clear_controller_validation(mysqli $db, int $controllerId, int $revision, int $actorId): void
{
    $stmt = $db->prepare('UPDATE deploy_ad_controllers SET validated_revision = NULL, validated_at = NULL, enabled = 0, updated_by = ?, updated_at = NOW() WHERE id = ? AND config_id = 1 AND (validated_revision IS NULL OR validated_revision = ?)');
    $stmt->bind_param('iii', $actorId, $controllerId, $revision);
    $stmt->execute();
}

function repo_directory_set_controller_enabled(mysqli $db, int $controllerId, bool $enabled, int $actorId): void
{
    $enabledInt = $enabled ? 1 : 0;
    $stmt = $db->prepare('UPDATE deploy_ad_controllers SET enabled = ?, updated_by = ?, updated_at = NOW() WHERE id = ? AND config_id = 1');
    $stmt->bind_param('iii', $enabledInt, $actorId, $controllerId);
    $stmt->execute();
}

/** @return 'ok'|'missing'|'retest'|'last_controller' */
function repo_directory_change_controller_enabled(mysqli $db, int $controllerId, bool $enabled, int $actorId): string
{
    return repo_transaction($db, function () use ($db, $controllerId, $enabled, $actorId): string {
        $config = repo_directory_config($db, true);
        $controllers = repo_fetch_all($db->query('SELECT * FROM deploy_ad_controllers WHERE config_id = 1 ORDER BY priority, id FOR UPDATE'));
        $target = null;
        foreach ($controllers as $controller) {
            if ((int) $controller['id'] === $controllerId) {
                $target = $controller;
                break;
            }
        }
        if ($config === null || $target === null) {
            return 'missing';
        }
        $revision = (int) $config['revision'];
        if ($enabled && (int) ($target['validated_revision'] ?? 0) !== $revision) {
            return 'retest';
        }
        if (!$enabled && (int) $config['enabled'] === 1 && (int) $target['enabled'] === 1) {
            $usable = array_filter($controllers, static fn (array $row): bool =>
                (int) $row['enabled'] === 1 && (int) ($row['validated_revision'] ?? 0) === $revision
            );
            if (count($usable) <= 1) {
                return 'last_controller';
            }
        }
        repo_directory_set_controller_enabled($db, $controllerId, $enabled, $actorId);

        return 'ok';
    });
}

function repo_directory_move_controller(mysqli $db, int $controllerId, int $direction, int $actorId): void
{
    repo_transaction($db, function () use ($db, $controllerId, $direction, $actorId): void {
        $current = repo_fetch_one($db, 'SELECT id, priority FROM deploy_ad_controllers WHERE id = ? AND config_id = 1 FOR UPDATE', 'i', [$controllerId]);
        if ($current === null) {
            return;
        }
        $operator = $direction < 0 ? '<' : '>';
        $order = $direction < 0 ? 'DESC' : 'ASC';
        // csp-allow: interpolated-sql
        $other = repo_fetch_one(
            $db,
            'SELECT id, priority FROM deploy_ad_controllers WHERE config_id = 1 AND priority ' . $operator . ' ? ORDER BY priority ' . $order . ' LIMIT 1 FOR UPDATE',
            'i',
            [(int) $current['priority']]
        );
        if ($other === null) {
            return;
        }

        $zero = 0;
        $stmt = $db->prepare('UPDATE deploy_ad_controllers SET priority = ?, updated_by = ?, updated_at = NOW() WHERE id = ?');
        $stmt->bind_param('iii', $zero, $actorId, $controllerId);
        $stmt->execute();
        $currentPriority = (int) $current['priority'];
        $otherId = (int) $other['id'];
        $stmt->bind_param('iii', $currentPriority, $actorId, $otherId);
        $stmt->execute();
        $otherPriority = (int) $other['priority'];
        $stmt->bind_param('iii', $otherPriority, $actorId, $controllerId);
        $stmt->execute();
    });
}

function repo_directory_delete_controller(mysqli $db, int $controllerId): void
{
    repo_transaction($db, function () use ($db, $controllerId): void {
        $stmt = $db->prepare('DELETE FROM deploy_ad_controllers WHERE id = ? AND config_id = 1');
        $stmt->bind_param('i', $controllerId);
        $stmt->execute();

        $rows = repo_directory_controllers($db);
        $priority = 1;
        $stmt = $db->prepare('UPDATE deploy_ad_controllers SET priority = ? WHERE id = ?');
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $stmt->bind_param('ii', $priority, $id);
            $stmt->execute();
            $priority++;
        }
    });
}

/** @return 'ok'|'missing'|'last_controller' */
function repo_directory_delete_controller_guarded(mysqli $db, int $controllerId): string
{
    return repo_transaction($db, function () use ($db, $controllerId): string {
        $config = repo_directory_config($db, true);
        $controllers = repo_fetch_all($db->query('SELECT * FROM deploy_ad_controllers WHERE config_id = 1 ORDER BY priority, id FOR UPDATE'));
        $target = null;
        foreach ($controllers as $controller) {
            if ((int) $controller['id'] === $controllerId) {
                $target = $controller;
                break;
            }
        }
        if ($target === null) {
            return 'missing';
        }
        if ($config !== null && (int) $config['enabled'] === 1 && (int) $target['enabled'] === 1) {
            $revision = (int) $config['revision'];
            $usable = array_filter($controllers, static fn (array $row): bool =>
                (int) $row['enabled'] === 1 && (int) ($row['validated_revision'] ?? 0) === $revision
            );
            if (count($usable) <= 1) {
                return 'last_controller';
            }
        }
        $stmt = $db->prepare('DELETE FROM deploy_ad_controllers WHERE id = ? AND config_id = 1');
        $stmt->bind_param('i', $controllerId);
        $stmt->execute();
        $remaining = repo_fetch_all($db->query('SELECT id FROM deploy_ad_controllers WHERE config_id = 1 ORDER BY priority, id'));
        $priority = 1;
        $stmt = $db->prepare('UPDATE deploy_ad_controllers SET priority = ? WHERE id = ?');
        foreach ($remaining as $row) {
            $id = (int) $row['id'];
            $stmt->bind_param('ii', $priority, $id);
            $stmt->execute();
            $priority++;
        }

        return 'ok';
    });
}
