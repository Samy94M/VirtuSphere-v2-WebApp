<?php

declare(strict_types=1);

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/repo/settings.php';

/**
 * Central password policy. account.php (own password), users.php (create,
 * reset) all validate through this pair instead of repeating a length check,
 * so the admin-configured minimum applies everywhere at once.
 */

/**
 * Effective minimum password length. Clamped to the constant bounds, so the
 * setting can only tighten the historical 12-character baseline; a broken or
 * hand-edited row falls back to the default rather than to "no minimum".
 */
function password_policy_min_length(mysqli $db): int
{
    try {
        $minLength = (int) repo_setting_value($db, VIRTUSPHERE_SETTING_PASSWORD_MIN_LENGTH, (string) VIRTUSPHERE_PASSWORD_MIN_LENGTH_DEFAULT);
    } catch (Throwable) {
        return VIRTUSPHERE_PASSWORD_MIN_LENGTH_DEFAULT;
    }

    return max(VIRTUSPHERE_PASSWORD_MIN_LENGTH_MIN, min(VIRTUSPHERE_PASSWORD_MIN_LENGTH_MAX, $minLength));
}

/**
 * Returns the localized error for a password the policy rejects, or null when it
 * passes. Pure (the minimum is injected), so it is unit-testable without a
 * database.
 *
 * Two limits, and they deliberately count different things:
 *
 * - the minimum counts CHARACTERS (mb_strlen): "12 characters" is what the form
 *   promises someone typing umlauts, and it is the stricter reading of the rule;
 * - the maximum counts BYTES (strlen), because that is what bcrypt truncates at.
 *   PASSWORD_DEFAULT is bcrypt, and bcrypt silently ignores everything past byte
 *   72: two different long passwords then verify against the same hash, and the
 *   user is never told. 40 umlauts are 80 bytes, so this is reachable without
 *   anyone doing anything strange (OWASP password-storage cheat sheet).
 *
 * @param string $langKey Module-scoped message key for the minimum; both existing
 *                        keys (account.err_new_password_min, users.err_password_min)
 *                        interpolate :min.
 * @param string $maxLangKey Message key for the byte limit; interpolates :max.
 */
function password_policy_error(string $password, int $minLength, string $langKey, string $maxLangKey = 'validate.password_max_bytes'): ?string
{
    if (mb_strlen($password) < $minLength) {
        return __t($langKey, ['min' => $minLength]);
    }

    if (strlen($password) > VIRTUSPHERE_PASSWORD_MAX_BYTES) {
        return __t($maxLangKey, ['max' => VIRTUSPHERE_PASSWORD_MAX_BYTES]);
    }

    return null;
}
