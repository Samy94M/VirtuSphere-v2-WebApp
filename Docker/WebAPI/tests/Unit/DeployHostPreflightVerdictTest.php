<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/layout_response.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_worker_host_preflight.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_terminal_presenter.php';

/**
 * K2 / DF-L8: the allowlist verdict of the host preflight is evaluated, not
 * only logged, and the stored block names its fix on the job page.
 */
final class DeployHostPreflightVerdictTest extends TestCase
{
    protected function tearDown(): void
    {
        Lang::load(Lang::DEFAULT_LOCALE);
    }

    public function testDeniedVerdictBlocksEveryModeThatEndsInAMacImport(): void
    {
        foreach ([VIRTUSPHERE_DEPLOY_MODE_FULL, 'powercycle', 'export'] as $mode) {
            try {
                deploy_worker_host_preflight_verdict($mode, 0, $this->allowlist('denied 10.0.0.7'));
                self::fail($mode . ' passed a denied allowlist');
            } catch (DeployWorkerConfigurationBlocked $blocked) {
                self::assertSame(deploy_host_preflight_allowlist_result('10.0.0.7'), $blocked->result);
                self::assertStringContainsString('10.0.0.7', $blocked->getMessage());
            }
        }
    }

    public function testModesWithoutAMacImportIgnoreTheVerdict(): void
    {
        foreach (['create', 'start', VIRTUSPHERE_DEPLOY_MODE_AUTOSTART] as $mode) {
            deploy_worker_host_preflight_verdict($mode, 0, $this->allowlist('denied 10.0.0.7'));
        }
        $this->addToAssertionCount(1);
    }

    public function testOkUnknownAndAbsentVerdictsDoNotBlock(): void
    {
        deploy_worker_host_preflight_verdict(VIRTUSPHERE_DEPLOY_MODE_FULL, 0, $this->allowlist('ok'));
        deploy_worker_host_preflight_verdict(VIRTUSPHERE_DEPLOY_MODE_FULL, 0, $this->allowlist('unknown'));
        deploy_worker_host_preflight_verdict(VIRTUSPHERE_DEPLOY_MODE_FULL, 0, '');
        // The last line wins, exactly as ansible_preflight_allowlist_verdict() reads it.
        deploy_worker_host_preflight_verdict(VIRTUSPHERE_DEPLOY_MODE_FULL, 0, $this->allowlist('denied 10.0.0.7') . $this->allowlist('ok'));
        $this->addToAssertionCount(1);
    }

    public function testNonZeroExitStaysAnExecutionFailureNamingTheComponent(): void
    {
        try {
            deploy_worker_host_preflight_verdict(VIRTUSPHERE_DEPLOY_MODE_FULL, 1, VIRTUSPHERE_ANSIBLE_PREFLIGHT_MARKER . " python3\n");
            self::fail('a broken host component passed');
        } catch (DeployWorkerConfigurationBlocked) {
            self::fail('a broken component is an execution failure, not a configuration block');
        } catch (RuntimeException $failure) {
            self::assertSame('Ansible host preflight failed with exit code 1. (failed at: python3)', $failure->getMessage());
        }
    }

    public function testStoredResultIsStrictlyDecoded(): void
    {
        $json = json_encode(deploy_host_preflight_allowlist_result('10.0.0.7'), JSON_THROW_ON_ERROR);
        self::assertSame(['blocker' => VIRTUSPHERE_DEPLOY_HOST_PREFLIGHT_ALLOWLIST_DENIED, 'ip' => '10.0.0.7'], deploy_host_preflight_decode_result($json));
        self::assertSame('', deploy_host_preflight_allowlist_result('not-an-ip')['ip']);

        $tampered = deploy_host_preflight_allowlist_result('10.0.0.7');
        $tampered['ip'] = '<script>';
        self::assertNull(deploy_host_preflight_decode_result(json_encode($tampered, JSON_THROW_ON_ERROR)));
        $extra = deploy_host_preflight_allowlist_result('10.0.0.7') + ['more' => 1];
        self::assertNull(deploy_host_preflight_decode_result(json_encode($extra, JSON_THROW_ON_ERROR)));
        self::assertNull(deploy_host_preflight_decode_result(null));
    }

    public function testTheTerminalWriteEncodesTheResultSoItsDecoderAcceptsIt(): void
    {
        // The worker stores the block through the same encoder as the network
        // preflight, which bounds row lists; a bounded host result was rejected
        // by its strict decoder and the job page lost its link.
        $result = deploy_host_preflight_allowlist_result('10.0.0.7');
        self::assertSame(
            ['blocker' => VIRTUSPHERE_DEPLOY_HOST_PREFLIGHT_ALLOWLIST_DENIED, 'ip' => '10.0.0.7'],
            deploy_host_preflight_decode_result(repo_encode_deploy_preflight_result($result))
        );
        $network = vm_network_preflight_result_contract(VIRTUSPHERE_DEPLOY_MODE_FULL, [], [], [18]);
        self::assertNotNull(vm_network_preflight_decode_result(repo_encode_deploy_preflight_result($network)), 'the network result keeps its bounded encoding');
    }

    public function testJobPageExplainsTheBlockAndLinksTheAllowlistOnlyForConfigurators(): void
    {
        Lang::load('en');
        $job = [
            'id' => 7,
            'mission_id' => 3,
            'status' => VIRTUSPHERE_DEPLOY_STATUS_FAILED,
            'result_json' => json_encode(deploy_host_preflight_allowlist_result('10.0.0.7'), JSON_THROW_ON_ERROR),
            'terminal_reason_code' => VIRTUSPHERE_DEPLOY_TERMINAL_REASON_CONFIGURATION_BLOCKED,
        ];
        $view = deploy_terminal_presenter($job);
        self::assertSame(__t('deploy.result_allowlist_denied', ['ip' => '10.0.0.7']), $view['result']['text']);
        self::assertTrue($view['result']['structured']);

        $link = 'href="' . htmlspecialchars(settings_url(VIRTUSPHERE_SETTINGS_TAB_MACHINE_API), ENT_QUOTES) . '"';
        self::assertStringContainsString($link, deploy_terminal_blocks_html($job, null, [], true));
        self::assertStringNotContainsString($link, deploy_terminal_blocks_html($job, null, [], false));
    }

    public function testHostPreflightRunsBeforeTheDeployingMarkAndTheFailurePathKnowsIt(): void
    {
        $mission = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/deploy_worker_mission.php');
        $preflight = strpos($mission, 'deploy_worker_run_host_preflight(');
        $mark = strpos($mission, '$priorLifecycles = deploy_worker_mark_vms_deploying(');
        self::assertIsInt($preflight);
        self::assertIsInt($mark);
        self::assertLessThan($mark, $preflight, 'MR-02: the host preflight must run before any VM is marked deploying');
        self::assertMatchesRegularExpression('/mark_vms_deploying\([^;]+;\s*\$vmsMarked = true;/', $mission);
        self::assertMatchesRegularExpression('/deploy_worker_handle_failure\([^;]*\$vmsMarked\s*\);/s', $mission);
    }

    private function allowlist(string $verdict): string
    {
        return VIRTUSPHERE_ANSIBLE_ALLOWLIST_MARKER . ' ' . $verdict . "\n";
    }
}
