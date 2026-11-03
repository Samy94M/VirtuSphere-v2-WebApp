<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/deploy_create_retry_evidence.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_retry_confirmation.php';

final class DeployCreateRetryEvidenceTest extends TestCase
{
    public function testValidSummaryAcceptsScopeMembershipIndependentlyOfInputOrder(): void
    {
        [$job, $payload, $rows] = self::evidence();
        self::assertTrue(deploy_create_retry_summary_is_valid($job, $payload, $rows));
        $payload['mode'] = 'full';
        self::assertTrue(deploy_create_retry_summary_is_valid($job, $payload, $rows));
        $rows[1]['status'] = 'uncertain';
        self::assertTrue(deploy_create_retry_summary_is_valid($job, $payload, $rows), 'The result discriminator must leave uncertainty to its dedicated blocking gate.');
        self::assertTrue(deploy_create_retry_plan($rows)['blocked']);
    }

    public static function malformedSummaries(): array
    {
        return [
            'missing' => [null],
            'json invalid' => ['{'],
            'string version' => ['{"version":"1","kind":"deploy_job","outcome":"partial"}'],
            'float version' => ['{"version":1.0,"kind":"deploy_job","outcome":"partial"}'],
            'boolean version' => ['{"version":true,"kind":"deploy_job","outcome":"partial"}'],
            'future version' => ['{"version":2,"kind":"deploy_job","outcome":"partial"}'],
            'unknown kind' => ['{"version":1,"kind":"other","outcome":"partial"}'],
            'array kind' => ['{"version":1,"kind":[],"outcome":"partial"}'],
            'array outcome' => ['{"version":1,"kind":"deploy_job","outcome":[]}'],
            'unknown outcome' => ['{"version":1,"kind":"deploy_job","outcome":"failed"}'],
            'unknown field' => ['{"version":1,"kind":"deploy_job","outcome":"partial","vm_ids":[9]}'],
        ];
    }

    #[DataProvider('malformedSummaries')]
    public function testSummaryDecoderRejectsMalformedProtocol(?string $json): void
    {
        self::assertNull(deploy_job_decode_terminal_result($json));
        [$job, $payload, $rows] = self::evidence();
        $job['result_json'] = $json;
        self::assertFalse(deploy_create_retry_summary_is_valid($job, $payload, $rows));
    }

    public static function evidenceMutations(): array
    {
        return array_map(static fn (string $case): array => [$case], [
            'job status', 'job id', 'mode', 'empty scope', 'incomplete scope',
            'extra scope', 'missing rows', 'unknown status', 'unknown action',
            'foreign row', 'duplicate row', 'duplicate vm', 'missing vm',
            'position gap', 'total mismatch', 'missing uuid', 'wrong outcome',
            'invalid boolean', 'missing finish', 'unknown error', 'no successes',
            'all successful',
        ]);
    }

    #[DataProvider('evidenceMutations')]
    public function testSummaryCannotInventOrWidenItsCreateEvidence(string $case): void
    {
        [$job, $payload, $rows] = self::evidence();
        switch ($case) {
            case 'job status': $job['status'] = 'failed'; break;
            case 'job id': $job['id'] = 0; break;
            case 'mode': $payload['mode'] = 'export'; break;
            case 'empty scope': $payload['vm_ids'] = []; break;
            case 'incomplete scope': $payload['vm_ids'] = [11]; break;
            case 'extra scope': $payload['vm_ids'][] = 13; break;
            case 'missing rows': $rows = []; break;
            case 'unknown status': $rows[1]['status'] = 'invented'; break;
            case 'unknown action': $rows[1]['action'] = 'invented'; break;
            case 'foreign row': $rows[1]['job_id'] = 8; break;
            case 'duplicate row': $rows[1]['id'] = $rows[0]['id']; break;
            case 'duplicate vm': $rows[1]['vm_id'] = $rows[0]['vm_id']; break;
            case 'missing vm': $rows[1]['vm_id'] = null; break;
            case 'position gap': $rows[1]['position'] = 3; break;
            case 'total mismatch': $rows[0]['total'] = 3; break;
            case 'missing uuid': $rows[0]['vm_instance_uuid'] = null; break;
            case 'wrong outcome': $rows[0]['outcome'] = 'unchanged'; break;
            case 'invalid boolean': $rows[0]['changed'] = 2; break;
            case 'missing finish': $rows[0]['finished_at'] = null; break;
            case 'unknown error': $rows[1]['error_code'] = 'invented'; break;
            case 'no successes':
                $rows[0] = array_replace($rows[1], ['id' => 31, 'vm_id' => 11, 'position' => 1]);
                break;
            case 'all successful':
                $rows[1] = array_replace($rows[0], ['id' => 32, 'vm_id' => 12, 'position' => 2]);
                break;
        }
        self::assertFalse(deploy_create_retry_summary_is_valid($job, $payload, $rows));
    }

    public function testConfirmationDescribesTheEvaluatedCreateOrMacPlanInBothLocales(): void
    {
        try {
            foreach (['de', 'en'] as $locale) {
                Lang::load($locale);
                $create = ['plan' => ['scope' => 'create_units'], 'effective_mode' => 'full', 'scope_vm_ids' => [11, 12]];
                self::assertSame(__t('deploy.confirm_retry_create', ['name' => 'Mission A']), deploy_retry_confirmation($create, 'Mission A'));
                self::assertStringNotContainsString('Export', deploy_retry_confirmation($create, 'Mission A'));
                $mac = ['plan' => ['scope' => 'failed_vms'], 'effective_mode' => 'export', 'scope_vm_ids' => [12]];
                self::assertSame(__t('deploy.confirm_retry_partial_one', ['name' => 'Mission A']), deploy_retry_confirmation($mac, 'Mission A'));
                $mac['scope_vm_ids'] = [12, 13];
                self::assertSame(__t('deploy.confirm_retry_partial_many', ['name' => 'Mission A', 'count' => 2]), deploy_retry_confirmation($mac, 'Mission A'));
            }
        } finally {
            Lang::load(Lang::DEFAULT_LOCALE);
        }
    }

    private static function evidence(): array
    {
        $job = ['id' => 7, 'status' => 'partial', 'result_json' => deploy_job_terminal_result_json(null, 'partial')];
        $payload = ['mode' => 'create', 'vm_ids' => [12, 11]];
        $base = ['job_id' => 7, 'total' => 2, 'action' => 'create', 'vm_name' => 'Exact VM', 'finished_at' => '2026-09-09 10:00:00'];
        $rows = [
            $base + ['id' => 31, 'vm_id' => 11, 'position' => 1, 'status' => 'succeeded', 'outcome' => 'created', 'changed' => 1, 'existed_before' => 0, 'vm_moid' => 'vm-31', 'vm_instance_uuid' => 'uuid-31'],
            $base + ['id' => 32, 'vm_id' => 12, 'position' => 2, 'status' => 'failed', 'outcome' => null, 'error_code' => 'module_failed', 'error_detail' => 'Confirmed failure.'],
        ];
        return [$job, $payload, $rows];
    }
}
