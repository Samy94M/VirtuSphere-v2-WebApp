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
    // lib/ansible_inventory.php (714) left this table in Etappe 7: artifact/
    // remote command, output normalization, datastore/query and capability/host
    // parsing are now separate modules behind a facade.
    // lib/ansible_command.php (523) left this table in Etappe 8: shell quoting,
    // mode/marker planning and the preflight/probe domain are now separate
    // modules behind a facade.
    // lib/layout.php (706) left this table with the Etappe-8 rest findings:
    // response/flash and status presenters are now separate from page chrome.
    // lib/system_status_panels.php (464) left this table in Etappe 13: the
    // facade keeps only the overview strip, which is the one presenter reading
    // all four sources, and MECM/site, Ansible and the internal services each
    // own the module of their own data source.
    // portal/credentials.php (479) left this table in the same stage: the page
    // is auth, RBAC and the request shell, the POST dispatch and the two ESXi
    // side effects live in lib/credentials_actions.php, and the view model plus
    // both panels in lib/credentials_panels.php.
    //
    // Six ceilings were raised once, in Etappe 10C, and the reason is recorded
    // here rather than six times below: a structured audit call is longer than
    // the one-line sentence it replaced. `audit($db, CATEGORY, 'created
    // credential id ' . $id, $userId)` became an event code, an object, a
    // result and a typed context, which is three to five lines per producer.
    // That is the cost of the trade and it was accepted: the alternative was to
    // keep a free sentence as the audit trail's only structure. The ratchet is
    // otherwise unchanged, and lib/auth.php was compacted back under the budget
    // instead of gaining an entry, because a file that has never had one should
    // not acquire one for four extra lines.
    // Etappe 13R raised two more, both registries whose growth IS the change:
    // constants.php 641->645 (the deploy service card anchor) and migrate.php
    // 1222->1248 (migration 0045 plus migrator_foreign_key_exists(), which the
    // registry needed because migrator_check_exists() only ever matched CHECK
    // constraints and therefore silently passed a duplicate foreign key on a
    // fresh schema). Neither may be split for the reason each records below.
    // Raised: credentials.php 451->479 and constants.php 603->641 (the audit event
    // registry's endpoint list/report-channel version plus the Etappe-10D
    // PowerShell log help mirrors), migrate.php 1220->1222 (migration 0044).
    // Etappe 14B raised ansible_yaml.php 543->545: the generated serverlist
    // gained portal_vm_id, the selector a per-VM create playbook picks its one
    // target by. That is one emitted field plus its one-line reason, and the
    // serializer stays the single coherent thing it was. The preflight module
    // was NOT raised in the same stage: it crossed the plain budget and was
    // split into lib/ansible_command_probes.php instead.
    // Etappe 14B raised migrate.php 1248->1250 for migration 0047 and 1250->1252
    // for 0048: a migration costs the registry exactly its require line and its
    // map entry, which is the growth the registry exists for. Their bodies live
    // in lib/migrations/ like every migration since 0042. Etappe 14C raised it
    // 1252->1254 for 0049, the same two lines for the same reason.
    // Etappe 14D raised it 1254->1259 for 0050: the same two registry lines plus
    // a three-line require of lib/mecm_hostname.php, which the migration's
    // preflight and backfill call and which no bootstrap loads for the CLI. The
    // same stage raised constants.php 645->722: the three rollout-hostname
    // constants and their reasons (the NetBIOS 15, the initial revision and the
    // migration's report limit), the closed reset-blocker vocabulary, and the
    // explicit getDeviceList projection. That last one is the largest single
    // entry because it names all 29 deploy_vms columns the wire has always
    // carried plus the two additive ones; it is the wire contract's SSoT and
    // belongs where every other SSoT constant sits, not next to one caller.
    // Etappe 15E raised constants.php 722->727: the bound on the deploy jobs listed
    // beside an exact correlation search, with the reason it is bounded at all
    // (one portal request can enqueue a staggered batch) and that the panel is an
    // orientation aid rather than the job list, which deploy.php owns.
    // --- Deliberate, open-ended exceptions: splitting these by line count would
    // --- scatter an ordered registry or a frozen surface across files.
    'Docker/WebAPI/lib/migrate.php' => [
        'lines' => 1259,
        'why' => 'ordered migration registry; distributing it across files breaks the one property it has, that the order is readable in one place',
        'stage' => 'kein Abbau geplant',
    ],
    'Docker/WebAPI/lib/constants.php' => [
        'lines' => 727,
        'why' => 'SSoT constant registry; a split would create a second place to look for a value',
        'stage' => 'kein Abbau geplant',
    ],
    'Docker/WebAPI/lib/ansible_yaml.php' => [
        'lines' => 545,
        'why' => 'one coherent serializer; grew by the one artifact field Etappe 14B needed',
        'stage' => 'kein Abbau geplant',
    ],
    // Etappe 14D was the "next functional change" this exception was waiting
    // for, and the check happened: the one part that had its own domain, the
    // hostname-claim reconciliation across the template boundary, moved to
    // lib/repo/vm_rollout.php, which owns the claim table. What is left here is
    // the mission rename hook and the rollout initialisation of a clone, and
    // both are decisions ABOUT a mission, not a second domain; splitting them
    // out would scatter one transaction across two files.
    'Docker/WebAPI/lib/repo/missions.php' => [
        'lines' => 504,
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
        'lines' => 427,
        'why' => 'one error-handling domain just over the target; Etappe 10C wired the log redaction through every sink it owns (file, audit, STDERR, fallback, debug render) without adding a second domain',
        'stage' => 'Etappe 15: Praesentation (HTML-/JSON-/CLI-Renderer, Debug-Gate, Nonce) in ein eigenes Modul ziehen, wenn die Korrelationsanzeige diese Renderer ohnehin anfasst',
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
