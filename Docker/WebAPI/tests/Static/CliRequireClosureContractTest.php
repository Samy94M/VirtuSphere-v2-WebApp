<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Every function a CLI entrypoint can reach must be defined by that entrypoint's
 * own require closure.
 *
 * A portal page may call `h()` or `__t()` without requiring lib/layout.php,
 * because the portal bootstrap loaded it first. A worker has no bootstrap: its
 * closure is all there is. lib/deploy_worker_outcome.php called
 * repo_record_worker_result() without requiring lib/repo/heartbeats.php, and
 * every other caller of that module happened to require it, so nothing noticed:
 * PHPUnit loaded lib/maintenance_tasks.php, which pulls heartbeats.php in, and
 * WorkerTrafficLightTest went green through the very entry point that was broken
 * in production. The deploy worker had therefore NEVER written its System status
 * row - the page told the operator "Bereitstellungsdienst laeuft nicht, neu
 * starten" about a worker that was up and processing jobs, which is the exact
 * inversion of what that row was built for.
 *
 * The failure was invisible twice over: deploy_worker_report_alive() catches
 * Throwable so its own row can never take a job down, and Error is a Throwable,
 * so a missing require decayed into one STDERR line per minute forever. A static
 * regex contract cannot see it either - PhaseCContractTest pins the CALL and
 * passes while the callee does not exist - so the check has to be the closure.
 *
 * WHICH files are entrypoints is derived, not listed. The first version carried
 * four names by hand and was missing lib/seed.php, so the guard claimed "every
 * CLI entrypoint" while one of them was never walked. The SSoT is now the CLI
 * guard the entrypoints themselves carry (`if (PHP_SAPI !== 'cli')` at top
 * level), plus DUAL_SAPI for the one file that legitimately serves both SAPIs
 * and therefore cannot carry that guard. A second, independent scan proves that
 * registry complete from the outside: every `php .../lib/<x>.php` this project
 * actually starts (compose commands, healthchecks, setup scripts) must be a
 * registered entrypoint.
 *
 * What counts as "our function" is every name lib/ defines, never a prefix list:
 * the first draft of this test filtered on `repo_`, `deploy_`, `esxi_` and their
 * siblings and would have missed a missing require of lib/ssh.php or
 * lib/mac_import.php, which are the worker's own hot path. The index is derived,
 * so a module named outside today's conventions is covered on the day it lands.
 *
 * Two deliberate imprecisions, both erring toward silence rather than noise:
 * a require inside a function body counts as always loaded, and a call inside a
 * branch counts as always reached.
 */
final class CliRequireClosureContractTest extends TestCase
{
    /**
     * Entrypoints that run without the portal bootstrap but cannot be found by
     * the CLI guard, because they are reachable under both SAPIs on purpose.
     * Each entry names the file and the reason; a new one is a decision.
     */
    private const DUAL_SAPI = [
        'lib/migrate.php' => 'runs from the CLI (--check and apply) and from the web migration path, so it must not answer 404 under a web SAPI; migrator_out() branches on PHP_SAPI instead of refusing it',
    ];

    /**
     * Calls that are reachable in source but not at CLI runtime, each with the
     * guard that makes it so. A new entry is a decision, not a formality: name
     * the guard, or add the require.
     */
    private const GUARDED = [
        // errors.php calls it inside `if (function_exists(...))`.
        'virtusphere_csp_nonce' => 'guarded by function_exists() in lib/errors.php',
        // https_config.php short-circuits on `PHP_SAPI === 'cli' ||` first.
        'virtusphere_is_request_secure' => 'unreachable under CLI: https_redirect_if_required() returns on PHP_SAPI === cli',
        // deploy_parse_schedule() parses $_POST, so only a request can call it.
        'portal_timezone' => 'only reached from deploy_parse_schedule(), which reads $_POST',
    ];

    /** Sources that may start a PHP CLI process; scanned for the cross-check. */
    private const INVOCATION_EXTENSIONS = ['yml', 'yaml', 'sh', 'ps1'];

    public function testEveryCliEntrypointClosureDefinesWhatItCalls(): void
    {
        $root = $this->root();
        $definedSomewhere = $this->functionIndex($root);
        self::assertNotSame([], $definedSomewhere, 'the lib/ function index came back empty; this contract would pass on anything.');

        $entrypoints = $this->entrypoints();
        self::assertNotSame([], $entrypoints, 'no CLI entrypoint was discovered; this contract would pass on anything.');
        self::assertContains('lib/seed.php', $entrypoints, 'lib/seed.php carries the CLI guard but was not discovered, so the derivation is broken.');

        $problems = $this->analyse($root, $entrypoints, $definedSomewhere, self::GUARDED);
        self::assertSame([], $problems, "A CLI path calls a function nothing in its closure defines:\n - " . implode("\n - ", $problems));
    }

    /**
     * The derivation itself, in both directions: the guard finds files, and
     * every hand-registered dual-SAPI exception is still a real, CLI-aware file
     * that the guard genuinely cannot find.
     */
    public function testTheEntrypointSetIsDerivedFromTheCliGuard(): void
    {
        $guarded = $this->discoverGuardedEntrypoints($this->root());
        self::assertNotSame([], $guarded, 'the CLI-guard scan came back empty; the entrypoint derivation would silently cover nothing.');
        self::assertGreaterThan(1, count($guarded), 'the CLI-guard scan found a single file; that is a broken pattern, not a project with one worker.');

        foreach (array_keys(self::DUAL_SAPI) as $relative) {
            $path = $this->root() . '/' . $relative;
            self::assertFileExists($path, $relative . ' is registered as a dual-SAPI entrypoint but does not exist.');
            self::assertNotContains(
                $relative,
                $guarded,
                $relative . ' carries the CLI guard now, so it is discovered; drop the DUAL_SAPI entry instead of carrying a stale exception.'
            );
            self::assertStringContainsString(
                'PHP_SAPI',
                (string) file_get_contents($path),
                $relative . ' does not mention PHP_SAPI at all, so nothing shows it is CLI-aware; it does not belong in DUAL_SAPI.'
            );
        }
    }

    /**
     * The outside proof that the registry is complete. Compose commands,
     * healthchecks and setup scripts are where a PHP CLI process actually gets
     * started; anything they start and this contract does not walk is exactly
     * the gap lib/seed.php sat in.
     */
    public function testEveryPhpProcessTheProjectStartsIsARegisteredEntrypoint(): void
    {
        $repoRoot = dirname($this->root(), 2);
        if (!is_dir($repoRoot . '/Docker/scripts')) {
            self::markTestSkipped('Repo root not visible; compose files and setup scripts only exist outside the container mount.');
        }

        $invoked = $this->discoverInvokedEntrypoints($repoRoot);
        self::assertNotSame([], $invoked, 'no `php .../lib/*.php` invocation was found; this cross-check would pass on anything.');

        $registered = $this->entrypoints();
        $missing = array_values(array_diff(array_keys($invoked), $registered));
        sort($missing);
        self::assertSame(
            [],
            $missing,
            "This project starts a PHP CLI process the require-closure contract never walks:\n - "
                . implode("\n - ", array_map(static fn (string $file): string => $file . ' (' . implode(', ', $invoked[$file]) . ')', $missing))
        );
    }

    /** A guard nothing needs any more is a guard that stopped guarding. */
    public function testEveryGuardedExceptionIsStillReached(): void
    {
        $reached = [];
        foreach ($this->entrypoints() as $relative) {
            foreach ($this->requireClosure($this->root() . '/' . $relative) as $file) {
                foreach (array_keys($this->calledFunctions($file)) as $function) {
                    if (isset(self::GUARDED[$function])) {
                        $reached[$function] = true;
                    }
                }
            }
        }

        $registered = array_keys(self::GUARDED);
        sort($registered);
        $found = array_keys($reached);
        sort($found);
        self::assertSame($registered, $found, 'GUARDED names a call no CLI closure makes any more; drop it instead of carrying a stale exemption.');
    }

    /**
     * The negative case, on a fixture rather than by breaking the repo: an
     * entrypoint that calls a lib/ function no file in its closure defines has
     * to be reported, with the defining file named.
     */
    public function testTheAnalyserReportsACallOutsideTheClosure(): void
    {
        $root = $this->fixtureTree([
            'lib/entry.php' => "<?php\nrequire_once __DIR__ . '/loaded.php';\nfixture_loaded();\nfixture_orphan();\n",
            'lib/loaded.php' => "<?php\nfunction fixture_loaded(): void {}\n",
            'lib/orphan.php' => "<?php\nfunction fixture_orphan(): void {}\n",
        ]);

        $problems = $this->analyse($root, ['lib/entry.php'], $this->functionIndex($root), []);

        self::assertCount(1, $problems, 'expected exactly the one call that sits outside the closure: ' . implode(' | ', $problems));
        self::assertStringContainsString('fixture_orphan', $problems[0]);
        self::assertStringContainsString('lib/orphan.php', $problems[0]);
    }

    /** The positive case: the same call is silent once the require is there. */
    public function testTheAnalyserAcceptsAClosureThatDefinesEverythingItCalls(): void
    {
        $root = $this->fixtureTree([
            'lib/entry.php' => "<?php\nrequire_once __DIR__ . '/loaded.php';\nrequire_once __DIR__ . '/orphan.php';\nfixture_loaded();\nfixture_orphan();\n",
            'lib/loaded.php' => "<?php\nfunction fixture_loaded(): void {}\n",
            'lib/orphan.php' => "<?php\nfunction fixture_orphan(): void {}\n",
        ]);

        self::assertSame([], $this->analyse($root, ['lib/entry.php'], $this->functionIndex($root), []));
    }

    /** A GUARDED entry suppresses its call, and only its call. */
    public function testAGuardedCallIsNotReported(): void
    {
        $root = $this->fixtureTree([
            'lib/entry.php' => "<?php\nfixture_orphan();\n",
            'lib/orphan.php' => "<?php\nfunction fixture_orphan(): void {}\n",
        ]);

        self::assertSame([], $this->analyse($root, ['lib/entry.php'], $this->functionIndex($root), ['fixture_orphan' => 'fixture guard']));
    }

    /**
     * Zero-match protection, made explicit: with an empty function index the
     * analyser reports nothing at all, which is why the real test asserts the
     * index is non-empty before trusting a green result.
     */
    public function testAnEmptyFunctionIndexMakesTheAnalyserBlind(): void
    {
        $root = $this->fixtureTree([
            'lib/entry.php' => "<?php\nfixture_orphan();\n",
            'lib/orphan.php' => "<?php\nfunction fixture_orphan(): void {}\n",
        ]);

        self::assertSame([], $this->analyse($root, ['lib/entry.php'], [], []), 'an empty index must produce no findings; the real test guards against exactly this.');
        self::assertSame([], $this->discoverGuardedEntrypoints($root), 'a tree without CLI guards must discover nothing; the real test guards against exactly this.');
    }

    /** The CLI guard is what the discovery reads, not the file name. */
    public function testTheGuardScanFindsOnlyTopLevelCliGuards(): void
    {
        $root = $this->fixtureTree([
            'lib/entry.php' => "<?php\nif (PHP_SAPI !== 'cli') {\n    http_response_code(404);\n    exit;\n}\n",
            'lib/nested.php' => "<?php\nfunction fixture_web(): void {\n    if (PHP_SAPI !== 'cli') {\n        return;\n    }\n}\n",
            'lib/web.php' => "<?php\nfunction fixture_other(): void {}\n",
        ]);

        self::assertSame(['lib/entry.php'], $this->discoverGuardedEntrypoints($root));
    }

    /**
     * @param list<string> $entrypoints relative to $root
     * @param array<string, list<string>> $definedSomewhere
     * @param array<string, string> $guarded
     *
     * @return list<string>
     */
    private function analyse(string $root, array $entrypoints, array $definedSomewhere, array $guarded): array
    {
        $problems = [];
        foreach ($entrypoints as $relative) {
            $entry = $root . '/' . $relative;
            self::assertFileExists($entry, $relative . ' is registered as a CLI entrypoint but does not exist.');

            $closure = $this->requireClosure($entry);

            $available = [];
            foreach ($closure as $file) {
                $available += $this->definedFunctions($file);
            }

            foreach ($closure as $file) {
                foreach (array_keys($this->calledFunctions($file)) as $function) {
                    if (isset($available[$function]) || !isset($definedSomewhere[$function]) || isset($guarded[$function])) {
                        continue;
                    }
                    $problems[] = sprintf(
                        '%s (via %s) calls %s(), which lives in %s and is in no require of that closure',
                        $this->relative($root, $file),
                        $relative,
                        $function,
                        implode(', ', $definedSomewhere[$function])
                    );
                }
            }
        }

        $problems = array_values(array_unique($problems));
        sort($problems);

        return $problems;
    }

    /**
     * Discovered CLI guards plus the registered dual-SAPI files.
     *
     * @return list<string> relative paths, sorted
     */
    private function entrypoints(): array
    {
        $all = array_values(array_unique(array_merge(
            $this->discoverGuardedEntrypoints($this->root()),
            array_keys(self::DUAL_SAPI)
        )));
        sort($all);

        return $all;
    }

    /**
     * Files under lib/ that refuse to run outside the CLI. The guard has to sit
     * at top level: the same condition inside a function body is a branch, not
     * an entrypoint contract.
     *
     * @return list<string> relative paths, sorted
     */
    private function discoverGuardedEntrypoints(string $root): array
    {
        $found = [];
        foreach ($this->phpFilesIn($root . '/lib') as $path) {
            if (preg_match("/^if \\(PHP_SAPI !== 'cli'\\)/m", (string) file_get_contents($path)) === 1) {
                $found[] = $this->relative($root, $path);
            }
        }
        sort($found);

        return $found;
    }

    /**
     * Every `php .../lib/<x>.php` the project's own executable sources start.
     *
     * @return array<string, list<string>> relative entrypoint => sources
     */
    private function discoverInvokedEntrypoints(string $repoRoot): array
    {
        $skip = ['node_modules', 'vendor', '.git', 'qa-artifacts', 'test-results', 'playwright-report'];
        $found = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($repoRoot, FilesystemIterator::SKIP_DOTS),
                static fn (SplFileInfo $file): bool => !$file->isDir() || !in_array($file->getFilename(), $skip, true)
            )
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $name = $file->getFilename();
            if (!in_array(strtolower($file->getExtension()), self::INVOCATION_EXTENSIONS, true) && !str_starts_with($name, 'Dockerfile')) {
                continue;
            }
            // Comment lines carry prose that names these files without running
            // them ("lib/run_report.php, lib/constants.php ist die SSoT").
            $source = (string) preg_replace('/^\s*(?:#|\/\/).*$/m', '', (string) file_get_contents($file->getPathname()));
            // `php` has to sit in command position: the lookbehind rejects the
            // extension of a neighbouring path, which is how the same prose
            // otherwise reads as an invocation of the next file on the line.
            if (preg_match_all('~(?<![\w.\-/])php(?=["\s,])["\s,]+[^"\s,]*?(lib/[a-z_]+\.php)~', $source, $matches) === 0) {
                continue;
            }
            foreach ($matches[1] as $relative) {
                $found[$relative][] = str_replace([$repoRoot . DIRECTORY_SEPARATOR, '\\'], ['', '/'], $file->getPathname());
            }
        }

        foreach ($found as $relative => $sources) {
            $found[$relative] = array_values(array_unique($sources));
        }
        ksort($found);

        return $found;
    }

    /** @param array<string, string> $files relative path => contents */
    private function fixtureTree(array $files): string
    {
        $root = sys_get_temp_dir() . '/vs-cli-closure-' . bin2hex(random_bytes(6));
        foreach ($files as $relative => $contents) {
            $path = $root . '/' . $relative;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0o777, true);
            }
            file_put_contents($path, $contents);
        }

        return $root;
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private function relative(string $root, string $path): string
    {
        return str_replace([$root . DIRECTORY_SEPARATOR, $root . '/', '\\'], ['', '', '/'], $path);
    }

    /** @return list<string> absolute paths of every .php file below $dir */
    private function phpFilesIn(string $dir): array
    {
        $out = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $out[] = $file->getPathname();
            }
        }

        return $out;
    }

    /**
     * @return list<string> absolute paths, entrypoint first
     */
    private function requireClosure(string $file): array
    {
        $seen = [];
        $this->walkRequires($file, $seen);

        return array_keys($seen);
    }

    /** @param array<string, true> $seen */
    private function walkRequires(string $file, array &$seen): void
    {
        $real = realpath($file);
        if ($real === false || isset($seen[$real])) {
            return;
        }
        $seen[$real] = true;

        $source = (string) file_get_contents($real);
        if (preg_match_all('/require(?:_once)?\s+__DIR__\s*\.\s*[\'"]([^\'"]+)[\'"]/', $source, $matches) === 0) {
            return;
        }
        foreach ($matches[1] as $suffix) {
            $this->walkRequires(dirname($real) . $suffix, $seen);
        }
    }

    /**
     * Top-level function declarations. Methods carry a visibility keyword and are
     * therefore not matched; `h()` is declared inside a function_exists() guard
     * and is matched on purpose, because loading that file does define it.
     *
     * @return array<string, true> lowercased names
     */
    private function definedFunctions(string $file): array
    {
        $out = [];
        if (preg_match_all('/^\s*function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/m', (string) file_get_contents($file), $matches) !== 0) {
            foreach ($matches[1] as $name) {
                $out[strtolower($name)] = true;
            }
        }

        return $out;
    }

    /**
     * Calls this file makes, minus what it defines itself. Method calls, static
     * calls and variable calls are excluded by the lookbehind; `new Foo(` is
     * excluded explicitly. Anything left that lib/ does not define (language
     * constructs, PHP builtins) is filtered by the caller against the index.
     *
     * @return array<string, true> lowercased names
     */
    private function calledFunctions(string $file): array
    {
        $source = (string) file_get_contents($file);
        // Comments carry example calls and prose; only real code counts.
        $source = (string) preg_replace(['!/\*.*?\*/!s', '!^\s*//.*$!m'], '', $source);

        $out = [];
        if (preg_match_all('/(?<![>$:\w])(?<!new\s)([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $source, $matches) !== 0) {
            foreach ($matches[1] as $name) {
                $out[strtolower($name)] = true;
            }
        }

        return array_diff_key($out, $this->definedFunctions($file));
    }

    /**
     * Every name lib/ defines, and where. This is the SSoT for "our function":
     * a prefix list here would be a second, silently incomplete copy of it.
     *
     * @return array<string, list<string>> function => files that define it
     */
    private function functionIndex(string $root): array
    {
        $index = [];
        foreach ($this->phpFilesIn($root . '/lib') as $path) {
            foreach (array_keys($this->definedFunctions($path)) as $name) {
                $index[$name][] = $this->relative($root, $path);
            }
        }

        return $index;
    }
}
