<?php

declare(strict_types=1);

/**
 * Drift check (ADR-0016 family): a number in user-facing prose must not spell out
 * a value the code owns.
 *
 * This class of defect has bitten three times: the help promised "mindestens 12
 * Zeichen" after the password minimum became configurable, the HSTS hint promised
 * "180 Tage" for a value a constant owns, and the ESXi interval bound lived in
 * three places at once. The failure is quiet by nature: the code keeps working,
 * only the text starts lying, and no test notices because nothing is broken.
 *
 * The rule enforced here: if a lang string states a number followed by a unit, and
 * a bound constant holds exactly that number, the string must interpolate it
 * (`:min`, `:days`, ...) rather than write the digits. Numbers the project does
 * *not* own (an external standard, a column width) are exempt and listed with the
 * reason, so the exemption is a decision on record and not an oversight.
 *
 * Usage: php scripts/check-bounds-sync.php [--ci|--quiet]
 *
 * --quiet prints nothing on success (session-start hook). VIRTUSPHERE_CHECK_ROOT
 * overrides the repo root (guard harness fixtures). Finding lines carry stable
 * [bounds-sync.*] IDs as the diagnostic contract.
 */

$quiet = false;
foreach (array_slice(array_values((array) ($_SERVER['argv'] ?? [])), 1) as $arg) {
    switch ($arg) {
        case '--ci':
            break;
        case '--quiet':
        case '-q':
            $quiet = true;
            break;
        case '--help':
        case '-h':
            fwrite(STDOUT, "Usage: php scripts/check-bounds-sync.php [--ci|--quiet|-q|--help|-h]\n");
            exit(0);
        default:
            fwrite(STDERR, "Unknown option: {$arg}\n");
            exit(2);
    }
}

$envRoot = getenv('VIRTUSPHERE_CHECK_ROOT');
$root = is_string($envRoot) && $envRoot !== '' ? $envRoot : dirname(__DIR__);

// Constants are not all in constants.php: the mission import TTL lives next to
// the importer, the SSH timeouts next to the SSH client. Parse every lib file
// that declares one, rather than loading a hand-picked few, so a constant in a
// new home is covered the day it is written. Parsed, not require'd: most of these
// files pull in a database or a bootstrap on load.
$constants = [];
foreach (glob($root . '/Docker/WebAPI/lib/*.php') ?: [] as $file) {
    $source = (string) file_get_contents($file);
    if (preg_match_all('/^const\s+(VIRTUSPHERE_[A-Z0-9_]+)\s*=\s*([0-9]+)\s*;/m', $source, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $constants[$match[1]] = (int) $match[2];
        }
    }
}

/**
 * Numbers that appear in prose but are NOT ours to change, so writing them out is
 * correct. Keyed by "module.key" => reason.
 */
const BOUNDS_EXEMPT = [
    'validate.netbios_hostname' => '15 is the NetBIOS name length, a Microsoft invariant, not a value we tune',
    'vm_edit.hostname_legacy_warning' => 'same NetBIOS 15',
    'help.naming_p3' => 'same NetBIOS 15',
    'settings.allowlist_description_too_long' => '255 is the VARCHAR column width, fixed by the schema',
    'validate.mission_name_invalid' => '255 is the VARCHAR column width, fixed by the schema',
    'help.packages_os_p2' => 'the 60-second MECM sync cadence is set in the PowerShell task on the MECM server, not here',
];

/**
 * The unit a constant is measured in, read from its name. Matching on the number
 * alone is not enough and produces noise that gets a check ignored: the stale
 * timeout is 600 seconds, which is 10 minutes, and "10 Prozent" in the backup
 * hint is not that. A number only accuses a constant when the unit agrees too.
 */
function bounds_unit_of(string $name): ?string
{
    return match (true) {
        str_contains($name, 'SECONDS') => 'seconds',
        str_contains($name, 'MINUTES') => 'minutes',
        str_contains($name, 'HOURS') => 'hours',
        str_contains($name, 'DAYS') => 'days',
        str_contains($name, 'PERCENT'), str_contains($name, 'THRESHOLD') => 'percent',
        str_contains($name, 'LENGTH'), str_contains($name, 'CHARS') => 'characters',
        str_contains($name, 'BYTES') => 'bytes',
        str_contains($name, 'ROWS') => 'rows',
        // Anything whose unit is not stated in its name is too ambiguous to
        // accuse a text with; a false alarm here costs more than the miss.
        default => null,
    };
}

/** @var array<int, array<string, string[]>> value => unit => constant names */
$bounds = [];
foreach ($constants as $name => $value) {
    if ($value < 2) {
        continue;
    }
    $unit = bounds_unit_of($name);
    if ($unit === null) {
        continue;
    }
    $bounds[$value][$unit][] = $name;

    // A duration is written in the unit that reads naturally, not the one it is
    // stored in: HSTS lives in seconds and the hint speaks of days.
    if ($unit === 'seconds') {
        if ($value % 60 === 0) {
            $bounds[intdiv($value, 60)]['minutes'][] = $name . ' (as minutes)';
        }
        if ($value % 3600 === 0) {
            $bounds[intdiv($value, 3600)]['hours'][] = $name . ' (as hours)';
        }
        if ($value % 86400 === 0) {
            $bounds[intdiv($value, 86400)]['days'][] = $name . ' (as days)';
        }
    }
}

/** The unit class each word in the prose stands for. */
const BOUNDS_UNIT_WORDS = [
    'Tage' => 'days', 'Tagen' => 'days', 'days' => 'days',
    'Minuten' => 'minutes', 'minutes' => 'minutes',
    'Sekunden' => 'seconds', 'seconds' => 'seconds',
    'Stunden' => 'hours', 'hours' => 'hours',
    'Zeichen' => 'characters', 'characters' => 'characters',
    'Prozent' => 'percent', 'percent' => 'percent', '%' => 'percent',
];

$units = '(' . implode('|', array_map(static fn (string $w): string => preg_quote($w, '/'), array_keys(BOUNDS_UNIT_WORDS))) . ')';

$findings = [];
foreach (['de', 'en'] as $locale) {
    foreach (glob($root . '/Docker/WebAPI/lang/' . $locale . '/*.php') ?: [] as $file) {
        $module = basename($file, '.php');
        /** @var array<string, mixed> $strings */
        $strings = require $file;
        foreach ($strings as $key => $text) {
            $fullKey = $module . '.' . $key;
            if (!is_string($text) || isset(BOUNDS_EXEMPT[$fullKey])) {
                continue;
            }
            if (!preg_match_all('/\b([0-9]{1,6})\s*' . $units . '/u', $text, $matches, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($matches as $match) {
                $number = (int) $match[1];
                $unit = BOUNDS_UNIT_WORDS[$match[2]];
                if (!isset($bounds[$number][$unit])) {
                    continue;
                }
                $findings[] = sprintf(
                    '  [bounds-sync.spelled-out] %s/%s: writes "%s %s"; a constant owns that value (%s). Interpolate it.',
                    $locale,
                    $fullKey,
                    $match[1],
                    $match[2],
                    implode(' or ', array_unique($bounds[$number][$unit]))
                );
            }
        }
    }
}

// A stale exemption is as misleading as a missing one.
$stale = [];
foreach (array_keys(BOUNDS_EXEMPT) as $exempt) {
    [$module, $key] = explode('.', $exempt, 2);
    $file = $root . '/Docker/WebAPI/lang/de/' . $module . '.php';
    if (!is_file($file)) {
        $stale[] = $exempt . ' (no such module)';
        continue;
    }
    /** @var array<string, mixed> $strings */
    $strings = require $file;
    if (!array_key_exists($key, $strings)) {
        $stale[] = $exempt . ' (key is gone)';
    }
}

if ($stale !== []) {
    echo "check-bounds-sync: BOUNDS_EXEMPT names a text that no longer exists; delete the entry:\n";
    foreach ($stale as $entry) {
        echo '  [bounds-sync.stale-exempt] ' . $entry . "\n";
    }
    exit(1);
}

if ($findings !== []) {
    echo "check-bounds-sync: a user-facing text spells out a number the code owns.\n";
    echo "The text will lie the moment the constant moves, and nothing else will notice.\n\n";
    foreach ($findings as $finding) {
        echo $finding . "\n";
    }
    echo "\nFix: put a :placeholder in the text and pass the constant at the call site.\n";
    echo "If the number is genuinely not ours (an external standard, a column width),\n";
    echo "add the key to BOUNDS_EXEMPT in this script with the reason.\n";
    exit(1);
}

if (!$quiet) {
    echo "check-bounds-sync: keine Zahl in Portal-Texten spiegelt eine Konstante.\n";
}
exit(0);
