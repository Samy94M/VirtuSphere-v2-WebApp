<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * LG-04 and LG-28: every audit row of the package catalog sync is a required row
 * inside the one transaction that writes the catalog. A row that cannot be stored
 * rolls the sync back; it is never swallowed while the sync answers success.
 *
 * Source-level pins: the integration side (PackageSyncTest) needs the test stack.
 */
final class PackageSyncAuditContractTest extends TestCase
{
    private const AUDIT_FUNCTIONS = [
        'packages_follow_first_versions_in_templates',
        'packages_audit_retired_assignments',
        'packages_audit_catalog_changes',
    ];

    private function code(): string
    {
        $path = dirname(__DIR__, 2) . '/mecm_packages.php';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);

        return (string) preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $source);
    }

    /** @return array{0:int,1:int} byte range of the function body, from its keyword to the next top-level function */
    private function functionBounds(string $code, string $name): array
    {
        $start = strpos($code, 'function ' . $name . '(');
        self::assertIsInt($start, $name . '() must exist (zero-match guard)');
        $end = strpos($code, "\nfunction ", $start + 1);
        if ($end === false) {
            $end = strpos($code, "\n\$clientIp = ", $start + 1);
        }
        self::assertIsInt($end, $name . '() must end before the endpoint body');

        return [$start, $end];
    }

    /** @return array{0:int,1:int} the transactional block of the endpoint */
    private function transactionBounds(string $code): array
    {
        $start = strpos($code, 'repo_transaction($connection, static function');
        self::assertIsInt($start, 'the sync must run in repo_transaction() (LG-04)');
        $end = strpos($code, "\n    });", $start);
        self::assertIsInt($end, 'the repo_transaction() block must close');

        return [$start, $end];
    }

    public function testTheSyncWriteRunsInTheTrackedTransaction(): void
    {
        $code = $this->code();

        self::assertStringNotContainsString('begin_transaction', $code, 'a raw BEGIN is invisible to the depth tracker that audit_event_required() reads');
        self::assertSame(0, substr_count($code, '->commit()'), 'repo_transaction() owns the commit');
        self::assertSame(1, substr_count($code, 'repo_transaction($connection'), 'exactly one transaction in the sync');
    }

    public function testEveryAuditOfTheSyncIsRequiredNotOptional(): void
    {
        $code = $this->code();

        foreach (self::AUDIT_FUNCTIONS as $name) {
            [$start, $end] = $this->functionBounds($code, $name);
            $body = substr($code, $start, $end - $start);
            self::assertStringContainsString('audit_event_required(', $body, $name . ' must write its row with audit_event_required()');
            self::assertDoesNotMatchRegularExpression('/(?<![A-Za-z_])audit_event\(/', $body, $name . ' must not use the unchecked audit_event()');
            self::assertStringNotContainsString('try {', $body, $name . ' must not swallow the failure of its row');
            self::assertStringNotContainsString('catch (', $body, $name . ' must not swallow the failure of its row');
        }
    }

    public function testEveryAuditCallSitsInsideTheTransactionBlock(): void
    {
        $code = $this->code();
        [$txStart, $txEnd] = $this->transactionBounds($code);

        foreach (['packages_follow_first_versions_in_templates(', 'packages_audit_retired_assignments(', 'packages_audit_catalog_changes('] as $call) {
            preg_match_all('/(?<![A-Za-z_])' . preg_quote($call, '/') . '/', $code, $matches, PREG_OFFSET_CAPTURE);
            self::assertNotSame([], $matches[0], $call . ' is called by the sync (zero-match guard)');
            foreach ($matches[0] as [, $offset]) {
                // The function definitions themselves sit above the endpoint block.
                if (preg_match('/function\s+$/', substr($code, max(0, $offset - 16), 16)) === 1) {
                    continue;
                }
                self::assertTrue($offset > $txStart && $offset < $txEnd, $call . ' must run inside the sync transaction');
            }
        }

        self::assertGreaterThan(0, substr_count(substr($code, $txStart, $txEnd - $txStart), 'packages_audit_catalog_changes('));
    }

    public function testBothCatalogsGetTheirSummaryRow(): void
    {
        $code = $this->code();

        self::assertStringContainsString("packages_audit_catalog_changes(\$connection, 'packages', \$createdPackages, \$retiredPackages, \$clientIp)", $code);
        self::assertStringContainsString("packages_audit_catalog_changes(\$connection, 'task_sequences', \$createdOs, \$retiredOs, \$clientIp)", $code);
        self::assertStringContainsString('VIRTUSPHERE_AUDIT_EVENT_MECM_CATALOG_CHANGED', $code);
    }

    public function testTheListsAreCutToFitTheContext(): void
    {
        $code = $this->code();

        self::assertSame(2, substr_count($code, 'audit_fit_string_list('), 'both list rows (relink and retirement) are bounded before they are written');
    }

    /** Zero-match guard: the scan must still see the real sync, or the checks above prove nothing. */
    public function testTheScanLooksAtTheRealSync(): void
    {
        $code = $this->code();

        self::assertStringContainsString('function packages_audit_catalog_changes(', $code);
        self::assertStringContainsString('catalog_retire_missing($connection, ', $code);
        self::assertStringContainsString('INSERT INTO deploy_packages', $code);
        self::assertStringContainsString('INSERT INTO deploy_os', $code);
    }
}
