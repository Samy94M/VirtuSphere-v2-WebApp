<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PasswordFieldContractTest extends TestCase
{
    public function testEveryPasswordEntryMirrorsTheServerPolicyAndAutocompletePurpose(): void
    {
        $root = str_replace('\\', '/', dirname(__DIR__, 2));
        $account = (string) file_get_contents($root . '/portal/account.php');
        $users = (string) file_get_contents($root . '/lib/users_accounts_panels.php');

        self::assertStringContainsString('autocomplete="current-password"', $account);
        self::assertSame(2, substr_count($account, 'autocomplete="new-password"'));
        self::assertSame(2, substr_count($account, 'minlength="<?php echo h((string) $passwordMinLength); ?>"'));
        self::assertStringContainsString('account.password_hint', $account);

        self::assertSame(2, substr_count($users, 'autocomplete="new-password"'));
        self::assertSame(2, substr_count($users, 'minlength="<?php echo h((string) $passwordMinLength); ?>"'));
        self::assertStringContainsString('for="<?php echo h($passwordInputId); ?>"', $users);
        self::assertStringContainsString('users.reset_password_label', $users);
        self::assertStringContainsString('users.password_hint', $users);
    }
}
