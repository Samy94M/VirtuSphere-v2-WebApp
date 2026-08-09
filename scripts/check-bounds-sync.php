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
//
// Known limit of the scan root: scripts/backup.sh owns KEEP=14, the backup
// retention window, and six texts write "14 Tage" out. A second scan root for
// shell would have to guess at a unit from a shell variable name, which is the
// noise this check is built to avoid. Lifting the number into a PHP constant
// that backup.sh reads is the fix, and it is a decision of its own.
$constants = [];
foreach (glob($root . '/Docker/WebAPI/lib/*.php') ?: [] as $file) {
    $source = (string) file_get_contents($file);
    // A product, not only a literal: the mission import cap is written
    // 2 * 1024 * 1024 because that is what the number means, and reading only
    // plain digits made exactly the constants with a readable unit invisible.
    if (preg_match_all('/^const\s+(VIRTUSPHERE_[A-Z0-9_]+)\s*=\s*([0-9]+(?:\s*\*\s*[0-9]+)*)\s*;/m', $source, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $product = 1;
            foreach (preg_split('/\s*\*\s*/', $match[2]) ?: [] as $factor) {
                $product *= (int) $factor;
            }
            $constants[$match[1]] = $product;
        }
    }
}

/**
 * Array constants whose elements carry a unit, named element by element.
 *
 * An allowlist and not a scan on purpose: most elements of these arrays are
 * counters and waiting times whose numbers say nothing about a unit, and
 * accusing a text over one of them is the noise that gets a check switched off.
 * A '*' key means the whole list shares one unit.
 *
 * Keyed by constant => element key => unit class.
 */
const BOUNDS_ARRAY_KEYS = [
    'VIRTUSPHERE_VM_DEFAULTS' => ['disk_size_gb' => 'gigabytes', 'ram_mb' => 'megabytes'],
    'VIRTUSPHERE_VM_LIMITS' => [
        'disk_size_gb_min' => 'gigabytes',
        'disk_size_gb_max' => 'gigabytes',
        'ram_mb_min' => 'megabytes',
        'ram_mb_max' => 'megabytes',
    ],
    'VIRTUSPHERE_RAM_PRESETS_MB' => ['*' => 'megabytes'],
];

/** @var array<string, array{value:int, unit:string}> label => value + unit */
$arrayBounds = [];
$missingArrayKeys = [];
foreach (glob($root . '/Docker/WebAPI/lib/*.php') ?: [] as $file) {
    $source = (string) file_get_contents($file);
    foreach (BOUNDS_ARRAY_KEYS as $name => $keys) {
        if (!preg_match('/^const\s+' . preg_quote($name, '/') . '\s*=\s*\[(.*?)\];/ms', $source, $block)) {
            continue;
        }
        foreach ($keys as $element => $unit) {
            if ($element === '*') {
                preg_match_all('/(?<![\w\'])([0-9]+)(?![\w\'])/', $block[1], $items);
                foreach ($items[1] as $index => $value) {
                    $arrayBounds[$name . '[' . $index . ']'] = ['value' => (int) $value, 'unit' => $unit];
                }
                continue;
            }
            if (preg_match('/\'' . preg_quote($element, '/') . '\'\s*=>\s*([0-9]+)/', $block[1], $item)) {
                $arrayBounds[$name . "['" . $element . "']"] = ['value' => (int) $item[1], 'unit' => $unit];
            }
        }
    }
}

// An allowlist entry that resolves to nothing is a silently disabled check, not
// a pass: the constant was renamed or restructured and the guard stopped looking.
foreach (BOUNDS_ARRAY_KEYS as $name => $keys) {
    $matched = 0;
    foreach (array_keys($arrayBounds) as $label) {
        if (str_starts_with($label, $name . '[')) {
            $matched++;
        }
    }
    if ($matched === 0) {
        $missingArrayKeys[] = $name;
    }
}

if ($missingArrayKeys !== []) {
    echo "check-bounds-sync: BOUNDS_ARRAY_KEYS names a constant this script can no longer read:\n";
    foreach ($missingArrayKeys as $name) {
        echo '  [bounds-sync.unreadable-array] ' . $name . "\n";
    }
    echo "\nWithout it the elements are unchecked while the guard still reports green.\n";
    exit(1);
}

/**
 * Numbers that appear in prose but are NOT ours to change, so writing them out is
 * correct. Keyed by "module.key" => reason.
 */
const BOUNDS_EXEMPT = [
    'validate.netbios_hostname' => '15 is the NetBIOS name length, a Microsoft invariant, not a value we tune',
    'vm_edit.hostname_legacy_warning' => 'same NetBIOS 15',
    'help_missions.naming_p3' => 'same NetBIOS 15',
    'settings.allowlist_description_too_long' => '255 is the VARCHAR column width, fixed by the schema',
    'validate.mission_name_invalid' => '255 is the VARCHAR column width, fixed by the schema',
    'system_status.reassign_too_long' => '255 is the VARCHAR column width, fixed by the schema',
    'help_packages.packages_os_p2' => 'the 60-second MECM sync cadence is set in the PowerShell task on the MECM server, not here',
    'help_settings.settings_time_p1' => 'the "plus 1 to 2 hours" is the Europe/Berlin UTC offset, a fact about the timezone, not a bound we set; it collides with the backup grace window only by value',
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
        str_contains($name, 'PERCENT'), str_contains($name, 'THRESHOLD'), str_contains($name, '_PCT') => 'percent',
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

    // Same rule one axis over: a size stored in bytes is written in MB or GB.
    // Exact divisions only, and never down to a 1: 262144 bytes are 0.25 MB and
    // rounding that to "0" or "1" would accuse texts at random, which is the
    // noise this check warns about in its own docblock.
    if ($unit === 'bytes') {
        foreach (['megabytes' => 1024 ** 2, 'gigabytes' => 1024 ** 3, 'terabytes' => 1024 ** 4] as $sizeUnit => $factor) {
            if ($value % $factor === 0 && intdiv($value, $factor) >= 2) {
                $bounds[intdiv($value, $factor)][$sizeUnit][] = $name . ' (as ' . $sizeUnit . ')';
            }
        }
    }
}

foreach ($arrayBounds as $label => $entry) {
    if ($entry['value'] >= 2) {
        $bounds[$entry['value']][$entry['unit']][] = $label;
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
    // The two classes bounds_unit_of() has always known and no word could reach,
    // which made them structurally mute.
    'Bytes' => 'bytes', 'bytes' => 'bytes', 'Byte' => 'bytes', 'byte' => 'bytes',
    'Zeilen' => 'rows', 'rows' => 'rows',
    'MB' => 'megabytes', 'GB' => 'gigabytes', 'TB' => 'terabytes',
];

// Longest word first, so "Bytes" is not consumed as "Byte" with a stray "s".
$unitWords = array_keys(BOUNDS_UNIT_WORDS);
usort($unitWords, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
$units = '(' . implode('|', array_map(static fn (string $w): string => preg_quote($w, '/'), $unitWords)) . ')';

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
            // (?![A-Za-z]) rather than \b, because \b never fires after "%": a
            // two-letter unit without a right-hand boundary reads "2 MBit" as
            // "2 MB", and that is a false alarm on a text nobody owns.
            if (!preg_match_all('/\b([0-9]{1,7})\s*' . $units . '(?![A-Za-z])/u', $text, $matches, PREG_SET_ORDER)) {
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
