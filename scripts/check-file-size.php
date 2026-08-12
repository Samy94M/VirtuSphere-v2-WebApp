<?php

declare(strict_types=1);

/**
 * ADR-0006 guard: a first-party PHP page or module stays below the size at which
 * unrelated responsibilities start hiding in one file.
 *
 * ADR-0006 has been a hook *warning* since 2026-06-28, and a warning nobody has
 * to answer is a budget nobody keeps: by 2026-08-11 twenty-three files under
 * lib/ and portal/ were over the limit, the largest at 1220 lines bundling five
 * independent transaction domains. A warning also cannot tell "this legacy file
 * is on a named teardown plan" from "someone just added 300 lines to it", which
 * is the only distinction that matters while a cleanup is in flight.
 *
 * So the budget is enforced, and every file that is over it today is recorded
 * with its exact current size, the reason, and the stage that takes it apart.
 * That makes the list a ratchet in both directions:
 *
 *   - a file not on the list may not cross the budget            (oversize)
 *   - a file on the list may not gain a single line              (grown)
 *   - a file on the list that got small enough must leave it     (stale)
 *
 * The third rule is what keeps this from becoming a permanent amnesty: an
 * exception cannot outlive the split it was waiting for.
 *
 * Scope is lib/ and portal/ - the code this project writes and rewrites. The
 * machine-API files in the WebAPI root (mecm-*.php, db_importMAC.php,
 * function.php) are a frozen wire surface that is deliberately not refactored
 * (same reasoning as the PHPStan scope), and tests are measured by what they
 * pin, not by their length.
 *
 * Usage: php scripts/check-file-size.php [--ci|--quiet|-q] [--list]
 *
 * --quiet prints nothing on success (session-start hook). --list prints every
 * scanned file over the budget with its recorded allowance, which is how the
 * exception list is maintained after a split. VIRTUSPHERE_CHECK_ROOT overrides
 * the repo root (guard harness fixtures). Finding lines carry stable
 * [file-size.*] IDs as the diagnostic contract.
 */

const FILE_SIZE_BUDGET = 400;

/** Directories scanned, relative to the repo root. */
const FILE_SIZE_SCOPE = [
    'Docker/WebAPI/lib',
    'Docker/WebAPI/portal',
];

/**
 * Files that are over budget today. `lines` is the exact size at the moment the
 * exception was recorded and is a ceiling, not a target: the entry disappears
 * when the named stage splits the file, and it may never be raised. Adding a
 * new entry instead of splitting is a decision to be argued in review, not a
 * formality - the list is meant to shrink to the five permanent ones.
 *
 * @var array<string, array{lines: int, why: string, stage: string}>
 */
const FILE_SIZE_ALLOWANCES = [
    // --- On a named teardown plan (Masterplan 2026-08-11, Refactoring-Vertrag).
    // lib/repo/deploy_jobs.php was the largest entry here (1220 lines) and is
    // gone from this table: Etappe 1 split it into domain modules behind a
    // facade, which is what "the list only shrinks" means in practice.
    // lib/deploy_worker.php (521) and lib/deploy_worker_outcome.php (693) left
    // this table in Etappe 2: CLI shell, the two job processors, stream, runtime,
    // VM state, reaper and outcome are now separate modules behind two facades.
    'Docker/WebAPI/lib/repo/esxi_inventory.php' => [
        'lines' => 794,
        'why' => 'cache replace, status/pause, queries and VLAN sync are separate write domains',
        'stage' => 'Etappe 5',
    ],
    'Docker/WebAPI/lib/esxi_inventory.php' => [
        'lines' => 606,
        'why' => 'credential resolution/enqueue, deviation analysis, traffic light/summary and scheduler are separate service domains',
        'stage' => 'Etappe 5',
    ],
    'Docker/WebAPI/lib/ssh.php' => [
        'lines' => 419,
        'why' => 'SFTP is its own transport domain and moves to lib/ssh_sftp.php',
        'stage' => 'Etappe 6',
    ],
    'Docker/WebAPI/lib/ansible_inventory.php' => [
        'lines' => 714,
        'why' => 'artifact/remote command, output normalization, datastore queries and capability/host parsing',
        'stage' => 'Etappe 7',
    ],
    'Docker/WebAPI/lib/ansible_command.php' => [
        'lines' => 523,
        'why' => 'mode/marker logic and preflight/command construction change separately',
        'stage' => 'Etappe 8',
    ],
    'Docker/WebAPI/portal/deploy.php' => [
        'lines' => 667,
        'why' => 'POST dispatch, view model, queue form and job list; grows further with the live blocker model',
        'stage' => 'Etappe 12',
    ],
    'Docker/WebAPI/portal/settings.php' => [
        'lines' => 934,
        'why' => 'eleven POST actions, five tabs, view model and large renderers',
        'stage' => 'Etappe 12/14',
    ],
    'Docker/WebAPI/lib/layout.php' => [
        'lines' => 666,
        'why' => 'chrome, flash, auth, formatting, badges, status labels and catalog filter are not one display domain',
        'stage' => 'Etappe 13',
    ],
    'Docker/WebAPI/lib/system_status_panels.php' => [
        'lines' => 464,
        'why' => 'MECM, site and internal panels read separate sources; the Ansible mission-activity presenter already left for lib/system_status_ansible_activity.php (Etappe 3)',
        'stage' => 'Etappe 13',
    ],
    'Docker/WebAPI/portal/credentials.php' => [
        'lines' => 451,
        'why' => 'POST dispatch, connection tests and list renderers in one page',
        'stage' => 'Etappe 13',
    ],
    'Docker/WebAPI/lib/repo/vms.php' => [
        'lines' => 888,
        'why' => 'legacy facade, validation, bundle persistence, identity and bulk/recovery actions',
        'stage' => 'Etappe 14',
    ],
    'Docker/WebAPI/portal/vm_edit.php' => [
        'lines' => 514,
        'why' => 'diagnostics/progress, dynamic form groups and actions belong in vm_edit_* modules',
        'stage' => 'Etappe 14',
    ],

    // --- Deliberate, open-ended exceptions: splitting these by line count would
    // --- scatter an ordered registry or a frozen surface across files.
    'Docker/WebAPI/lib/migrate.php' => [
        'lines' => 1094,
        'why' => 'ordered migration registry; distributing it across files breaks the one property it has, that the order is readable in one place',
        'stage' => 'kein Abbau geplant',
    ],
    'Docker/WebAPI/lib/constants.php' => [
        'lines' => 597,
        'why' => 'SSoT constant registry; a split would create a second place to look for a value',
        'stage' => 'kein Abbau geplant',
    ],
    'Docker/WebAPI/lib/ansible_yaml.php' => [
        'lines' => 543,
        'why' => 'one coherent serializer; semantically untouched by the current plan',
        'stage' => 'kein Abbau geplant',
    ],
    'Docker/WebAPI/lib/repo/missions.php' => [
        'lines' => 463,
        'why' => 'coherent mission repository just over the target; no independent second domain today',
        'stage' => 'bei naechster fachlicher Aenderung pruefen',
    ],
    'Docker/WebAPI/lib/esxi_inventory_options.php' => [
        'lines' => 455,
        'why' => 'one presence/bucket domain just over the target',
        'stage' => 'bei naechster fachlicher Aenderung pruefen',
    ],
    'Docker/WebAPI/lib/status.php' => [
        'lines' => 418,
        'why' => 'one status-mapping domain just over the target',
        'stage' => 'bei naechster fachlicher Aenderung pruefen',
    ],
    'Docker/WebAPI/lib/errors.php' => [
        'lines' => 411,
        'why' => 'one error-handling domain just over the target',
        'stage' => 'bei naechster fachlicher Aenderung pruefen',
    ],
    'Docker/WebAPI/lib/auth.php' => [
        'lines' => 401,
        'why' => 'one session/auth domain one line over the target',
        'stage' => 'bei naechster fachlicher Aenderung pruefen',
    ],
];

$quiet = false;
$list = false;
foreach (array_slice(array_values((array) ($_SERVER['argv'] ?? [])), 1) as $arg) {
    switch ($arg) {
        case '--ci':
            break;
        case '--quiet':
        case '-q':
            $quiet = true;
            break;
        case '--list':
            $list = true;
            break;
        case '--help':
        case '-h':
            fwrite(STDOUT, "Usage: php scripts/check-file-size.php [--ci|--quiet|-q|--list|--help|-h]\n");
            exit(0);
        default:
            fwrite(STDERR, "Unknown option: {$arg}\n");
            exit(2);
    }
}

$envRoot = getenv('VIRTUSPHERE_CHECK_ROOT');
$root = is_string($envRoot) && $envRoot !== '' ? $envRoot : dirname(__DIR__);

/** Physical lines, the same unit `wc -l` reports for a newline-terminated file. */
function file_size_lines(string $path): int
{
    $source = (string) file_get_contents($path);
    if ($source === '') {
        return 0;
    }

    return substr_count($source, "\n") + (str_ends_with($source, "\n") ? 0 : 1);
}

/** @return list<string> repo-relative paths, sorted */
function file_size_scan(string $root): array
{
    $files = [];
    foreach (FILE_SIZE_SCOPE as $relativeDir) {
        $dir = $root . '/' . $relativeDir;
        if (!is_dir($dir)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                // Strip the root as a PREFIX, never with str_replace: this repo
                // has a `lib/repo/` directory, so a checkout mounted at /repo
                // had every `lib/repo/x.php` rewritten to `libx.php` and the
                // guard then reported a stat failure on a path nobody has.
                $path = str_replace('\\', '/', $file->getPathname());
                $prefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
                $files[] = str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
            }
        }
    }
    sort($files);

    return $files;
}

$files = file_size_scan($root);

// Zero-match protection. An empty scope means the scan root moved or the scope
// constant went stale, and reporting "no file is too large" would be the exact
// silent pass this guard exists to prevent.
if ($files === []) {
    echo "check-file-size: no PHP file was found in the configured scope.\n";
    echo '  [file-size.zero-match] scope: ' . implode(', ', FILE_SIZE_SCOPE) . ' under ' . $root . "\n";
    exit(1);
}

$measured = [];
foreach ($files as $relative) {
    $measured[$relative] = file_size_lines($root . '/' . $relative);
}

if ($list) {
    foreach ($measured as $relative => $lines) {
        if ($lines > FILE_SIZE_BUDGET) {
            $allowance = FILE_SIZE_ALLOWANCES[$relative]['lines'] ?? 0;
            printf("%6d  (allowance %6s)  %s\n", $lines, $allowance > 0 ? (string) $allowance : '-', $relative);
        }
    }
    exit(0);
}

$findings = [];

foreach ($measured as $relative => $lines) {
    $allowance = FILE_SIZE_ALLOWANCES[$relative] ?? null;
    if ($allowance === null) {
        if ($lines > FILE_SIZE_BUDGET) {
            $findings[] = sprintf(
                '  [file-size.oversize] %s: %d lines, budget %d. Split it by domain, or record it with a reason and a teardown stage.',
                $relative,
                $lines,
                FILE_SIZE_BUDGET
            );
        }
        continue;
    }
    if ($lines > $allowance['lines']) {
        $findings[] = sprintf(
            '  [file-size.grown] %s: %d lines, recorded allowance %d (%s, %s). An exception is a ceiling, not a budget.',
            $relative,
            $lines,
            $allowance['lines'],
            $allowance['why'],
            $allowance['stage']
        );
    }
}

// A stale exception is as misleading as a missing one: it claims a split is
// still outstanding, and it silently re-opens the budget for a file that has
// already come back under it.
foreach (FILE_SIZE_ALLOWANCES as $relative => $allowance) {
    if (!array_key_exists($relative, $measured)) {
        $findings[] = sprintf(
            '  [file-size.stale] %s is recorded as an exception but is not in the scanned scope any more; delete the entry.',
            $relative
        );
        continue;
    }
    if ($measured[$relative] <= FILE_SIZE_BUDGET) {
        $findings[] = sprintf(
            '  [file-size.stale] %s is down to %d lines and back inside the budget; delete its exception (%s).',
            $relative,
            $measured[$relative],
            $allowance['stage']
        );
    }
}

if ($findings !== []) {
    echo "check-file-size: the ADR-0006 size budget is broken.\n";
    echo "A file that bundles unrelated responsibilities hides coupling, and a stale\n";
    echo "exception hides the split it was waiting for.\n\n";
    foreach ($findings as $finding) {
        echo $finding . "\n";
    }
    echo "\nFix: split by domain (a facade may keep the public require path), or, for a\n";
    echo "file that genuinely cannot be split yet, record it in FILE_SIZE_ALLOWANCES in\n";
    echo "this script with its exact size, the reason and the stage that removes it.\n";
    exit(1);
}

if (!$quiet) {
    printf(
        "check-file-size: %d Dateien geprueft, Budget %d Zeilen, %d begruendete Ausnahmen.\n",
        count($measured),
        FILE_SIZE_BUDGET,
        count(FILE_SIZE_ALLOWANCES)
    );
}
exit(0);
