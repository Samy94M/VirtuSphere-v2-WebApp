<?php

declare(strict_types=1);

require_once __DIR__ . '/directory_constants.php';

/**
 * Traffic-light state for one controller row (repo_directory_controllers()
 * shape: deploy_ad_controllers left-joined with deploy_ad_controller_state).
 *
 * 'unknown': disabled, or not admitted for the config's current revision
 * (never tested, or a configuration change invalidated the last test) - it
 * is not part of the usable pool at all, so it cannot itself be failing.
 * 'danger': admitted, but its last recorded observation (from a real login,
 * session recheck or manual test - directory_observe_controller() writes
 * every one of them) was not VIRTUSPHERE_DIRECTORY_OUTCOME_OK. This is the
 * gap a schema-only "enabled + validated_revision" check misses: a
 * controller stays admitted across ordinary automatic failures, because
 * automatic observations never clear validation (only a failed *manual*
 * re-test does, via repo_directory_clear_controller_validation()); real
 * traffic can keep failing against it while it still counts as usable.
 * 'warning': admitted, last observation was ok, but its certificate (known
 * only from its last manual test) expires within
 * VIRTUSPHERE_DIRECTORY_CERTIFICATE_EXPIRY_WARNING_DAYS.
 * 'stale': admitted, last observation was ok and the certificate is not
 * soon-expiring, but that success is older than
 * VIRTUSPHERE_DIRECTORY_OBSERVATION_STALE_AFTER_DAYS. Only a passing result
 * ages this way (matches ansible_preflight_ampel()'s rule): greying out a
 * known failure would hide it, so 'danger' never turns 'stale'.
 * 'ok': admitted, last observation ok, certificate not soon-expiring, and
 * that success is still recent enough to count as current evidence.
 *
 * @param array<string,mixed> $controller
 */
function directory_controller_ampel(array $controller, int $currentRevision, ?int $now = null): string
{
    if ((int) $controller['enabled'] !== 1 || (int) ($controller['validated_revision'] ?? 0) !== $currentRevision) {
        return 'unknown';
    }
    $lastOutcome = (string) ($controller['last_outcome'] ?? '');
    if ($lastOutcome !== VIRTUSPHERE_DIRECTORY_OUTCOME_OK) {
        return 'danger';
    }

    $now ??= time();
    $notAfter = trim((string) ($controller['certificate_not_after'] ?? ''));
    if ($notAfter !== '') {
        $notAfterEpoch = strtotime($notAfter . ' UTC');
        if ($notAfterEpoch !== false && $notAfterEpoch < $now + VIRTUSPHERE_DIRECTORY_CERTIFICATE_EXPIRY_WARNING_DAYS * 86400) {
            return 'warning';
        }
    }

    $lastSuccess = trim((string) ($controller['last_success_at'] ?? ''));
    if ($lastSuccess === '') {
        // Admitted with an 'ok' last_outcome but no last_success_at is not
        // reachable through repo_directory_record_controller_outcome() (it
        // stamps last_success_at in the same write as an 'ok' outcome); if it
        // ever happens, treat the missing timestamp itself as unproven.
        return 'stale';
    }
    $lastSuccessEpoch = strtotime($lastSuccess . ' UTC');
    if ($lastSuccessEpoch !== false && ($now - $lastSuccessEpoch) > VIRTUSPHERE_DIRECTORY_OBSERVATION_STALE_AFTER_DAYS * 86400) {
        return 'stale';
    }

    return 'ok';
}

/**
 * Single SSoT for the AD overview badge and every controller row's badge,
 * computed once against the same $now (plan section 15.2): the System status
 * card, its per-controller table and the help legend must never be able to
 * disagree about what a colour means.
 *
 * Overall state:
 * 'unknown' (neutral): no saved configuration, or AD is a disabled draft.
 * 'danger': AD is active but the search account's password was rejected
 * (the revision-wide circuit breaker, repo_directory_pause_controllers_for_bind_rejection())
 * or the usable pool contains zero controllers (by schema) or zero
 * controllers with a currently-ok observation (all failing live).
 * 'warning': AD is active, at least one usable controller currently answers
 * ok, but at least one other usable controller is 'danger', 'stale' or
 * 'warning' (mirrors "mindestens ein Controller funktioniert, ein anderer
 * ist ausgefallen/veraltet/ungetestet" from the plan almost verbatim).
 * 'ok': AD is active and every usable controller is currently 'ok'.
 *
 * @param array<string,mixed>|null $config
 * @param list<array<string,mixed>> $controllers
 * @return array{overall:string,now:int,controllers:list<array{controller:array<string,mixed>,state:string}>}
 */
function directory_health_snapshot(?array $config, array $controllers, ?int $now = null): array
{
    $now ??= time();
    $revision = $config !== null ? (int) $config['revision'] : 0;
    $rows = [];
    foreach ($controllers as $controller) {
        $rows[] = ['controller' => $controller, 'state' => directory_controller_ampel($controller, $revision, $now)];
    }

    $enabled = $config !== null && (int) $config['enabled'] === 1;
    $bindBlocked = $config !== null && (int) ($config['automatic_bind_blocked_revision'] ?? 0) === $revision;
    if (!$enabled) {
        $overall = 'unknown';
    } elseif ($bindBlocked) {
        $overall = 'danger';
    } else {
        $usable = 0;
        $ok = 0;
        $danger = 0;
        foreach ($rows as $row) {
            $controller = $row['controller'];
            if ((int) $controller['enabled'] === 1 && (int) ($controller['validated_revision'] ?? 0) === $revision) {
                $usable++;
                if ($row['state'] === 'ok') {
                    $ok++;
                } elseif ($row['state'] === 'danger') {
                    $danger++;
                }
                // 'warning'/'stale' count toward neither: a controller with no
                // known failure but no full proof either degrades the pool
                // without making it an outage on its own.
            }
        }
        $overall = match (true) {
            $usable === 0 => 'danger',
            // Every usable controller has an actual, recent, recorded
            // failure: an outage, not merely unconfirmed or degraded.
            $danger === $usable => 'danger',
            $ok === $usable => 'ok',
            default => 'warning',
        };
    }

    return ['overall' => $overall, 'now' => $now, 'controllers' => $rows];
}
