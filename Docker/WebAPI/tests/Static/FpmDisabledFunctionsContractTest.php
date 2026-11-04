<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\NodeFinder;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/PortalClosureSource.php';

/**
 * SEC2 (C-11): the FPM pool disables process execution, mail and source/config
 * disclosure. Derived, not listed: the FPM entry points are every PHP file nginx
 * can hand to FPM (all of the webroot minus the directories its deny rule names),
 * their require closure is computed from source, and the runtime Composer
 * packages come from composer.lock. A call to a disabled function anywhere in
 * that set would fail only at runtime, so it fails here. The CLI side is checked
 * the other way round: the workers need proc_open, so the list must never move
 * into the shared ini that the CLI reads too.
 */
final class FpmDisabledFunctionsContractTest extends TestCase
{
    /** The process-execution core may only grow, never be dropped. */
    private const REQUIRED = ['exec', 'passthru', 'shell_exec', 'system', 'proc_open', 'popen'];

    private static function repoRoot(): string
    {
        return str_replace('\\', '/', dirname(__DIR__, 4));
    }

    private static function webRoot(): string
    {
        return str_replace('\\', '/', dirname(__DIR__, 2));
    }

    /** @return list<string> */
    private static function disabledFunctions(string $poolConfig): array
    {
        if (preg_match_all('/^\s*php_admin_value\[disable_functions\]\s*=\s*(.+?)\s*$/m', $poolConfig, $matches) !== 1) {
            return [];
        }
        return array_values(array_filter(array_map(
            static fn (string $name): string => strtolower(trim($name)),
            explode(',', $matches[1][0])
        )));
    }

    /** @return list<string> top-level webroot directories nginx refuses before PHP */
    private static function deniedDirectories(string $nginxConfig): array
    {
        if (preg_match('/location ~ \^\/\(([a-z|]+)\)\/ \{ deny all; \}/', $nginxConfig, $match) !== 1) {
            return [];
        }
        return explode('|', $match[1]);
    }

    /** @return list<string> */
    private static function fpmEntries(PortalClosureSource $source, array $denied): array
    {
        $entries = [];
        foreach ($source->files(self::webRoot()) as $file) {
            $relative = substr($file, strlen(self::webRoot()) + 1);
            $top = str_contains($relative, '/') ? strstr($relative, '/', true) : '';
            if ($top !== '' && in_array($top, $denied, true)) {
                continue;
            }
            $entries[] = $file;
        }
        return $entries;
    }

    /** @return list<string> */
    private static function runtimePackageDirectories(): array
    {
        $lock = json_decode((string) file_get_contents(self::webRoot() . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
        $dirs = [];
        foreach ($lock['packages'] ?? [] as $package) {
            $dirs[] = self::webRoot() . '/vendor/' . $package['name'];
        }
        return $dirs;
    }

    /**
     * @param list<string> $files
     * @param list<string> $disabled
     * @param array<string,string> $overrides
     * @return list<string>
     */
    private static function callsTo(array $files, array $disabled, array $overrides = []): array
    {
        $source = new PortalClosureSource($overrides);
        $finder = new NodeFinder();
        $found = [];
        foreach ($files as $file) {
            foreach ($finder->findInstanceOf($source->nodes($file), Node\Expr\FuncCall::class) as $call) {
                if ($call->name instanceof Node\Name && in_array($call->name->toLowerString(), $disabled, true)) {
                    $found[] = $file . ':' . $call->getStartLine() . ' ' . $call->name->toString() . '()';
                }
            }
        }
        return $found;
    }

    /** @return list<string> */
    private static function fpmReachableFiles(PortalClosureSource $source): array
    {
        $denied = self::deniedDirectories((string) file_get_contents(self::repoRoot() . '/Docker/nginx/default.conf'));
        self::assertContains('lib', $denied, 'the nginx deny rule must keep lib/ away from FPM, or every worker file is an FPM entry point');
        // The owner index also resolves registry-driven requires (help partials).
        $source->owners(self::webRoot());
        $files = [];
        foreach (self::fpmEntries($source, $denied) as $entry) {
            // An entry already inside an earlier closure adds nothing new: its
            // own closure is part of that one.
            if (isset($files[$entry])) {
                continue;
            }
            foreach ($source->closure($entry) as $file) {
                if (!str_contains($file, '/vendor/')) {
                    $files[$file] = true;
                }
            }
        }
        foreach (self::runtimePackageDirectories() as $dir) {
            foreach ($source->files($dir) as $file) {
                $files[$file] = true;
            }
        }
        $list = array_keys($files);
        sort($list);
        return $list;
    }

    public function testThePoolDisablesTheProcessCoreAndTheSharedIniDoesNot(): void
    {
        $disabled = self::disabledFunctions((string) file_get_contents(self::repoRoot() . '/Docker/php/fpm.d/zz-virtusphere.conf'));
        foreach (self::REQUIRED as $name) {
            self::assertContains($name, $disabled, $name . ' must stay disabled in the FPM pool');
        }
        $ini = (string) file_get_contents(self::repoRoot() . '/Docker/php/conf.d/zz-virtusphere.ini');
        self::assertDoesNotMatchRegularExpression('/^\s*disable_functions\s*=/m', $ini,
            'conf.d is read by the CLI workers too; they need proc_open and pcntl');

        // The CLI side really needs what the pool takes away, so the split matters.
        $workerCalls = self::callsTo([self::webRoot() . '/lib/ansible_test_process.php',
            self::webRoot() . '/lib/deploy_supervisor_process.php'], $disabled);
        self::assertNotSame([], $workerCalls, 'worker files no longer call proc_open: re-derive whether the split is still needed');
    }

    public function testNoFpmReachableFileCallsADisabledFunction(): void
    {
        $disabled = self::disabledFunctions((string) file_get_contents(self::repoRoot() . '/Docker/php/fpm.d/zz-virtusphere.conf'));
        self::assertNotSame([], $disabled);
        $source = new PortalClosureSource();
        $files = self::fpmReachableFiles($source);
        self::assertContains(self::webRoot() . '/portal/login.php', $files);
        self::assertContains(self::webRoot() . '/mecm-api.php', $files);
        self::assertContains(self::webRoot() . '/lib/bootstrap.php', $files);
        self::assertNotContains(self::webRoot() . '/lib/deploy_worker.php', $files);

        $found = self::callsTo($files, $disabled);
        self::assertSame([], $found, "FPM-reachable calls to pool-disabled functions:\n" . implode("\n", $found));

        // Counter-probe: the same scan sees a call planted in a portal page.
        $login = self::webRoot() . '/portal/login.php';
        $planted = (string) preg_replace('/^<\?php/', "<?php exec('id');", (string) file_get_contents($login), 1);
        self::assertNotSame([], self::callsTo([$login], $disabled, [$login => $planted]));
    }
}
