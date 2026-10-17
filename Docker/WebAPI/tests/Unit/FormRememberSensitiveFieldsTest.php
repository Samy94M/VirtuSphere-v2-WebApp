<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * form_remember() stashes a failed form's posted values in the session. A
 * password must never be among them (QL-05: a failed directory save kept
 * bind_password in plaintext in $_SESSION['_form_state']).
 *
 * form_state() memoizes the session read in a function static, so each case runs
 * in its own process, the same harness as FormOldArrayTest.
 */
final class FormRememberSensitiveFieldsTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testADirectorySaveKeepsNoPasswordAndKeepsTheRestOfThePost(): void
    {
        $_SESSION = [];

        form_remember('directory', [
            '_csrf' => 'token-value',
            'bind_user' => 'svc-ldap',
            'bind_password' => 'bind-secret-value',
            'current_password' => 'old-value',
            'new_password' => 'new-value',
            'confirm_password' => 'new-value',
        ], ['bind_user' => 'required']);

        $stashed = self::stashedForm('directory');
        self::assertSame(['bind_user' => 'svc-ldap'], $stashed['old']);
        self::assertSame(['bind_user' => 'required'], $stashed['errors']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPasswordMinLengthSurvivesAValidationError(): void
    {
        $_SESSION = [];

        form_remember('password_policy', ['password_min_length' => '12'], []);

        $old = self::stashedForm('password_policy')['old'] ?? null;
        self::assertIsArray($old);
        self::assertSame('12', $old['password_min_length'] ?? null);
    }

    /**
     * The stash of one form as form_remember() left it. Read in its own scope:
     * after `$_SESSION = []` the analyser would otherwise know the session only as
     * the empty array it was assigned.
     *
     * @return array<string, mixed>
     */
    private static function stashedForm(string $form): array
    {
        $state = $_SESSION['_form_state'][$form] ?? [];

        return is_array($state) ? $state : [];
    }
}
