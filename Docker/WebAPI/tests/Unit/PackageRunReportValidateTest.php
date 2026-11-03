<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/package_run_report.php';

final class PackageRunReportValidateTest extends TestCase
{
    private static array $fixture;

    public static function setUpBeforeClass(): void
    {
        $raw = file_get_contents(__DIR__ . '/../fixtures/package-report-v1.json');
        self::assertIsString($raw);
        self::$fixture = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    }

    public static function validationCases(): iterable
    {
        $raw = file_get_contents(__DIR__ . '/../fixtures/package-report-v1.json');
        if (!is_string($raw)) {
            throw new RuntimeException('Package report fixture is unreadable.');
        }
        $fixture = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        foreach ($fixture['validation_cases'] as $case) {
            yield $case['name'] => [$fixture['base'], $case['patch'], $case['remove'] ?? [], $case['expect']];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('validationCases')]
    public function testV1Fixture(array $base, array $patch, array $remove, array $expect): void
    {
        $request = array_replace($base, $patch);
        foreach ($remove as $field) {
            unset($request[$field]);
        }
        $result = package_run_report_validate($request);
        if ($expect['status'] === 200) {
            self::assertArrayHasKey('report', $result);
            self::assertSame($expect['event'], $result['report']['event']);
            if (isset($expect['mac_candidates'])) {
                self::assertSame($expect['mac_candidates'], $result['report']['mac_candidates']);
            }
            return;
        }

        self::assertSame($expect['status'], $result['status']);
        self::assertSame($expect['error'], $result['error']);
    }

    public function testFixturePinsStatefulResponseAndDatabaseCasesForTheRepositoryPackage(): void
    {
        self::assertCount(5, self::$fixture['state_sequences']);
        $names = array_column(self::$fixture['state_sequences'], 'name');
        self::assertCount(count($names), array_unique($names));

        $statuses = [];
        $errors = [];
        foreach (self::$fixture['state_sequences'] as $sequence) {
            self::assertNotEmpty($sequence['requests']);
            self::assertCount(count($sequence['requests']), $sequence['responses']);
            self::assertNotEmpty($sequence['db']);
            foreach ($sequence['responses'] as $response) {
                $statuses[] = $response['status'];
                if (isset($response['error'])) {
                    $errors[] = $response['error'];
                }
            }
        }

        self::assertContains(200, $statuses);
        self::assertContains(409, $statuses);
        self::assertContains(410, $statuses);
        self::assertContains(422, $statuses);
        self::assertContains('detail_limit', $errors);
        self::assertContains('report_expired', $errors);
        self::assertContains('acceptance_generation_mismatch', $errors);
    }

    public function testBoundsReserveNormalDetailsPlusFirstFailureAndCompletion(): void
    {
        self::assertSame(256, VIRTUSPHERE_PACKAGE_REPORT_NORMAL_DETAIL_LIMIT);
        self::assertSame(65536, VIRTUSPHERE_PACKAGE_REPORT_MAX_BODY_BYTES);
        self::assertSame(90, VIRTUSPHERE_PACKAGE_REPORT_RETENTION_DAYS);
    }

    public function testCompletionNormalizesOmittedOptionalFirstFailureFields(): void
    {
        $request = array_replace(self::$fixture['base'], [
            'event' => 'completed',
            'event_seq' => 3,
            'event_at' => '2026-09-21T12:00:02Z',
            'wrapper_result' => 'failed',
            'wrapper_exit_code' => 1,
            'detection_result' => 'not_attempted',
            'processed_count' => 1,
            'ok_count' => 0,
            'skip_count' => 0,
            'fail_count' => 1,
            'last_processed_index' => 1,
            'first_failure' => ['step_index' => 1, 'script_name' => '001.ps1'],
            'payload_omitted_count' => 0,
            'wrapper_log_path' => null,
            'reporting_log_path' => null,
        ]);

        $result = package_run_report_validate($request);

        self::assertSame([
            'step_index' => 1,
            'script_name' => '001.ps1',
            'error_category' => null,
            'child_exit_code' => null,
            'detail_path' => null,
        ], $result['report']['first_failure']);
    }
}
