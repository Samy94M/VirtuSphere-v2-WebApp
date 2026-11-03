<?php

declare(strict_types=1);

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/errors.php';

/**
 * Size-based rotation for the two file logs nothing else rotates (ADR-0026
 * amendment): logs/error.log (the portal error handler appends, errors.php)
 * and the PHP engine log from the ini (logs/php-error.log). The DB retention
 * job cannot cover them, and an unrotated error log on a LAN appliance grows
 * until the disk is the incident.
 *
 * Contract:
 *  - a file only rotates once it exceeds the size cap; a missing file is idle,
 *    never an error (a fresh install has no error log, and that is health),
 *  - generations shift name -> name.1 -> ... -> name.N, the oldest falls off,
 *  - rotation only ever touches paths whose real parent IS the resolved log
 *    directory - a symlinked or traversal path is an error, not a target,
 *  - one rotation at a time per directory (flock on logs/.rotation.lock);
 *    a held lock means another worker is rotating, which is idle, not failure,
 *  - permission or rename failures throw: the maintenance verdict carries the
 *    job failure to the System status row (no extra display of its own).
 *
 * Writers stay safe across the rename because both append paths open the file
 * per write (FILE_APPEND in errors.php, PHP's engine log likewise); a write
 * racing the rename lands in the renamed file or the fresh one, never nowhere.
 */

/**
 * Rotate every known file log that exceeds the cap. Returns the number of
 * rotated files; idle (0) when nothing is due or another rotation holds the
 * lock. $logDirOverride exists for the unit tests, which rotate in a temp
 * directory instead of the live one; production callers pass nothing.
 */
function virtusphere_rotate_logs(
    int $maxBytes = VIRTUSPHERE_LOG_ROTATE_MAX_BYTES,
    int $generations = VIRTUSPHERE_LOG_ROTATE_GENERATIONS,
    ?string $logDirOverride = null
): int {
    $logDir = realpath($logDirOverride ?? virtusphere_log_dir());
    if ($logDir === false) {
        // No log directory yet: nothing can have grown, so nothing to rotate.
        return 0;
    }

    $lock = fopen($logDir . DIRECTORY_SEPARATOR . '.rotation.lock', 'c');
    if ($lock === false) {
        throw new RuntimeException('Log rotation lock cannot be opened in ' . $logDir);
    }

    try {
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            return 0;
        }

        $rotated = 0;
        foreach (virtusphere_rotation_candidates($logDir) as $path) {
            if (virtusphere_rotate_one_log($path, $logDir, $maxBytes, $generations)) {
                $rotated++;
            }
        }

        return $rotated;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * The file logs rotation owns: the handler log and, when the ini points it
 * into the same directory, the PHP engine log. An engine log configured
 * elsewhere is deliberately not touched - rotating outside the log directory
 * is exactly what the containment rule forbids.
 *
 * @return list<string>
 */
function virtusphere_rotation_candidates(string $logDir): array
{
    $candidates = [$logDir . DIRECTORY_SEPARATOR . basename(virtusphere_error_log_path())];
    $engineLog = virtusphere_php_engine_error_log_path();
    if ($engineLog !== null && !in_array($engineLog, $candidates, true)) {
        // Selection, not enforcement: an engine log configured into another
        // directory simply is not ours to rotate. The hard containment check
        // in virtusphere_rotate_one_log() then guards what selection cannot
        // see - a symlink inside the log directory pointing elsewhere.
        $engineParent = realpath(dirname($engineLog));
        if ($engineParent !== false && $engineParent === $logDir) {
            $candidates[] = $engineLog;
        }
    }

    return $candidates;
}

/**
 * Rotate one file if it exceeds the cap. True when a rotation happened.
 */
function virtusphere_rotate_one_log(string $path, string $logDir, int $maxBytes, int $generations): bool
{
    if ($maxBytes <= 0 || $generations < 1) {
        throw new InvalidArgumentException('Rotation needs a positive size cap and at least one generation.');
    }

    clearstatcache(true, $path);
    if (!is_file($path)) {
        return false;
    }

    // Containment: the file's real parent must BE the log directory. realpath
    // resolves symlinks, so a link pointing elsewhere fails here instead of
    // making rotation rename files outside its ground.
    $real = realpath($path);
    if ($real === false || dirname($real) !== $logDir) {
        throw new RuntimeException('Log rotation refuses a path outside the log directory: ' . $path);
    }

    $size = filesize($real);
    if ($size === false || $size <= $maxBytes) {
        return false;
    }

    // Shift generations from the oldest down: .N falls off, .(N-1) -> .N, ...
    $oldest = $real . '.' . $generations;
    if (is_file($oldest) && !unlink($oldest)) {
        throw new RuntimeException('Log rotation cannot remove the oldest generation: ' . $oldest);
    }
    for ($generation = $generations - 1; $generation >= 1; $generation--) {
        $from = $real . '.' . $generation;
        if (is_file($from) && !rename($from, $real . '.' . ($generation + 1))) {
            throw new RuntimeException('Log rotation cannot shift generation: ' . $from);
        }
    }
    if (!rename($real, $real . '.1')) {
        throw new RuntimeException('Log rotation cannot rotate: ' . $real);
    }

    return true;
}

/**
 * Admits an unhandled error to error.log and the audit trail once per error class
 * and hour (LG-16). One bug hit by every request would otherwise write the full
 * block and the audit row thousands of times and exceed the rotation cap within
 * its cycle. The first occurrence of a class in an hour is admitted; later ones in
 * that hour are counted in logs/error-storm.json and write nothing. The caller
 * still shows the reference to the user for every occurrence.
 *
 * It fails open: when the state cannot be opened, locked or read, the occurrence
 * is admitted, because losing the diagnosis is worse than a longer log.
 */
function virtusphere_error_storm_admits(string $errorClass, ?string $statePath = null, ?string $hour = null): bool
{
    $statePath ??= virtusphere_log_dir() . DIRECTORY_SEPARATOR . 'error-storm.json';
    $hour ??= date('YmdH');

    $handle = @fopen($statePath, 'c+');
    if ($handle === false) {
        return true;
    }
    // The php, deploy-worker and maintenance-worker containers share logs/; a file one of
    // them created must stay writable for the others, or the throttle silently fails open.
    virtusphere_try_chmod($statePath, 0666, 0006);

    try {
        if (!flock($handle, LOCK_EX)) {
            return true;
        }
        $raw = stream_get_contents($handle);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        // Only the current hour is kept, so the file holds one entry per class seen
        // in this hour and cannot grow with the history of errors.
        $state = [];
        if (is_array($decoded)) {
            foreach ($decoded as $class => $entry) {
                if (is_array($entry) && ($entry['hour'] ?? null) === $hour) {
                    $state[(string) $class] = ['hour' => $hour, 'suppressed' => (int) ($entry['suppressed'] ?? 0)];
                }
            }
        }

        $admitted = !isset($state[$errorClass]);
        if ($admitted) {
            $state[$errorClass] = ['hour' => $hour, 'suppressed' => 0];
        } else {
            $state[$errorClass]['suppressed']++;
        }

        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, (string) json_encode($state, JSON_UNESCAPED_SLASHES));
        fflush($handle);

        return $admitted;
    } catch (Throwable) {
        return true;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/**
 * Whether this failed poll starts an outage (LG-15). The health endpoint keeps no
 * state between requests, so an outage is remembered in a flag file: the first
 * failed poll creates it and returns true, and the caller writes its one line;
 * later polls return false until virtusphere_outage_ends() removes the flag on the
 * first healthy answer. A flag that cannot be created (the directory is not
 * writable) returns true on every poll, so the diagnosis is kept rather than lost.
 */
function virtusphere_outage_begins(string $flagPath): bool
{
    if (is_file($flagPath)) {
        return false;
    }

    $handle = @fopen($flagPath, 'x');
    if ($handle === false) {
        // A concurrent poll may have created the flag between the check and the open.
        return !is_file($flagPath);
    }
    fclose($handle);

    return true;
}

/** Removes an outage flag set by virtusphere_outage_begins(); a missing flag is nothing to do. */
function virtusphere_outage_ends(string $flagPath): void
{
    if (is_file($flagPath)) {
        @unlink($flagPath);
    }
}
