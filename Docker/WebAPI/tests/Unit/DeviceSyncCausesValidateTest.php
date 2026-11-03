<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/device_sync_causes.php';
require_once __DIR__ . '/../../lib/repo/vm_sync_causes.php';

/**
 * reportDeviceCauses V1 (MX1, FM-05): closed schema, strict types, bounds and
 * the per-entry admission rule of the writer. Bodies are decoded from JSON text,
 * so a numeric string or a float arrives exactly as the wire would deliver it.
 */
final class DeviceSyncCausesValidateTest extends TestCase
{
    private const RUN = '0123456789abcdef0123456789abcdef';

    public function testAValidReportIsNormalizedUnchanged(): void
    {
        $result = device_sync_causes_validate($this->decode(
            '{"schema_version":1,"run_id":"' . self::RUN . '","complete":true,"causes":['
            . '{"vm_id":7,"rollout_revision":3,"code":"mac_missing"},'
            . '{"code":"resource_update_failed","vm_id":2147483647,"rollout_revision":1}]}'
        ));

        self::assertSame(['report' => [
            'schema_version' => 1,
            'run_id' => self::RUN,
            'complete' => true,
            'causes' => [
                ['vm_id' => 7, 'rollout_revision' => 3, 'code' => 'mac_missing'],
                ['vm_id' => 2147483647, 'rollout_revision' => 1, 'code' => 'resource_update_failed'],
            ],
        ]], $result);
    }

    public function testAnEmptyCompleteReportIsValid(): void
    {
        $result = device_sync_causes_validate($this->decode('{"schema_version":1,"run_id":"' . self::RUN . '","complete":true,"causes":[]}'));

        self::assertSame([], $result['report']['causes'] ?? null);
    }

    /** @return iterable<string,array{0:string,1:string}> */
    public static function refusals(): iterable
    {
        $run = '"run_id":"' . self::RUN . '"';
        $entry = '{"vm_id":7,"rollout_revision":3,"code":"mac_missing"}';
        yield 'unknown top-level field' => ['{"schema_version":1,' . $run . ',"complete":true,"causes":[],"v":1}', 'unknown_field'];
        yield 'missing complete' => ['{"schema_version":1,' . $run . ',"causes":[]}', 'missing_field'];
        yield 'missing causes' => ['{"schema_version":1,' . $run . ',"complete":true}', 'missing_field'];
        yield 'list body' => ['[1,2,3]', 'unknown_field'];
        yield 'schema 2' => ['{"schema_version":2,' . $run . ',"complete":true,"causes":[]}', 'unsupported_schema'];
        yield 'schema as string' => ['{"schema_version":"1",' . $run . ',"complete":true,"causes":[]}', 'unsupported_schema'];
        yield 'schema as float' => ['{"schema_version":1.0,' . $run . ',"complete":true,"causes":[]}', 'unsupported_schema'];
        yield 'run id upper case' => ['{"schema_version":1,"run_id":"' . strtoupper(self::RUN) . '","complete":true,"causes":[]}', 'invalid_run_id'];
        yield 'run id short' => ['{"schema_version":1,"run_id":"abc","complete":true,"causes":[]}', 'invalid_run_id'];
        yield 'run id trailing newline' => ['{"schema_version":1,"run_id":"' . self::RUN . '\n","complete":true,"causes":[]}', 'invalid_run_id'];
        yield 'run id number' => ['{"schema_version":1,"run_id":123,"complete":true,"causes":[]}', 'invalid_run_id'];
        yield 'complete as int' => ['{"schema_version":1,' . $run . ',"complete":1,"causes":[]}', 'invalid_complete'];
        yield 'complete as string' => ['{"schema_version":1,' . $run . ',"complete":"true","causes":[]}', 'invalid_complete'];
        yield 'causes as object' => ['{"schema_version":1,' . $run . ',"complete":true,"causes":{"a":' . $entry . '}}', 'invalid_causes'];
        yield 'causes as null' => ['{"schema_version":1,' . $run . ',"complete":true,"causes":null}', 'invalid_causes'];
        yield 'entry as list' => ['{"schema_version":1,' . $run . ',"complete":true,"causes":[[7,3,"mac_missing"]]}', 'invalid_cause'];
        yield 'entry as empty object' => ['{"schema_version":1,' . $run . ',"complete":true,"causes":[{}]}', 'invalid_cause'];
        yield 'entry with extra field' => ['{"schema_version":1,' . $run . ',"complete":true,"causes":[{"vm_id":7,"rollout_revision":3,"code":"mac_missing","detail":"x"}]}', 'invalid_cause'];
        yield 'entry without code' => ['{"schema_version":1,' . $run . ',"complete":true,"causes":[{"vm_id":7,"rollout_revision":3}]}', 'invalid_cause'];
        yield 'vm id numeric string' => ['{"schema_version":1,' . $run . ',"complete":true,"causes":[{"vm_id":"7","rollout_revision":3,"code":"mac_missing"}]}', 'invalid_vm_id'];
        yield 'vm id zero' => ['{"schema_version":1,' . $run . ',"complete":true,"causes":[{"vm_id":0,"rollout_revision":3,"code":"mac_missing"}]}', 'invalid_vm_id'];
        yield 'vm id above INT' => ['{"schema_version":1,' . $run . ',"complete":true,"causes":[{"vm_id":2147483648,"rollout_revision":3,"code":"mac_missing"}]}', 'invalid_vm_id'];
        yield 'vm id float' => ['{"schema_version":1,' . $run . ',"complete":true,"causes":[{"vm_id":7.0,"rollout_revision":3,"code":"mac_missing"}]}', 'invalid_vm_id'];
        yield 'revision numeric string' => ['{"schema_version":1,' . $run . ',"complete":true,"causes":[{"vm_id":7,"rollout_revision":"3","code":"mac_missing"}]}', 'invalid_rollout_revision'];
        yield 'revision zero' => ['{"schema_version":1,' . $run . ',"complete":true,"causes":[{"vm_id":7,"rollout_revision":0,"code":"mac_missing"}]}', 'invalid_rollout_revision'];
        yield 'revision null' => ['{"schema_version":1,' . $run . ',"complete":true,"causes":[{"vm_id":7,"rollout_revision":null,"code":"mac_missing"}]}', 'invalid_rollout_revision'];
        yield 'revision beyond PHP int' => ['{"schema_version":1,' . $run . ',"complete":true,"causes":[{"vm_id":7,"rollout_revision":99999999999999999999,"code":"mac_missing"}]}', 'invalid_rollout_revision'];
        yield 'collection-only code' => ['{"schema_version":1,' . $run . ',"complete":true,"causes":[{"vm_id":7,"rollout_revision":3,"code":"collection_update_failed"}]}', 'unknown_cause_code'];
        yield 'package code' => ['{"schema_version":1,' . $run . ',"complete":true,"causes":[{"vm_id":7,"rollout_revision":3,"code":"package_content_group_empty"}]}', 'unknown_cause_code'];
        yield 'code in upper case' => ['{"schema_version":1,' . $run . ',"complete":true,"causes":[{"vm_id":7,"rollout_revision":3,"code":"MAC_MISSING"}]}', 'unknown_cause_code'];
        yield 'code as number' => ['{"schema_version":1,' . $run . ',"complete":true,"causes":[{"vm_id":7,"rollout_revision":3,"code":1}]}', 'unknown_cause_code'];
        yield 'same VM twice' => ['{"schema_version":1,' . $run . ',"complete":false,"causes":[' . $entry . ',{"vm_id":7,"rollout_revision":3,"code":"mac_conflict"}]}', 'duplicate_vm_id'];
    }

    #[DataProvider('refusals')]
    public function testRefusalsAre400WithAClosedToken(string $json, string $token): void
    {
        $result = device_sync_causes_validate($this->decode($json));

        self::assertSame(['error' => $token, 'status' => 400], $result);
        self::assertContains($token, VIRTUSPHERE_DEVICE_SYNC_CAUSE_ERRORS);
    }

    public function testTheEntryBoundIsExactlyFiveHundred(): void
    {
        $atBound = $this->report(VIRTUSPHERE_DEVICE_SYNC_CAUSE_MAX_ENTRIES);
        self::assertArrayHasKey('report', device_sync_causes_validate($atBound));

        $above = $this->report(VIRTUSPHERE_DEVICE_SYNC_CAUSE_MAX_ENTRIES + 1);
        self::assertSame(['error' => 'too_many_causes', 'status' => 400], device_sync_causes_validate($above));
    }

    /**
     * The worst case at the entry bound (ten-digit ids, the longest code, a
     * large revision) must fit the byte bound, or the 413 would refuse a report
     * the validator accepts (Q8: 500 entries in 64 KiB).
     */
    public function testTheWorstCaseReportFitsTheByteBound(): void
    {
        $longest = '';
        foreach (VIRTUSPHERE_DEVICE_SYNC_CAUSES as $code) {
            $longest = strlen($code) > strlen($longest) ? $code : $longest;
        }
        $causes = [];
        for ($i = 0; $i < VIRTUSPHERE_DEVICE_SYNC_CAUSE_MAX_ENTRIES; $i++) {
            $causes[] = ['vm_id' => VIRTUSPHERE_DEVICE_SYNC_CAUSE_VM_ID_MAX - $i, 'rollout_revision' => 9999999999, 'code' => $longest];
        }
        $body = json_encode(['schema_version' => 1, 'run_id' => self::RUN, 'complete' => true, 'causes' => $causes], JSON_THROW_ON_ERROR);

        self::assertLessThanOrEqual(VIRTUSPHERE_DEVICE_SYNC_CAUSE_MAX_BODY_BYTES, strlen($body));
        self::assertArrayHasKey('report', device_sync_causes_validate($this->decode($body)));
    }

    public function testTheCodeSetIsTheTwentyThreeDeviceCodesWithoutDuplicates(): void
    {
        self::assertCount(23, VIRTUSPHERE_DEVICE_SYNC_CAUSES);
        self::assertSame(VIRTUSPHERE_DEVICE_SYNC_CAUSES, array_values(array_unique(VIRTUSPHERE_DEVICE_SYNC_CAUSES)));
        self::assertNotContains('collection_update_failed', VIRTUSPHERE_DEVICE_SYNC_CAUSES, 'collection-level only');
        foreach (VIRTUSPHERE_DEVICE_SYNC_CAUSES as $code) {
            self::assertMatchesRegularExpression('/\A[a-z][a-z_]*[a-z]\z/', $code);
            self::assertDoesNotMatchRegularExpression('/\Apackage_/', $code, 'package codes belong to the autoimporter');
        }
    }

    /**
     * The writer's admission: the same pure revision function every MECM
     * callback uses (a newer revision is refused like an older one), plus the
     * getDeviceList predicate `updated = 1 OR pending`.
     */
    public function testAdmissionFollowsTheFenceAndTheDeviceListPredicate(): void
    {
        $vm = ['mecm_rollout_revision' => 4, 'mecm_previous_id' => null, 'updated' => 0, 'mecm_sync_state' => 'pending'];

        self::assertTrue(repo_vm_sync_causes_admissible($vm, 4));
        self::assertFalse(repo_vm_sync_causes_admissible($vm, 3), 'older revision');
        self::assertFalse(repo_vm_sync_causes_admissible($vm, 5), 'newer revision');
        self::assertTrue(repo_vm_sync_causes_admissible(['mecm_sync_state' => 'registered', 'updated' => 1] + $vm, 4), 'queued transfer');
        self::assertTrue(repo_vm_sync_causes_admissible(['mecm_sync_state' => 'deploy_failed', 'updated' => 1] + $vm, 4), 'stored for every listed VM (Q7)');
        self::assertFalse(repo_vm_sync_causes_admissible(['mecm_sync_state' => 'registered'] + $vm, 4), 'not on the device list');
        self::assertFalse(repo_vm_sync_causes_admissible(['mecm_sync_state' => 'not_ready'] + $vm, 4), 'not on the device list');
        self::assertTrue(repo_vm_sync_causes_admissible(['mecm_rollout_revision' => null] + $vm, 1), 'NULL reads as the initial revision');
        self::assertFalse(repo_vm_sync_causes_admissible(['mecm_rollout_revision' => null] + $vm, 2));
        self::assertTrue(repo_vm_sync_causes_admissible(['mecm_previous_id' => 'XYZ00001'] + $vm, 4), 'a tombstone does not matter for a reported revision');
    }

    /** @return array<mixed> */
    private function decode(string $json): array
    {
        $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    /** @return array<string,mixed> */
    private function report(int $entries): array
    {
        $causes = [];
        for ($i = 1; $i <= $entries; $i++) {
            $causes[] = ['vm_id' => $i, 'rollout_revision' => 1, 'code' => 'mac_missing'];
        }

        return ['schema_version' => 1, 'run_id' => self::RUN, 'complete' => true, 'causes' => $causes];
    }
}
