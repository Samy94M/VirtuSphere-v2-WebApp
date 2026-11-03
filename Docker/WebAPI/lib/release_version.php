<?php

declare(strict_types=1);

/**
 * Release identity of this portal checkout (plan docs/audits/2026-10-15-versioning-plan.md).
 *
 * One release number, JJJJ.MM.N, is the operator-facing version. It lives in
 * lib/VERSION, the one line a release edits by hand. lib/BUILD.json carries the git
 * commit: `git archive` fills it through export-subst, a git checkout keeps the
 * placeholder. A missing commit is therefore a normal state, never an error.
 *
 * This file is pure (no database, no session) so that CLI closures can load it
 * standalone.
 */

// N at most 99 keeps every MECM identifier within the 32-character script_version
// field (decision 16.10.2026, plan docs/audits/2026-10-15-versioning-plan.md).
const VIRTUSPHERE_RELEASE_PATTERN = '/^[0-9]{4}\.(0[1-9]|1[0-2])\.[1-9][0-9]?$/';
const VIRTUSPHERE_RELEASE_FILE = __DIR__ . '/VERSION';
const VIRTUSPHERE_BUILD_FILE = __DIR__ . '/BUILD.json';
const VIRTUSPHERE_MIGRATE_FILE = __DIR__ . '/migrate.php';

/**
 * Exact match of the release pattern, with no trimming: a value that needs trimming
 * is not a release at its source. PCRE's `$` also accepts one trailing newline, so
 * that byte is refused explicitly instead of being silently accepted.
 */
function release_is_valid(string $value): bool
{
    return preg_match(VIRTUSPHERE_RELEASE_PATTERN, $value) === 1 && !str_ends_with($value, "\n");
}

/**
 * The release this checkout was built as, or null when lib/VERSION is missing or
 * holds anything but a valid release. Whitespace is trimmed here because editors and
 * Windows checkouts add CRLF or spaces to a one-line file.
 */
function portal_release(string $path = VIRTUSPHERE_RELEASE_FILE): ?string
{
    // A missing or unreadable file is an expected state on a bare checkout, not a fault.
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return null;
    }

    $value = trim($raw);

    return release_is_valid($value) ? $value : null;
}

/**
 * The git commit the release was exported from, or null. A plain checkout keeps the
 * export-subst placeholder, which is not a hash, so it reads as "unknown" rather
 * than as a commit that does not exist.
 */
function portal_build_commit(string $path = VIRTUSPHERE_BUILD_FILE): ?string
{
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return null;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return null;
    }

    $commit = $decoded['commit'] ?? null;

    // The D modifier keeps `$` from accepting a trailing newline, as release_is_valid() does.
    return is_string($commit) && preg_match('/^[0-9a-f]{40}$/D', $commit) === 1 ? $commit : null;
}

/**
 * Splits a MECM run identifier into the release it carries and its deviation flag.
 *
 * The identifier has the form `<base>+<release>`, optionally followed by `-abw` when
 * a file deviates from the install manifest. Older forms carry a commit or a
 * placeholder where the release belongs; those yield a null release, not an error,
 * so an old task shows up as "unknown" instead of being mistaken for a release.
 * The split happens at the LAST `+`, because the base name may contain one itself.
 *
 * @return array{release: ?string, deviates: bool}
 */
function release_from_script_identifier(?string $identifier): array
{
    $value = trim($identifier ?? '');
    if ($value === '') {
        return ['release' => null, 'deviates' => false];
    }

    $deviates = str_ends_with($value, '-abw');
    if ($deviates) {
        $value = substr($value, 0, -strlen('-abw'));
    }

    $plus = strrpos($value, '+');
    if ($plus === false) {
        return ['release' => null, 'deviates' => $deviates];
    }

    $candidate = substr($value, $plus + 1);

    return ['release' => release_is_valid($candidate) ? $candidate : null, 'deviates' => $deviates];
}

/**
 * The migration names the code defines, in file order.
 *
 * lib/migrate.php runs every migration when it is included, so it must never be
 * included just to list them. Its registry keys are read as text instead, anchored
 * to the exact four-space indent the registry uses, so that a nested array key
 * elsewhere in the file cannot be mistaken for a migration.
 *
 * @return list<string>
 */
function release_code_migrations(string $path = VIRTUSPHERE_MIGRATE_FILE): array
{
    $source = @file_get_contents($path);
    if ($source === false) {
        return [];
    }

    preg_match_all('/^    \'([0-9]{4}_[a-z0-9_]+)\' => /m', $source, $matches);

    return $matches[1];
}
