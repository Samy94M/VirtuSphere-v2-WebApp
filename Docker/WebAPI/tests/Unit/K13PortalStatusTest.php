<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/layout.php';
require_once dirname(__DIR__, 2) . '/lib/settings_page.php';
require_once dirname(__DIR__, 2) . '/lib/help_page.php';
require_once dirname(__DIR__, 2) . '/lib/forms.php';

final class K13PortalStatusTest extends TestCase
{
    public function testCertificateCardWarnsAboutPinnedConsumersBeforeOverwrite(): void
    {
        Lang::load('en');
        $httpsEnabled = $httpsListenerLive = $httpsRedirectEnabled = $httpsHstsEnabled = false;
        $httpsPort = '8443';
        $httpsMeta = ['subject' => 'qa', 'sans' => '', 'issuer' => 'qa', 'valid_from' => 1, 'valid_to' => 2,
            'days_remaining' => 100, 'fingerprint' => 'SHA256', 'thumbprint' => 'TESTSHA1'];
        ob_start();
        require dirname(__DIR__, 2) . '/lib/settings/https_panel.php';
        $html = (string) ob_get_clean();
        self::assertStringContainsString('pinned MECM servers and client bundles', $html);
        self::assertStringContainsString('-CertThumbprint TESTSHA1', $html);
        self::assertMatchesRegularExpression('/data-confirm="[^"]*thumbprint changes/', $html);
    }

    public function testDeploymentProvidesTheFullTestEvidenceAtTheCredentialChoice(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/deploy_queue_panel.php');
        self::assertStringContainsString('ansibleTestSnapshot', $source);
        self::assertStringContainsString('last_checked_at', $source);
        self::assertStringContainsString('ansible_test_now', $source);
    }

    public function testHttpRequestGetsAPlaintextWarning(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/portal/system_status.php');
        self::assertStringContainsString("!virtusphere_is_request_secure()", $source);
        self::assertStringContainsString("__t('system_status.http_plaintext')", $source);
    }
}
