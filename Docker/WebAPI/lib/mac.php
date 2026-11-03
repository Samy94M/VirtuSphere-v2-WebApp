<?php

declare(strict_types=1);

// Canonical MAC handling (ADR-0018 follow-up, plan stage E2). Dependency-free
// on purpose: used by machine endpoints, repos, the migration backfill and
// tests. Canonical format: uppercase, colon-separated (AA:BB:CC:DD:EE:FF) -
// matches what ESXi/Ansible import and MECM display.

// Accepts colon, hyphen, Cisco dotted (aabb.ccdd.eeff) and raw 12-hex input;
// returns the canonical form or null when the value is not a MAC address.
function virtusphere_normalize_mac(?string $mac): ?string
{
    if ($mac === null) {
        return null;
    }

    $hex = strtoupper((string) preg_replace('/[^0-9A-Fa-f]/', '', trim($mac)));
    if (strlen($hex) !== 12) {
        return null;
    }

    return implode(':', str_split($hex, 2));
}
