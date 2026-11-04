<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/auth_password_rehash.php';

/**
 * SEC1 (C-04): a sign-in for an unknown, locked or deactivated name must cost
 * what a real password comparison costs, or the response time names the
 * accounts that exist. The blind hash used to be bcrypt cost 10 while
 * PASSWORD_DEFAULT is cost 12 since PHP 8.4, a fourfold difference.
 */
final class AuthBlindHashTest extends TestCase
{
    public function testTheBlindHashHasTheAlgorithmAndCostOfEveryStoredHash(): void
    {
        // Derived, not pinned: the same predicate that lifts stored hashes to
        // the current default on sign-in must find nothing to lift here. A PHP
        // release that raises the default cost turns this red.
        self::assertFalse(
            password_needs_rehash(VIRTUSPHERE_AUTH_BLIND_PASSWORD_HASH, PASSWORD_DEFAULT),
            'the blind hash differs in algorithm or cost from PASSWORD_DEFAULT; regenerate it with password_hash()'
        );
    }

    public function testNoUsableHashNeverMatches(): void
    {
        foreach ([null, '', 'not-a-hash', 42] as $stored) {
            self::assertFalse(auth_password_verify_constant_cost('anything', $stored));
        }
        self::assertFalse(auth_password_verify_constant_cost('', null));
    }

    public function testARealHashStillDecides(): void
    {
        $hash = password_hash('correct horse', PASSWORD_DEFAULT);
        self::assertTrue(auth_password_verify_constant_cost('correct horse', $hash));
        self::assertFalse(auth_password_verify_constant_cost('wrong horse', $hash));
    }
}
