<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/credentials_test_message.php';

/**
 * Which connection-test results carry a link, and where to.
 *
 * Two of the sentences credentials_test_message() returns end in an instruction
 * that names another page ("unter Einstellungen"), and the ESXi branch of the
 * same handler already shipped a link, so the page answered the same question
 * two different ways. Everything else stays link-free on purpose: a missing
 * pyvmomi is fixed on the Ansible host, and a link into the portal would point
 * at a page that cannot help.
 */
final class CredentialsTestActionTest extends TestCase
{
    /**
     * @param array<string, string|int> $context
     * @return array{ok: bool, code: string, detail: string, context: array<string, string|int>}
     */
    private function makeResult(bool $ok, string $code, array $context = []): array
    {
        return ['ok' => $ok, 'code' => $code, 'detail' => '', 'context' => $context];
    }

    public function testAllowlistWarningPointsAtTheMachineApiTab(): void
    {
        $action = credentials_test_action($this->makeResult(true, VIRTUSPHERE_CREDENTIAL_TEST_ALLOWLIST, ['ip' => '10.0.0.5']));
        self::assertNotNull($action);
        self::assertSame('settings.php#panel-machine-api', $action['url']);
        self::assertNotSame('', $action['label']);
    }

    public function testPortalProbeFailurePointsAtTheDeployTab(): void
    {
        $action = credentials_test_action($this->makeResult(false, VIRTUSPHERE_CREDENTIAL_TEST_PREFLIGHT, [
            'component' => VIRTUSPHERE_ANSIBLE_PREFLIGHT_PORTAL,
        ]));
        self::assertNotNull($action);
        self::assertSame('settings.php#panel-deploy', $action['url']);
        self::assertNotSame('', $action['label']);
    }

    /**
     * Both targets are tabs of settings.php, which falls back to its first tab
     * when the fragment is missing: a bare settings.php would open a panel that
     * does not hold the field the sentence just named.
     */
    public function testEverySettingsTargetNamesItsTab(): void
    {
        foreach ([
            $this->makeResult(true, VIRTUSPHERE_CREDENTIAL_TEST_ALLOWLIST),
            $this->makeResult(false, VIRTUSPHERE_CREDENTIAL_TEST_PREFLIGHT, ['component' => VIRTUSPHERE_ANSIBLE_PREFLIGHT_PORTAL]),
        ] as $result) {
            $action = credentials_test_action($result);
            self::assertNotNull($action);
            self::assertStringContainsString('#panel-', $action['url']);
            self::assertStringNotContainsString('credentials.', $action['label'], 'untranslated key');
        }
    }

    public function testResultsFixedOnTheAnsibleHostCarryNoLink(): void
    {
        self::assertNull(credentials_test_action($this->makeResult(true, VIRTUSPHERE_CREDENTIAL_TEST_OK)));
        self::assertNull(credentials_test_action($this->makeResult(false, VIRTUSPHERE_CREDENTIAL_TEST_SFTP)));
        self::assertNull(credentials_test_action($this->makeResult(false, VIRTUSPHERE_CREDENTIAL_TEST_PREFLIGHT, [
            'component' => 'pyvmomi',
        ])));
        self::assertNull(credentials_test_action($this->makeResult(false, VIRTUSPHERE_CREDENTIAL_TEST_PREFLIGHT)));
    }

    /**
     * The allowlist verdict rides an ok=true result. A failed test that happened
     * to carry the same code is not an allowlist warning and must not be sent to
     * the allowlist.
     */
    public function testAllowlistCodeOnAFailedResultCarriesNoLink(): void
    {
        self::assertNull(credentials_test_action($this->makeResult(false, VIRTUSPHERE_CREDENTIAL_TEST_ALLOWLIST)));
    }
}
