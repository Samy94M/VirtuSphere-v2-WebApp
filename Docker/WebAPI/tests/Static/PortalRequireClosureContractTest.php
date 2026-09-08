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
        $entries = $source->files($root . '/portal');
        $index = $source->owners($root);
        $problems = [];
        foreach ($entries as $entry) {
            $closure = $source->closure($entry);
            foreach ($closure as $file) {
                if (!str_contains($file, '/vendor/')) {
                    $calls = new PortalClosureCalls($index, $source->available($entry) + $source->available($file), $source->methods, $source);
                    $problems = array_merge($problems, $calls->check($source->nodes($file), $file));
                }
            }
        }
        $problems = array_values(array_unique($problems));
        self::assertSame([], $problems, implode("\n", $problems));
    }
}
