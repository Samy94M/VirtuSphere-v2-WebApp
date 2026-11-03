<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SshHostIdentityContractTest extends TestCase
{
    public function testVendorReconnectAndPingRetainVirtualLoginDispatch(): void
    {
        $bodies = [];
        foreach (['ping', 'reconnect'] as $name) {
            $method = new ReflectionMethod(phpseclib3\Net\SSH2::class, $name);
            $filename = $method->getFileName();
            self::assertIsString($filename);
            $lines = file($filename);
            self::assertIsArray($lines);
            $start = $method->getStartLine();
            $end = $method->getEndLine();
            self::assertIsInt($start);
            self::assertIsInt($end);
            $bodies[$name] = implode('', array_slice($lines, $start - 1, $end - $start + 1));
        }
        self::assertStringContainsString('$this->login(...$auth)', $bodies['reconnect']);
        self::assertStringContainsString('$this->reconnect()', $bodies['ping']);
    }

    public function testHostIdentityMigrationIsClassCWithAnExplicitReturnBoundary(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 4) . '/docs/operations/upgrade-recovery.md');
        self::assertSame(1, preg_match('/^\| C [^|]+\| ([^|]+) \|/m', $source, $row));
        self::assertStringContainsString('0059', $row[1]);
        self::assertStringContainsString('Migration 0059', $source);
        self::assertStringContainsString('ohne Host-Schlüsselprüfung', $source);
    }
    public function testProductTransportsCannotBypassTheHostGuard(): void
    {
        $sources = [];
        $root = dirname(__DIR__, 2);
        foreach (['lib', 'portal'] as $area) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $area, FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $sources[$file->getPathname()] = (string) file_get_contents($file->getPathname());
                }
            }
        }
        self::assertSame([], $this->transportProblems($sources));
    }

    public function testGuardDetectsDirectLoginConstructorsAndEmptyInput(): void
    {
        self::assertNotSame([], $this->transportProblems([]));
        self::assertNotSame([], $this->transportProblems(['fixture.php' => '<?php $s = new SSH2("host");']));
        self::assertNotSame([], $this->transportProblems(['fixture.php' => '<?php $s->login("worker", "fixture");']));
    }

    public function testTrustDocumentHasTheRequiredStructureAndAccessibleDiagram(): void
    {
        $root = dirname(__DIR__, 4);
        $source = (string) file_get_contents($root . '/docs/operations/trust-flows.md');
        $index = (string) file_get_contents($root . '/docs/operations/flows.md');
        self::assertSame([], $this->documentProblems($source, $index));
        preg_match_all('/\]\(([^)]+)\)/', $source, $links);
        self::assertNotSame([], $links[1]);
        foreach ($links[1] as $link) {
            $path = explode('#', $link, 2)[0];
            self::assertFileExists($root . '/docs/operations/' . $path, $link);
        }
    }

    public function testDocumentGuardRejectsEmptyMissingTitleOversizeAndLegendDrift(): void
    {
        $root = dirname(__DIR__, 4);
        $source = (string) file_get_contents($root . '/docs/operations/trust-flows.md');
        $index = (string) file_get_contents($root . '/docs/operations/flows.md');
        self::assertNotSame([], $this->documentProblems('', $index));
        foreach ([
            str_replace('accTitle:', 'title:', $source),
            str_replace('fill:#eef2ff', 'fill:#000000', $source),
            $source . "\nEXTRAA[one]\nEXTRAB[two]\nEXTRAC[three]\n",
        ] as $mutant) {
            self::assertNotSame($source, $mutant);
            self::assertNotSame([], $this->documentProblems($mutant, $index));
        }
    }

    /** @return list<string> */
    private function documentProblems(string $source, string $index): array
    {
        $problems = [];
        foreach (['Zweck:', 'accTitle:', 'accDescr:', '### Wenn etwas schiefgeht', '### Im Code', '### Prüfung', '### Betrieb', 'flows.md'] as $required) {
            if (!str_contains($source, $required)) { $problems[] = 'missing ' . $required; }
        }
        if (substr_count($source, '```mermaid') !== 1) { $problems[] = 'expected one trust diagram'; }
        preg_match_all('/\b([A-Z]+)(?:\[|\{)/', $source, $matches);
        $nodes = array_unique($matches[1]);
        if ($nodes === [] || preg_match('/flow-node-limit:\s*(\d+)/', $index, $limit) !== 1
            || count($nodes) > (int) $limit[1]) { $problems[] = 'node limit or empty diagram'; }
        preg_match_all('/^classDef [^\r\n]+/m', $index, $legend);
        preg_match_all('/^\s*(classDef [^\r\n]+)/m', $source, $classes);
        if ($legend[0] === [] || $legend[0] !== $classes[1]) { $problems[] = 'legend drift'; }
        if (!str_contains($index, 'trust-flows.md')) { $problems[] = 'missing index entry'; }
        return $problems;
    }

    /** @param array<string,string> $sources @return list<string> */
    private function transportProblems(array $sources): array
    {
        if ($sources === []) { return ['transport scan empty']; }
        $problems = [];
        foreach ($sources as $path => $source) {
            $owner = dirname(__DIR__, 2) . '/lib/ssh_host_identity.php';
            if (str_replace('\\', '/', $path) === str_replace('\\', '/', $owner)) { continue; }
            if (preg_match('/new\s+(?:\\\\?phpseclib3\\\\Net\\\\)?(?:SSH2|SFTP)\s*\(/', $source) === 1) {
                $problems[] = $path . ': unguarded constructor';
            }
            if (preg_match('/->login\s*\(/', $source) === 1) {
                $problems[] = $path . ': direct authentication';
            }
        }
        return $problems;
    }
}
