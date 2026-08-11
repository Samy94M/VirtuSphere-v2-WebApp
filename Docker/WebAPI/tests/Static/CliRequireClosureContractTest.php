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
    /** Entrypoints that run without the portal bootstrap. */
    private const ENTRYPOINTS = [
        'lib/deploy_worker.php',
        'lib/maintenance_worker.php',
        'lib/worker_healthcheck.php',
        'lib/migrate.php',
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

    public function testEveryCliEntrypointClosureDefinesWhatItCalls(): void
    {
        $definedSomewhere = $this->functionIndex();
        self::assertNotSame([], $definedSomewhere, 'the lib/ function index came back empty; this contract would pass on anything.');

        $problems = [];
        foreach (self::ENTRYPOINTS as $relative) {
            $entry = $this->root() . '/' . $relative;
            self::assertFileExists($entry, $relative . ' is registered as a CLI entrypoint but does not exist.');

            $closure = $this->requireClosure($entry);
            self::assertGreaterThan(1, count($closure), $relative . ': the require scan found no dependencies, so this contract would pass on anything.');

            $available = [];
            foreach ($closure as $file) {
                $available += $this->definedFunctions($file);
            }

            foreach ($closure as $file) {
                foreach (array_keys($this->calledFunctions($file)) as $function) {
                    if (isset($available[$function]) || !isset($definedSomewhere[$function]) || isset(self::GUARDED[$function])) {
                        continue;
                    }
                    $problems[] = sprintf(
                        '%s (via %s) calls %s(), which lives in %s and is in no require of that closure',
                        $this->relative($file),
                        $relative,
                        $function,
                        implode(', ', $definedSomewhere[$function])
                    );
                }
            }
        }

        $problems = array_values(array_unique($problems));
        sort($problems);
        self::assertSame([], $problems, "A CLI path calls a function nothing in its closure defines:\n - " . implode("\n - ", $problems));
    }

    /** A guard nothing needs any more is a guard that stopped guarding. */
    public function testEveryGuardedExceptionIsStillReached(): void
    {
        $reached = [];
        foreach (self::ENTRYPOINTS as $relative) {
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

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private function relative(string $path): string
    {
        return str_replace([$this->root() . DIRECTORY_SEPARATOR, '\\'], ['', '/'], $path);
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
    private function functionIndex(): array
    {
        $index = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root() . '/lib', FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            foreach (array_keys($this->definedFunctions($file->getPathname())) as $name) {
                $index[$name][] = $this->relative($file->getPathname());
            }
        }

        return $index;
    }
}
