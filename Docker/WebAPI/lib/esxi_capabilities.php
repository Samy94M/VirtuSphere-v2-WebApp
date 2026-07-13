<?php

declare(strict_types=1);

/**
 * ESXi capability facts (ADR-0023 amendment 3): what a host IS and what it is
 * DOING, read from the last successful inventory pull.
 *
 * These are deliberately kept apart from the VIRTUSPHERE_INVENTORY_ERROR_*
 * categories. An error describes a fetch that failed; a capability describes a
 * host whose fetch succeeded. Mixing them would make "this host has a free
 * licence" look like "we could not reach this host", and only one of those is
 * fixed by retrying.
 *
 * Every fact is tri-state. NULL means "not known" (never pulled, an older cache
 * row, or the module did not report the field) and must never be read as false:
 * promising that a host is licensed for writes when we simply did not ask is
 * exactly the failure this feature exists to prevent.
 *
 * Lives in its own module rather than in esxi_inventory.php, which is already at
 * its size budget (ADR-0006).
 */

require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/esxi_inventory.php';
require_once __DIR__ . '/repo/credentials.php';
require_once __DIR__ . '/repo/esxi_inventory.php';

/** A capability warning that is not an error: the pull worked, the host cannot. */
const VIRTUSPHERE_ESXI_CAPABILITY_LICENSE_FREE = 'license_free';
const VIRTUSPHERE_ESXI_CAPABILITY_HA_CLUSTER = 'in_ha_cluster';
const VIRTUSPHERE_ESXI_CAPABILITY_MAINTENANCE = 'in_maintenance';

/**
 * Normalizes the capability columns of a state row into tri-state PHP values.
 * A missing row, or a row from before migration 0016, yields all-null.
 *
 * @param array<string, mixed>|null $state
 * @return array{api_type:?string, product_version:?string, license_product:?string, license_free:?bool, in_ha_cluster:?bool, in_maintenance:?bool}
 */
function esxi_capabilities(?array $state): array
{
    $text = static function (?array $row, string $key): ?string {
        $value = trim((string) ($row[$key] ?? ''));

        return $value !== '' ? $value : null;
    };
    // A tri-state column: SQL NULL stays null, 0/1 become false/true.
    $flag = static function (?array $row, string $key): ?bool {
        if ($row === null || !array_key_exists($key, $row) || $row[$key] === null) {
            return null;
        }

        return (int) $row[$key] === 1;
    };

    return [
        'api_type' => $text($state, 'api_type'),
        'product_version' => $text($state, 'product_version'),
        'license_product' => $text($state, 'license_product'),
        'license_free' => $flag($state, 'license_free'),
        'in_ha_cluster' => $flag($state, 'in_ha_cluster'),
        'in_maintenance' => $flag($state, 'in_maintenance'),
    ];
}

/**
 * Capability warnings for one credential, most severe first. Only facts that are
 * known AND true produce an entry, so an unpulled credential warns about nothing.
 *
 * A free licence and an HA cluster are `warning`: the inventory keeps working,
 * but a deploy or autostart run on this host will not. Maintenance mode is
 * `info`: it is a temporary, deliberate state of the host, not a misconfiguration.
 *
 * @param array<string, mixed>|null $state
 * @return array<int, array{key:string, level:string}>
 */
function esxi_capability_warnings(?array $state): array
{
    $facts = esxi_capabilities($state);
    $warnings = [];
    if ($facts['license_free'] === true) {
        $warnings[] = ['key' => VIRTUSPHERE_ESXI_CAPABILITY_LICENSE_FREE, 'level' => 'warning'];
    }
    if ($facts['in_ha_cluster'] === true) {
        $warnings[] = ['key' => VIRTUSPHERE_ESXI_CAPABILITY_HA_CLUSTER, 'level' => 'warning'];
    }
    if ($facts['in_maintenance'] === true) {
        $warnings[] = ['key' => VIRTUSPHERE_ESXI_CAPABILITY_MAINTENANCE, 'level' => 'info'];
    }

    return $warnings;
}

/**
 * Whether the capability facts are recent enough to act on. A fact is only as
 * trustworthy as the pull that produced it: refusing a deploy because of a
 * licence we last saw a month ago would be a guess with consequences.
 *
 * The freshness window is the same one the traffic light uses for staleness. An
 * interval of 0 (automation deliberately off) makes age meaningless, exactly as
 * in esxi_inventory_ampel(), so the facts count as fresh.
 *
 * @param array<string, mixed>|null $state
 */
function esxi_capabilities_fresh(?array $state, int $intervalHours, ?int $now = null): bool
{
    if ($state === null || empty($state['last_success_at'])) {
        return false;
    }
    if ($intervalHours <= 0) {
        return true;
    }

    $age = ($now ?? time()) - (int) strtotime((string) $state['last_success_at'] . ' UTC');

    return $age <= VIRTUSPHERE_ESXI_INVENTORY_STALE_FACTOR * $intervalHours * 3600;
}

/**
 * Rank of a traffic-light state, mirroring repo_integration_worst_state(). Used
 * to roll several credentials up into one dashboard tile.
 */
function esxi_state_rank(string $state): int
{
    return match ($state) {
        'danger' => 3,
        'warning' => 2,
        'unknown' => 1,
        default => 0,
    };
}

/**
 * The traffic light shown for one ESXi credential anywhere in the portal: its
 * fetch health, and nothing else. Green means the last pull succeeded and is
 * current; it does NOT promise the host can deploy.
 *
 * There is exactly one of these because three call sites (the credential row,
 * the integrations heading and the dashboard rollup) must never disagree on the
 * same colour. It answers one question: "is the inventory pull healthy?"
 *
 * A host capability (a free licence, an HA cluster, maintenance mode) does not
 * colour this light. Those are properties of a *successful* pull, not fetch
 * problems, so they surface as their own badges (esxi_capability_warnings()) and
 * gate deploys through esxi_autostart_preflight(). Painting a perfectly healthy
 * pull amber because the host has a free licence made "amber" mean two unrelated
 * things and hid the one it was built for. Keeping them apart is the point of
 * having a separate capability module at all.
 *
 * @param array<string, mixed>|null $state
 */
function esxi_credential_state(?array $state, int $intervalHours, ?int $now = null): string
{
    return esxi_inventory_ampel($state, $intervalHours, $now);
}

/**
 * Worst hypervisor state across every ESXi credential, for the dashboard tile.
 * Null when no ESXi credential exists at all: a permanently grey tile for a
 * feature nobody configured is noise, so the caller hides it instead.
 *
 * This is fetch health only, the same as esxi_credential_state(): the tile is
 * green when every host's inventory pull is healthy, even if one of them has a
 * free licence. A deploy-blocking capability is not a fetch problem; it surfaces
 * as a badge and is enforced by esxi_autostart_preflight(), not by this colour.
 */
function esxi_worst_state(mysqli $db): ?string
{
    $credentials = repo_credentials_by_type($db, VIRTUSPHERE_CREDENTIAL_TYPE_ESXI);
    if ($credentials === []) {
        return null;
    }

    $intervalHours = esxi_inventory_interval_hours($db);
    $worst = 'ok';
    foreach ($credentials as $credential) {
        $state = esxi_credential_state(repo_esxi_inventory_state($db, (int) $credential['id']), $intervalHours);
        if (esxi_state_rank($state) > esxi_state_rank($worst)) {
            $worst = $state;
        }
    }

    return $worst;
}

/**
 * Preflight verdict for a job that would write the autostart policy.
 *
 * The cache-never-blocks rule (ADR-0023) applies to unknown and stale facts: we
 * warn and let ESXi be the authority, because a job that fails loudly on the host
 * is better than a job the portal refused on a month-old assumption.
 *
 * Two exceptions, both requiring a FRESH fact:
 *  - a free licence has no write API at all, so the module would fail with a
 *    message no operator can act on. Refuse with a sentence they can.
 *  - a host in an HA cluster ignores autostart entirely. Writing it would report
 *    success and change nothing, which is the worst possible outcome.
 *
 * @param array<string, mixed>|null $state
 * @return array{verdict:string, reason:?string, facts:array<string,mixed>}
 *         verdict: 'ok' | 'block' | 'skip'
 */
function esxi_autostart_preflight(?array $state, int $intervalHours, ?int $now = null): array
{
    $facts = esxi_capabilities($state);
    $fresh = esxi_capabilities_fresh($state, $intervalHours, $now);

    if ($fresh && $facts['license_free'] === true) {
        return ['verdict' => 'block', 'reason' => VIRTUSPHERE_ESXI_CAPABILITY_LICENSE_FREE, 'facts' => $facts];
    }
    if ($fresh && $facts['in_ha_cluster'] === true) {
        return ['verdict' => 'skip', 'reason' => VIRTUSPHERE_ESXI_CAPABILITY_HA_CLUSTER, 'facts' => $facts];
    }

    return ['verdict' => 'ok', 'reason' => null, 'facts' => $facts];
}

/**
 * One-line, non-localized summary of the facts for the job log. Operator
 * diagnostics, so it stays English and carries "unknown" verbatim rather than
 * hiding a gap in knowledge behind a plausible-looking default.
 *
 * @param array<string, mixed> $facts
 */
function esxi_capabilities_log_line(array $facts, bool $fresh): string
{
    $render = static fn (mixed $value): string => match (true) {
        $value === null => 'unknown',
        $value === true => 'yes',
        $value === false => 'no',
        default => (string) $value,
    };

    return sprintf(
        'ESXi capabilities: product=%s api=%s license=%s free=%s ha_cluster=%s maintenance=%s facts=%s',
        $render($facts['product_version'] ?? null),
        $render($facts['api_type'] ?? null),
        $render($facts['license_product'] ?? null),
        $render($facts['license_free'] ?? null),
        $render($facts['in_ha_cluster'] ?? null),
        $render($facts['in_maintenance'] ?? null),
        $fresh ? 'fresh' : 'stale-or-missing'
    );
}
