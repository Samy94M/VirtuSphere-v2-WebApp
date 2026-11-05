<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/PortalClosureSource.php';
require_once __DIR__ . '/../Support/PortalClosureCalls.php';

/** Conditional portal helpers must be available from each route's own closure. */
final class PortalRequireClosureContractTest extends TestCase
{
    public function testEveryPortalEntrypointLoadsItsHelperClosure(): void
    {
        $root = str_replace('\\', '/', dirname(__DIR__, 2));
        $source = new PortalClosureSource();
        $problems = $source->problems($root, $source->files($root . '/portal'));
        self::assertSame([], $problems, implode("\n", $problems));
    }
}
