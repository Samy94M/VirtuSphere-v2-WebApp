<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/deploy_create_history_review.php';

final class DeployCreateHistoryReviewTest extends TestCase
{
    public function testInventorySqlIsReadOnly(): void
    {
        $sql = deploy_create_history_review_sql();
        self::assertStringStartsWith('SELECT', ltrim($sql));
        self::assertDoesNotMatchRegularExpression('/\b(?:INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|TRUNCATE)\b/i', $sql);
    }

    public function testKnownFalseClassificationShapesBecomeSuspectsOnly(): void
    {
        self::assertSame(
            'possible_created_recorded_failed',
            deploy_create_history_review_classify([
                'status' => 'failed',
                'error_code' => 'identity_result_invalid',
                'existed_before' => 0,
            ])
        );
        self::assertSame(
            'possible_module_failure_recorded_unchanged',
            deploy_create_history_review_classify([
                'status' => 'succeeded',
                'outcome' => 'unchanged',
                'changed' => 0,
                'existed_before' => 1,
            ])
        );
    }

    public function testOrdinaryAndUncertainRowsAreNotReclassified(): void
    {
        self::assertNull(deploy_create_history_review_classify([
            'status' => 'succeeded',
            'outcome' => 'created',
            'changed' => 1,
            'existed_before' => 0,
        ]));
        self::assertNull(deploy_create_history_review_classify([
            'status' => 'uncertain',
            'error_code' => 'async_state_missing',
            'existed_before' => 0,
        ]));
    }

    public function testReportRowsKeepEvidenceAndDoNotInventAVerdict(): void
    {
        $row = deploy_create_history_review_present([
            'job_id' => '41',
            'position' => '2',
            'status' => 'failed',
            'error_code' => 'identity_result_invalid',
            'existed_before' => '0',
            'precheck_moid' => null,
            'precheck_instance_uuid' => null,
            'vm_moid' => null,
            'vm_instance_uuid' => null,
            'remote_execution_id' => '88',
            'controller_state' => 'terminal',
            'effect_state' => 'unknown',
            'result_sha256' => null,
        ]);

        self::assertSame('suspect_only', $row['assessment']);
        self::assertSame('possible_created_recorded_failed', $row['risk_class']);
        self::assertSame(41, $row['job_id']);
        self::assertSame(2, $row['position']);
        self::assertFalse($row['prepare_evidence']['existed_before']);
        self::assertSame(88, $row['remote_evidence']['remote_execution_id']);
        self::assertArrayNotHasKey('recommended_status', $row);
    }
}
