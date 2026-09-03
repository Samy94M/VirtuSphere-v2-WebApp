<?php

declare(strict_types=1);

// Rollout-hostname identity (Etappe 14D, ADR-0043). Dependency-free on purpose:
// the machine endpoints, the VM repo, the claim table, the migration backfill
// and the Pester vectors all have to agree on ONE answer to "is this the same
// Windows name", and a helper that pulls the portal bootstrap cannot be used by
// half of them.
//
// The rule is deliberately narrow: trim plus ASCII case folding, nothing else.
// It NEVER truncates and never "repairs" a value. A name that is too long or
// carries a dot stays exactly as invalid as it was, because a normaliser that
// silently fixes input would make two different desired names collapse into one
// claim and hand the operator a rollout they never asked for. Mapping a value
// to a shorter legal one is the client's job at rename time, not identity's.

/**
 * The comparison key for a Windows rollout name. A pure change of spelling
 * (`Backup-12345` -> `backup-12345`) is NOT a new identity: it keeps the same
 * key, needs no reset and produces no second claim row.
 *
 * ASCII case folding rather than mb_/locale folding, because the accepted
 * charset is `[A-Za-z0-9-]` and a locale-aware fold would answer differently
 * under a Turkish locale for exactly the letter `I`.
 */
function mecm_hostname_key(?string $hostname): string
{
    $trimmed = trim((string) $hostname);
    if ($trimmed === '') {
        return '';
    }

    return strtr($trimmed, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
}

/** True when two rollout names name the same Windows computer. */
function mecm_hostname_same(?string $left, ?string $right): bool
{
    $leftKey = mecm_hostname_key($left);

    return $leftKey !== '' && $leftKey === mecm_hostname_key($right);
}

/**
 * Whether a value may be ACTIVATED for a MECM rollout. Mirrors
 * Validator::netbiosHostname exactly; it is a separate predicate because the
 * repo has to answer it for a STORED value (a grandfathered legacy hostname
 * that no edit ever touched) where no Validator instance is in play.
 *
 * Grandfathering is a rule about editing, not about rollout: an unchanged
 * illegal legacy value must not block unrelated edits, but it must never be
 * handed to MECM, because the client's rename phase would truncate it and the
 * device would then answer to a name nothing in the portal knows.
 */
function mecm_hostname_is_rollout_valid(?string $hostname): bool
{
    $value = trim((string) $hostname);
    if ($value === '' || strlen($value) > VIRTUSPHERE_MECM_ROLLOUT_HOSTNAME_MAX_LENGTH) {
        return false;
    }

    return preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,13}[A-Za-z0-9])?$/', $value) === 1;
}
