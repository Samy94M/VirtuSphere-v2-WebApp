<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/layout_response.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_terminal_presenter.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_log_outcome.php';

final class DeployTerminalPresenterTest extends TestCase
{
    protected function tearDown(): void
    {
        Lang::load(Lang::DEFAULT_LOCALE);
    }

    public function testReasonRegistryAcceptsOnlyDeclaredStatusPairs(): void
    {
        foreach (VIRTUSPHERE_DEPLOY_TERMINAL_REASON_STATUSES as $code => $statuses) {
            self::assertNotEmpty($statuses, $code . ' has no status');
            foreach ($statuses as $status) {
                deploy_terminal_reason_assert($status, $code);
            }
        }

        $this->expectException(InvalidArgumentException::class);
        deploy_terminal_reason_assert(VIRTUSPHERE_DEPLOY_STATUS_CANCELLED, VIRTUSPHERE_DEPLOY_TERMINAL_REASON_EXECUTION_FAILED);
    }

    public function testTerminalReasonDetailIsBoundedAtTheSsoT(): void
    {
        $bounded = deploy_terminal_reason_detail(str_repeat('ä', VIRTUSPHERE_DEPLOY_TERMINAL_REASON_DETAIL_MAX_LENGTH + 10));

        self::assertNotNull($bounded);
        self::assertSame(VIRTUSPHERE_DEPLOY_TERMINAL_REASON_DETAIL_MAX_LENGTH, mb_strlen($bounded, 'UTF-8'));
        self::assertNull(deploy_terminal_reason_detail('  '));
    }

    public function testLegacyCancelledSuppressesOldLastErrorAndNamesDeletedActor(): void
    {
        Lang::load('de');
        $view = deploy_terminal_presenter([
            'status' => VIRTUSPHERE_DEPLOY_STATUS_CANCELLED,
            'last_error' => 'Cancelled by user id 42',
            'cancel_requested_by' => 42,
            'cancel_requested_by_name' => null,
            'cancel_requested_at' => '2026-08-25 10:00:00',
            'cancelled_at' => '2026-08-25 10:01:00',
        ]);

        self::assertNull($view['last_error']);
        self::assertSame('Benutzer #42 (gelöscht)', $view['cancel']['actor']);
        self::assertStringContainsString('kein strukturierter Abschlussgrund', $view['reason']['text']);
    }

    public function testFailedLegacyJobKeepsItsFallback(): void
    {
        $view = deploy_terminal_presenter([
            'status' => VIRTUSPHERE_DEPLOY_STATUS_FAILED,
            'last_error' => 'redacted failure',
        ]);

        self::assertSame('redacted failure', $view['last_error']);
    }

    /**
     * FC2-05: a failed step keeps its last Ansible failure line as the reason
     * detail, and the job view links to that place in the log through the
     * shared filter URL instead of leaving the reader to scroll for it.
     */
    public function testAQuotedAnsibleFailureLinksToItsPlaceInTheLog(): void
    {
        $job = [
            'id' => 42,
            'status' => VIRTUSPHERE_DEPLOY_STATUS_FAILED,
            'terminal_reason_code' => VIRTUSPHERE_DEPLOY_TERMINAL_REASON_EXECUTION_FAILED,
            'terminal_reason_detail' => 'TASK [Autostart je VM schreiben] failed: [localhost] (item=VM-03) => {"changed": false, "msg": "Insufficient permissions"}',
            'last_error' => 'Ansible command failed with exit code 2 (playbook step: autostartVMs-ESXi_playbook.yml).',
        ];

        $html = deploy_terminal_blocks_html($job);

        // The English detail itself lives in the technical details (E2, 29.10.2026).
        self::assertStringNotContainsString('Insufficient permissions', $html);
        self::assertStringContainsString('Insufficient permissions', deploy_log_technical_details_html($job, [], false));
        self::assertStringContainsString(
            deploy_terminal_h('deploy_log.php?' . http_build_query(['id' => '42', 'q' => 'failed: [localhost] (item=VM-03)', 'source' => VIRTUSPHERE_DEPLOY_LOG_ANSIBLE])),
            $html
        );

        $plain = deploy_terminal_blocks_html(['terminal_reason_detail' => 'Remote command produced no output for 1800 seconds (idle timeout).'] + $job);
        self::assertStringNotContainsString('q=', $plain, 'a detail without an Ansible failure line has nothing to jump to');
    }

    public function testPartialResultLivesInResultBlockAndNeverInLastError(): void
    {
        $result = json_encode([
            'version' => VIRTUSPHERE_MAC_IMPORT_LEGACY_RESULT_VERSION,
            'kind' => VIRTUSPHERE_MAC_IMPORT_RESULT_KIND,
            'outcome' => 'partial',
            'successful_vm_ids' => [1, 2],
            'failed_vm_ids' => [3],
            'counts' => ['successful_vms' => 2, 'failed_vms' => 1],
        ], JSON_THROW_ON_ERROR);
        $view = deploy_terminal_presenter([
            'status' => VIRTUSPHERE_DEPLOY_STATUS_PARTIAL,
            'result_json' => $result,
            'last_error' => 'old partial summary',
            'terminal_reason_code' => VIRTUSPHERE_DEPLOY_TERMINAL_REASON_PARTIAL_RESULT,
        ]);

        self::assertStringContainsString('2', $view['result']['text']);
        self::assertStringContainsString('1', $view['result']['text']);
        self::assertNull($view['last_error']);
    }

    public function testSkippedUncreatedVmCountIsLocalizedAndCreateLinkRequiresDeployPermission(): void
    {
        $job = [
            'id' => 42,
            'mission_id' => 9,
            'status' => VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED,
            'payload_json' => json_encode(['mode' => 'start', 'vm_ids' => [7], 'skipped_vm_ids' => [8]], JSON_THROW_ON_ERROR),
        ];

        Lang::load('de');
        $view = deploy_terminal_presenter($job);
        self::assertSame(1, $view['result']['skipped_count']);
        $allowedHtml = deploy_terminal_blocks_html($job, null, null, false, true);
        self::assertStringContainsString('1 VM nicht angelegt, übersprungen.', $allowedHtml);
        self::assertStringContainsString('deploy.php?mission_id=9&amp;mode=create#deploy-queue-form', $allowedHtml);
        $deniedHtml = deploy_terminal_blocks_html($job, null, null, false, false);
        self::assertStringNotContainsString('deploy.php?mission_id=9&amp;mode=create#deploy-queue-form', $deniedHtml);

        Lang::load('en');
        self::assertStringContainsString('1 VM not created, skipped.', deploy_terminal_blocks_html($job, null, null, false, true));
    }

    public function testGenericSuccessResultIsVersionedAndExistingMacResultWins(): void
    {
        $generic = deploy_job_terminal_result_json(null, VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED);
        self::assertSame(
            ['outcome' => VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED],
            deploy_job_decode_terminal_result($generic)
        );
        self::assertNull(deploy_job_terminal_result_json(null, VIRTUSPHERE_DEPLOY_STATUS_FAILED));

        $mac = '{"version":1,"kind":"mac_import","outcome":"success"}';
        self::assertSame($mac, deploy_job_terminal_result_json($mac, VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED));
    }

    /**
     * 15.10.2026: a create job failed on the host, and its result block said
     * "the current portal network configuration is now unambiguous". That
     * sentence answers a network failure; under any other one it reads like a
     * cause that was fixed. It appears only when the job's own result is about
     * the network.
     */
    public function testTheNetworkReadySentenceAppearsOnlyAboveANetworkResult(): void
    {
        Lang::load('en');
        $ready = ['network_ready' => true, 'findings' => []];
        $sentence = __t('deploy.retry_network_ready_now');

        $createFailure = deploy_terminal_blocks_html([
            'id' => 1180,
            'mission_id' => 5,
            'status' => VIRTUSPHERE_DEPLOY_STATUS_FAILED,
            'terminal_reason_code' => VIRTUSPHERE_DEPLOY_TERMINAL_REASON_EXECUTION_FAILED,
        ], $ready);
        self::assertStringNotContainsString($sentence, $createFailure);

        $network = vm_network_preflight_result_contract(
            VIRTUSPHERE_DEPLOY_MODE_FULL,
            [['id' => 17, 'vm_name' => 'APP-17']],
            [['code' => VIRTUSPHERE_VM_NETWORK_AMBIGUOUS, 'vm_id' => 17, 'vlan' => 'WDS', 'interface_ids' => [41, 42]]]
        );
        $networkFailure = deploy_terminal_blocks_html([
            'id' => 99,
            'mission_id' => 5,
            'status' => VIRTUSPHERE_DEPLOY_STATUS_FAILED,
            'result_json' => json_encode($network, JSON_THROW_ON_ERROR),
            'terminal_reason_code' => VIRTUSPHERE_DEPLOY_TERMINAL_REASON_CONFIGURATION_BLOCKED,
        ], $ready, []);
        self::assertStringContainsString(htmlspecialchars($sentence, ENT_QUOTES), $networkFailure);
    }

    public function testNetworkPreflightResultIsExhaustivelyDecodedAndPresented(): void
    {
        Lang::load('en');
        $result = vm_network_preflight_result_contract(
            VIRTUSPHERE_DEPLOY_MODE_FULL,
            [['id' => 17, 'vm_name' => 'APP-17']],
            [['code' => VIRTUSPHERE_VM_NETWORK_AMBIGUOUS, 'vm_id' => 17, 'vlan' => 'WDS', 'interface_ids' => [41, 42]]]
        );
        $view = deploy_terminal_presenter([
            'id' => 99,
            'mission_id' => 5,
            'status' => VIRTUSPHERE_DEPLOY_STATUS_FAILED,
            'result_json' => json_encode($result, JSON_THROW_ON_ERROR),
            'terminal_reason_code' => VIRTUSPHERE_DEPLOY_TERMINAL_REASON_CONFIGURATION_BLOCKED,
        ], []);

        self::assertTrue($view['result']['structured']);
        self::assertStringContainsString('1 of 1', $view['result']['text']);
        self::assertSame([], $result['missing_vm_ids']);
        self::assertSame(VIRTUSPHERE_VM_NETWORK_AMBIGUOUS, $view['result']['network_rows'][0]['issues'][0]['code']);
    }

    /**
     * A shortened stored result must say so. Without the note the table reads
     * as the complete list while the sentence above it counts every blocked VM,
     * and the two silently disagree by exactly the rows nobody can see.
     */
    public function testATruncatedPreflightResultSaysWhatItDoesNotList(): void
    {
        Lang::load('en');
        $vms = [];
        $blockers = [];
        for ($index = 0; $index < VIRTUSPHERE_DEPLOY_PREFLIGHT_DETAIL_LIMIT + 3; $index++) {
            $vmId = 7000 + $index;
            $vms[] = ['id' => $vmId, 'vm_name' => sprintf('APP-%04d', $index)];
            $blockers[] = ['code' => VIRTUSPHERE_VM_NETWORK_EMPTY, 'vm_id' => $vmId, 'interface_ids' => [$vmId]];
        }
        $result = vm_network_preflight_result_contract(VIRTUSPHERE_DEPLOY_MODE_FULL, $vms, $blockers);
        $json = json_encode($result, JSON_THROW_ON_ERROR);

        $view = deploy_terminal_presenter([
            'id' => 99,
            'mission_id' => 5,
            'status' => VIRTUSPHERE_DEPLOY_STATUS_FAILED,
            'result_json' => $json,
            'terminal_reason_code' => VIRTUSPHERE_DEPLOY_TERMINAL_REASON_CONFIGURATION_BLOCKED,
        ], []);

        self::assertSame(3, $view['result']['network_omitted']);
        self::assertCount(VIRTUSPHERE_DEPLOY_PREFLIGHT_DETAIL_LIMIT, $view['result']['network_rows']);
        self::assertStringContainsString(
            (string) count($vms) . ' of ' . count($vms),
            $view['result']['text'],
            'the sentence keeps counting the complete decision'
        );
        $html = deploy_terminal_blocks_html([
            'id' => 99,
            'mission_id' => 5,
            'status' => VIRTUSPHERE_DEPLOY_STATUS_FAILED,
            'result_json' => $json,
            'terminal_reason_code' => VIRTUSPHERE_DEPLOY_TERMINAL_REASON_CONFIGURATION_BLOCKED,
        ], null, []);
        self::assertStringContainsString(htmlspecialchars(__t('deploy.blocker_omitted', ['count' => 3]), ENT_QUOTES), $html);
    }

    public function testMissingPreflightScopeIsStrictlyDecodedAndPresentedWithoutAnIssueCode(): void
    {
        Lang::load('en');
        $result = vm_network_preflight_result_contract(VIRTUSPHERE_DEPLOY_MODE_FULL, [], [], [18]);
        $json = json_encode($result, JSON_THROW_ON_ERROR);
        self::assertSame($result, vm_network_preflight_decode_result($json));

        $view = deploy_terminal_presenter([
            'mission_id' => 5,
            'status' => VIRTUSPHERE_DEPLOY_STATUS_FAILED,
            'result_json' => $json,
            'terminal_reason_code' => VIRTUSPHERE_DEPLOY_TERMINAL_REASON_CONFIGURATION_BLOCKED,
        ]);
        self::assertStringContainsString('1 of 1', $view['result']['text']);
        self::assertSame(18, $view['result']['network_rows'][0]['vm_id']);
        self::assertSame('', $view['result']['network_rows'][0]['issues'][0]['code']);

        $invalid = $result;
        $invalid['missing_vm_ids'] = ['18'];
        self::assertNull(vm_network_preflight_decode_result(json_encode($invalid, JSON_THROW_ON_ERROR)));
        $invalid = $result;
        $invalid['missing_vm_ids'] = [19, 18];
        $invalid['counts']['missing_vms'] = 2;
        $invalid['counts']['blocked_vms'] = 2;
        $invalid['counts']['expected_vms'] = 2;
        self::assertNull(vm_network_preflight_decode_result(json_encode($invalid, JSON_THROW_ON_ERROR)));
        $invalid = $result;
        $invalid['counts']['blocked_vms'] = 0;
        self::assertNull(vm_network_preflight_decode_result(json_encode($invalid, JSON_THROW_ON_ERROR)));
    }

    public function testBothLocalesCoverEveryReasonWithoutTranslatingTheCode(): void
    {
        foreach (Lang::LOCALES as $locale) {
            Lang::load($locale);
            foreach (array_keys(VIRTUSPHERE_DEPLOY_TERMINAL_REASON_STATUSES) as $code) {
                self::assertNotSame('deploy.terminal_reason_' . $code, __t('deploy.terminal_reason_' . $code));
            }
            $job = [
                'status' => VIRTUSPHERE_DEPLOY_STATUS_FAILED,
                'terminal_reason_code' => VIRTUSPHERE_DEPLOY_TERMINAL_REASON_TIMEOUT,
            ];
            // The visible sentence is translated; the stored code stays as it is,
            // in the technical details (E2, 29.10.2026).
            self::assertStringContainsString(h(__t('deploy.terminal_reason_timeout')), deploy_terminal_blocks_html($job));
            self::assertStringContainsString('<code>timeout</code>', deploy_log_technical_details_html($job, [], false));
        }
    }
}
