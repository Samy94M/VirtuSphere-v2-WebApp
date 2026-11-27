<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/layout.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_log_recovery.php';

/**
 * P1a-3 (TS1-K3): in the recovery block of a job log only the cleanup row reads
 * as a sentence, because it leads to the retry button; the other remote states
 * stay technical details in code font (i18n R13).
 */
final class DeployLogRecoveryCleanupLabelTest extends TestCase
{
    private string $previousLocale;

    protected function setUp(): void
    {
        $this->previousLocale = Lang::locale();
    }

    protected function tearDown(): void
    {
        Lang::load($this->previousLocale);
    }

    public function testEveryCleanupStateHasASentenceAndNoneShowsItsToken(): void
    {
        self::assertNotEmpty(VIRTUSPHERE_REMOTE_CLEANUP_STATES, 'zero-match: no cleanup state');
        foreach (Lang::LOCALES as $locale) {
            Lang::load($locale);
            $labels = [];
            foreach (VIRTUSPHERE_REMOTE_CLEANUP_STATES as $state) {
                $label = deploy_log_recovery_cleanup_label($state);
                self::assertNotSame('deploy.recovery_cleanup_state_' . $state, $label, $locale . ': ' . $state . ' has no label');
                self::assertNotSame($state, $label, $locale . ': ' . $state . ' shows its token');
                $labels[] = $label;
            }
            self::assertSame(count($labels), count(array_unique($labels)), $locale . ': two states read the same');
            $unknown = deploy_log_recovery_cleanup_label('something_new');
            self::assertSame(__t('deploy.recovery_cleanup_state_unknown'), $unknown);
            self::assertStringNotContainsString('something_new', $unknown, 'an unknown value gets a neutral sentence, never its token');
        }
    }

    public function testTheBlockRendersTheCleanupSentenceAndTheOtherStatesAsCode(): void
    {
        Lang::load('en');
        // The block carries two POST forms, whose CSRF field needs a session.
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_start();
        }
        ob_start();
        try {
            deploy_log_render_recovery(['id' => 7], [
                'id' => 3, 'controller_state' => 'exited_nonzero', 'effect_state' => 'unknown',
                'reconciliation_state' => 'manual_required', 'cleanup_state' => 'failed',
                'cleanup_attempts' => 2, 'cleanup_last_error' => null, 'result_sha256' => '',
            ], ['id' => 1, 'role' => 'admin']);
        } finally {
            $html = (string) ob_get_clean();
        }

        foreach (['exited_nonzero', 'unknown', 'manual_required'] as $token) {
            self::assertStringContainsString('<code>' . $token . '</code>', $html);
        }
        self::assertStringContainsString(h(__t('deploy.recovery_cleanup_state_failed')), $html);
        self::assertStringNotContainsString('<code>failed</code>', $html);
    }
}
