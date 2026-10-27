<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Support/OperationsFlowDoc.php';

/**
 * Every flow document that docs/operations/flows.md links names its owners in
 * backticks: `php_function()`, `VIRTUSPHERE_CONSTANT`, `Verb-VsNoun` and repo
 * paths. Each of them must still exist, so a rename in the code cannot leave a
 * diagram pointing at nothing. The documents come from the links in flows.md,
 * the names from the code; nothing is listed here. Which backticked paths are
 * repo paths is derived from the tree (repoPathResolver()), not listed either.
 */
final class OperationsFlowReferencesContractTest extends TestCase
{
    private const INDEX = 'docs/operations/flows.md';

    public function testEveryBacktickedOwnerOfTheFlowDocumentsExists(): void
    {
        $flows = OperationsFlowDoc::load(self::INDEX);
        if ($flows === null) {
            self::markTestSkipped('Repo root not visible; docs/ only exists outside the container mount.');
        }
        $root = OperationsFlowDoc::repoRoot();
        $documents = self::linkedDocuments($flows);
        self::assertNotSame([], $documents, 'flows.md links no flow document (zero-match must not pass).');

        $php = self::phpOwners($root . '/Docker/WebAPI');
        $powershell = self::powershellFunctions($root . '/Powershell-MECM');
        self::assertNotSame([], $php['functions']);
        self::assertNotSame([], $php['constants']);
        self::assertNotSame([], $powershell);

        $problems = [];
        foreach ($documents as $document) {
            $text = (string) OperationsFlowDoc::load('docs/operations/' . $document);
            $problems = array_merge($problems, self::referenceProblems(
                $document,
                $text,
                $php['functions'],
                $php['constants'],
                $powershell,
                self::repoPathResolver($root, $root . '/docs/operations')
            ));
        }

        self::assertSame([], $problems);
    }

    public function testReferenceProblemsRejectEveryKindOfDeadName(): void
    {
        $exists = static fn (string $path): ?bool => str_starts_with($path, 'host/') ? null : ($path === 'docs/a.md' || $path === 'lib/');
        $ok = 'Calls `known_fn()`, `json_encode()`, `VIRTUSPHERE_KNOWN`, `Send-VsThing`, `docs/a.md` and `lib/`; ignores `plain`, `a b/c`, `X-Header` and the runtime path `host/x/`.';
        self::assertSame([], self::referenceProblems('d.md', $ok, ['known_fn' => true], ['VIRTUSPHERE_KNOWN' => true], ['send-vsthing' => true], $exists));

        self::assertSame(
            [
                'd.md names function `gone_fn()`, which no PHP source declares.',
                'd.md names constant `VIRTUSPHERE_GONE`, which no PHP source declares.',
                'd.md names PowerShell function `Get-VsGone`, which Powershell-MECM does not declare.',
                'd.md names path `docs/gone.md`, which does not exist.',
            ],
            self::referenceProblems('d.md', 'See `gone_fn()`, `VIRTUSPHERE_GONE`, `Get-VsGone` and `docs/gone.md`.', [], [], [], $exists)
        );
    }

    public function testLinkedDocumentsAreTheLocalMarkdownLinksOfTheIndex(): void
    {
        self::assertSame(['a.md', 'b.md'], self::linkedDocuments('[A](a.md), [B](b.md#x), [A again](a.md), [up](../README.md), [web](https://x/y.md)'));
        self::assertSame([], self::linkedDocuments('no links'));
    }

    /**
     * @param array<string,true> $functions
     * @param array<string,true> $constants
     * @param array<string,true> $powershell lower-cased function names
     * @param callable(string):?bool $pathExists null: the token is no repo path
     * @return list<string>
     */
    public static function referenceProblems(string $label, string $text, array $functions, array $constants, array $powershell, callable $pathExists): array
    {
        $internal = array_fill_keys(get_defined_functions()['internal'], true);
        $problems = [];
        preg_match_all('/`([^`\n]+)`/', $text, $matches);
        foreach (array_unique($matches[1]) as $token) {
            if (preg_match('/^([a-z_][a-z0-9_]*)\(\)$/', $token, $function) === 1) {
                if (!isset($functions[$function[1]]) && !isset($internal[$function[1]])) {
                    $problems[] = sprintf('%s names function `%s`, which no PHP source declares.', $label, $token);
                }
            } elseif (preg_match('/^VIRTUSPHERE_[A-Z0-9_]+$/', $token) === 1) {
                if (!isset($constants[$token])) {
                    $problems[] = sprintf('%s names constant `%s`, which no PHP source declares.', $label, $token);
                }
            } elseif (preg_match('/^[A-Z][a-z]+-Vs[A-Za-z0-9]+$/', $token) === 1) {
                if (!isset($powershell[strtolower($token)])) {
                    $problems[] = sprintf('%s names PowerShell function `%s`, which Powershell-MECM does not declare.', $label, $token);
                }
            } elseif (preg_match('#^[A-Za-z0-9_.-]+(/[A-Za-z0-9_.-]+)*/?$#', $token) === 1 && str_contains($token, '/')
                && (str_ends_with($token, '/') || preg_match('/\.[A-Za-z0-9]+$/', $token) === 1)) {
                if ($pathExists($token) === false) {
                    $problems[] = sprintf('%s names path `%s`, which does not exist.', $label, $token);
                }
            }
        }

        return $problems;
    }

    public function testRepoPathResolverDerivesTheRootsFromTheTree(): void
    {
        $root = OperationsFlowDoc::repoRoot();
        if (!is_dir($root . '/docs')) {
            self::markTestSkipped('Repo root not visible; docs/ only exists outside the container mount.');
        }
        $resolve = self::repoPathResolver($root, $root . '/docs/operations');

        self::assertTrue($resolve('docs/operations/flows.md'), 'a path from the repo root');
        self::assertTrue($resolve('lib/deploy_constants.php'), 'a path from the PHP app root');
        self::assertTrue($resolve('tests/powershell/'), 'tests/ exists in both roots; either may hold the path');
        self::assertTrue($resolve('../audits/'), 'a relative path resolves from the document');
        self::assertFalse($resolve('lib/gone.php'));
        self::assertFalse($resolve('docs/gone.md'));
        self::assertFalse($resolve('../gone.md'));
        self::assertNull($resolve('virtusphere/Ansible/host-identity/'), 'a runtime path on a host is no repo path');
    }

    /**
     * Resolves a backticked path. Documents name paths from the repo root
     * (`docs/...`, `Ansible/...`) or from the PHP app root (`lib/...`), and
     * relative ones from their own folder. A path whose first segment exists in
     * neither root is a runtime path on a host, not a repo path: null.
     *
     * @return callable(string):?bool
     */
    public static function repoPathResolver(string $root, string $documentDir): callable
    {
        $roots = [$root, $root . '/Docker/WebAPI'];

        return static function (string $path) use ($roots, $documentDir): ?bool {
            $path = rtrim($path, '/');
            if (str_starts_with($path, './') || str_starts_with($path, '../')) {
                return file_exists($documentDir . '/' . $path);
            }
            $first = explode('/', $path)[0];
            $candidates = array_values(array_filter($roots, static fn (string $base): bool => file_exists($base . '/' . $first)));
            if ($candidates === []) {
                return null;
            }
            foreach ($candidates as $base) {
                if (file_exists($base . '/' . $path)) {
                    return true;
                }
            }

            return false;
        };
    }
    /** @return list<string> file names of the local .md links, in order of first use */
    public static function linkedDocuments(string $flows): array
    {
        preg_match_all('/\]\(([A-Za-z0-9_-]+\.md)(#[^)]*)?\)/', $flows, $matches);

        return array_values(array_unique($matches[1]));
    }

    /** @return array{functions: array<string,true>, constants: array<string,true>} */
    private static function phpOwners(string $base): array
    {
        $functions = [];
        $constants = [];
        $skip = '#[\\\\/](vendor|tests|node_modules)[\\\\/]#';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $path = (string) $file;
            if (!str_ends_with($path, '.php') || preg_match($skip, substr($path, strlen($base))) === 1) {
                continue;
            }
            $source = (string) file_get_contents($path);
            preg_match_all('/^\s*function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/m', $source, $declared);
            foreach ($declared[1] as $name) {
                $functions[$name] = true;
            }
            preg_match_all("/^\s*(?:const\s+(VIRTUSPHERE_[A-Z0-9_]+)\s*=|define\(\s*'(VIRTUSPHERE_[A-Z0-9_]+)')/m", $source, $defined);
            foreach (array_merge($defined[1], $defined[2]) as $name) {
                if ($name !== '') {
                    $constants[$name] = true;
                }
            }
        }

        return ['functions' => $functions, 'constants' => $constants];
    }

    /** @return array<string,true> lower-cased function names */
    private static function powershellFunctions(string $base): array
    {
        if (!is_dir($base)) {
            return [];
        }
        $names = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $path = (string) $file;
            if (preg_match('/\.psm?1$/', $path) !== 1) {
                continue;
            }
            preg_match_all('/^\s*function\s+([A-Za-z]+-[A-Za-z0-9]+)/mi', (string) file_get_contents($path), $declared);
            foreach ($declared[1] as $name) {
                $names[strtolower($name)] = true;
            }
        }

        return $names;
    }
}
