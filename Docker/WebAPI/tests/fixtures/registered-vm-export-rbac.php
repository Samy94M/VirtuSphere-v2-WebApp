<?php

declare(strict_types=1);

// Loaded only by an isolated presenter-test process, never by the product.
function can(string $permission, ?array $user = null): bool
{
    PHPUnit\Framework\TestCase::assertSame('vms.write', $permission);
    return (bool) ($GLOBALS['k3_can_write'] ?? false);
}
