<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/RequireClosureAnalysis.php';

/** Conditional portal helpers must be available from each route's own closure. */
final class PortalRequireClosureContractTest extends TestCase
{
    use RequireClosureAnalysis;

    public function testEveryPortalEntrypointLoadsItsHelperClosure(): void
    {
        $root = $this->root();
        $entries = array_map(
            fn (string $path): string => $this->relative($root, $path),
            glob($root . '/portal/*.php') ?: []
        );
        $index = $this->functionIndex($root);
        self::assertGreaterThan(1, count($entries));
        self::assertNotSame([], $index);
        $problems = [];
        foreach ($entries as $entry) {
            // The anonymous health route never parses a schedule. Loading a
            // portal bootstrap merely for this unreachable function is wrong.
            $guarded = $entry === 'portal/health.php'
                ? ['portal_timezone' => 'only called by deploy_parse_schedule(), never by the read-only health route']
                : [];
            $problems = array_merge($problems, $this->analyse($root, [$entry], $index, $guarded));
        }
        self::assertSame([], $problems, implode("\n", $problems));
    }
}
