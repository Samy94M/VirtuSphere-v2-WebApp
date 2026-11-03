<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/layout.php';
require_once dirname(__DIR__, 2) . '/lib/credentials_host_identity.php';

/**
 * UX-HK, at the markup level: the System status link lands on the credential's
 * row (HK-5), and the confirmed state says whether the host answered with the key
 * since the confirmation (HK-7). The confirmation form itself needs a session for
 * its CSRF field and is covered by the integration and browser suites.
 */
final class CredentialHostIdentityRenderTest extends TestCase
{
    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private static function credential(array $overrides = []): array
    {
        $fingerprint = 'SHA256:' . rtrim(base64_encode(hash('sha256', 'render-fixture', true)), '=');

        return array_replace([
            'id' => 7,
            'name' => 'ansible-fixture',
            'config_revision' => 1,
            'ansible_host_fingerprint' => $fingerprint,
            'ansible_host_key_type' => 'ssh-ed25519',
            'ansible_host_observed_fingerprint' => $fingerprint,
            'ansible_host_observed_type' => 'ssh-ed25519',
            'ansible_host_observed_at' => '2026-10-02 10:00:00',
            'ansible_host_confirmed_at' => '2026-10-01 09:00:00',
            'ansible_host_confirmed_by' => 1,
            'ansible_host_first_seen_at' => '2026-09-30 08:00:00',
            'ansible_host_accept_new' => 0,
        ], $overrides);
    }

    /** @param array<string, mixed> $credential */
    private function render(array $credential, bool $canManage = false): string
    {
        ob_start();
        credential_render_host_identity($credential, $canManage);

        return (string) ob_get_clean();
    }

    public function testTheSystemStatusLinkPointsAtTheCredentialRow(): void
    {
        $html = $this->render(self::credential(), true);
        self::assertStringContainsString('href="credentials.php#host-identity-7"', $html);
    }

    public function testWithoutTheManagePermissionNoLinkIsRendered(): void
    {
        self::assertStringNotContainsString('credentials.php#', $this->render(self::credential(), false));
    }

    public function testAConfirmedKeyTheHostAnsweredAfterwardsShowsTheLastMatch(): void
    {
        $html = $this->render(self::credential());
        self::assertStringContainsString('data-host-identity-contact="matching"', $html);
        self::assertSame(['matching' => true, 'when' => '2026-10-02 10:00:00'], credential_host_identity_contact(self::credential()));
    }

    public function testAConfirmedKeyWithoutAConnectionSinceShowsNoMatch(): void
    {
        $credential = self::credential(['ansible_host_observed_at' => '2026-09-30 08:00:00']);
        $html = $this->render($credential);
        self::assertStringContainsString('data-host-identity-contact="idle"', $html);
        self::assertSame(['matching' => false, 'when' => ''], credential_host_identity_contact($credential));
    }

    public function testTheContactLineExistsOnlyForAConfirmedKey(): void
    {
        $provisional = self::credential(['ansible_host_confirmed_at' => null]);
        self::assertNull(credential_host_identity_contact($provisional));
        self::assertStringNotContainsString('data-host-identity-contact', $this->render($provisional));
    }

    public function testTheStatesThatNeedAnActionOpenTheRowByThemselves(): void
    {
        foreach (['provisional', 'unconfirmed', 'mismatch'] as $state) {
            self::assertTrue(credential_host_identity_needs_action($state), $state);
        }
        foreach (['confirmed', 'upgrade_pending'] as $state) {
            self::assertFalse(credential_host_identity_needs_action($state), $state);
        }
    }

    /**
     * UX-HK (HK-3): the confirmation form lives in the full-width row with the anchor, never in the
     * narrow action column. The form needs a session for its CSRF field, so the layout is pinned
     * by the source: the page renders the row, and only the row renders the form.
     */
    public function testTheFormLivesOnlyInTheFullWidthRowWithTheAnchor(): void
    {
        $root = dirname(__DIR__, 2) . '/lib/';
        $panels = (string) file_get_contents($root . 'credentials_panels.php');
        $module = (string) file_get_contents($root . 'credentials_host_identity.php');
        self::assertStringContainsString('credential_render_host_identity_row(', $panels);
        self::assertStringNotContainsString('credential_render_host_identity_form(', $panels);
        self::assertStringContainsString('<td colspan="8">', $module);
        self::assertStringContainsString('id="<?php echo h(credential_host_identity_anchor($id)); ?>"', $module);
    }

    /**
     * UX-HK (HK-5): the failed-test message for a host key names the credential's row. The branch
     * needs a session for can(), so its link is pinned by source here, next to the URL contract.
     */
    public function testTheTestResultLinkForAHostKeyOpensTheCredentialRow(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/credentials_test_message.php');
        self::assertStringContainsString('credential_host_identity_url($credentialId)', $source);
        self::assertStringContainsString('int $credentialId = 0', $source);
    }
}
