<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MacImportTransactionContractTest extends TestCase
{
    public function testJobConflictGatePrecedesTheSingleRawTransaction(): void
    {
        $source = $this->source('db_importMAC.php');
        $jobGate = strpos($source, 'mac_import_job($connection, $jobId)');
        $begin = strpos($source, '$connection->begin_transaction()');

        self::assertNotFalse($jobGate);
        self::assertNotFalse($begin);
        self::assertLessThan($begin, $jobGate, 'job/mission/status validation must happen before BEGIN');
        // ADR-0033: the callback window is running OR cancelling (the sequence
        // that produced the MACs still owns the job); only confirmed end
        // states refuse, and the recheck behind BEGIN uses the same window.
        self::assertStringContainsString('VIRTUSPHERE_DEPLOY_STATUS_CANCELLING], true)', $source);
        self::assertStringContainsString('status IN (?, ?) LIMIT 1 FOR UPDATE', $source);
        self::assertStringContainsString('MacImportConflictException', $source);
        self::assertStringContainsString("], 409)", $source);
        // The rejection leaves a job-log line and a throttled portal audit row
        // (raw prepared statement, after rollback, never able to 500).
        self::assertStringContainsString('Rejected a MAC callback:', $source);
        self::assertStringContainsString('machine_api_audit_warning(', $source);
    }

    public function testPlanningFinishesBeforeAnyPhaseTwoWrite(): void
    {
        $source = $this->source('db_importMAC.php');
        $plan = strpos($source, 'mac_import_build_plan(');
        $write = strpos($source, '$updateInterface->execute()');
        $resultWrite = strpos($source, 'UPDATE deploy_jobs SET result_json = ?');
        $commit = strpos($source, '$connection->commit()');

        self::assertNotFalse($plan);
        self::assertNotFalse($write);
        self::assertNotFalse($resultWrite);
        self::assertNotFalse($commit);
        self::assertLessThan($write, $plan);
        self::assertLessThan($commit, $resultWrite);
        self::assertStringContainsString('INSERT INTO deploy_vm_status_events', $source);
        self::assertStringNotContainsString('repo_set_vm_state(', $source);
        self::assertStringNotContainsString('repo_transaction($connection', $source);
    }

    public function testWireAndDurableResultContractsStayAdditiveAndBounded(): void
    {
        $endpoint = $this->source('db_importMAC.php');
        foreach (['success', 'legacy_payload', 'updated_interfaces', 'updated_vms', 'missing_vms', 'unmatched_interfaces', 'duplicate_macs'] as $field) {
            self::assertStringContainsString("'{$field}' =>", $endpoint, $field);
        }
        foreach (['result_version', 'outcome', 'job_id', 'vm_results', 'counts', 'errors'] as $field) {
            self::assertStringContainsString("'{$field}' =>", $endpoint, $field);
        }

        $planner = $this->source('lib/mac_import.php');
        foreach (['interface_not_found', 'duplicate_mac', 'invalid_mac', 'ambiguous_vlan', 'vm_not_in_mission', 'missing_name', 'missing_nic_data', 'esxi_query_failed'] as $code) {
            self::assertStringContainsString("'{$code}'", $planner, $code);
        }
        self::assertStringContainsString('mac_import_bounded_identifier', $planner);
        self::assertLessThanOrEqual(400, substr_count($planner, "\n") + 1, 'new PHP modules stay below the ADR-0006 warning threshold');
        self::assertLessThanOrEqual(400, substr_count($this->source('lib/mac_import_result.php'), "\n") + 1);
    }

    private function source(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
