<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/constants.php';
require_once dirname(__DIR__, 2) . '/lib/audit_registry.php';

/**
 * The producer side of the Etappe 10C contract, as a build failure rather than
 * as a convention.
 *
 * `scripts/check-audit-contract.php` (gate `audit-contract`, with its harness
 * fixtures) owns the text rules alone: dead-sink, token-sink, unredacted-sink and
 * forbidden-field, and since QL4 (B1) also registry-bypass, the registered-event
 * walk in both directions and the historical events. W3A (R5) and QL4 removed
 * their copies here after a mutation probe showed the gate fails on each
 * mutation by itself (`docs/register/2026-11-08-W3A.md`,
 * `docs/register/2026-11-15-QL4.md`). What stays here says something the gate
 * does not: the owner still holding the one INSERT, the throttled events'
 * context fields, the throttle, export and filter shapes.
 */
final class AuditProducerContractTest extends TestCase
{
    /**
     * The owner still holds the one INSERT into the audit table.
     *
     * That nobody ELSE writes into deploy_logs, that every producer names a
     * registered event, that every registered event has a producer and that a
     * historical event is never written again is `scripts/check-audit-contract.php`
     * (registry-bypass, free-producer/unknown-event, unused-event,
     * historical-produced, with harness fixtures). QL4 (B1) removed the copies of
     * those scans here after a mutation probe on the real tree made the gate fail
     * on each (`docs/register/2026-11-15-QL4.md`). The gate does not ask whether
     * the owner itself still writes; this does.
     */
    public function testTheRepositoryOwnerHoldsTheAuditInsert(): void
    {
        self::assertMatchesRegularExpression('/INSERT\s+INTO\s+deploy_logs\b/i', $this->withoutComments($this->source('lib/repo/log.php')));
    }

    /**
     * The machine-denial count matches the event code, never the category.
     *
     * The `machine_api` category also holds a rejected MAC callback and an
     * internal failure. Counting the category told an operator whose Ansible
     * callback had raced a cancelled job to add the host to an allowlist it was
     * already on, and left the real cause unexamined.
     */
    public function testTheMachineDenialCountIsScopedToItsEventCode(): void
    {
        $repo = $this->source('lib/repo/log.php');
        $function = $this->functionBody($repo, 'repo_recent_machine_api_denials');

        self::assertStringContainsString('event_code = ?', $function);
        self::assertStringContainsString('VIRTUSPHERE_AUDIT_EVENT_MACHINE_API_DENIED', $function);
        self::assertStringNotContainsString('category = ?', $function);
        self::assertStringNotContainsString('VIRTUSPHERE_LOG_CATEGORY_MACHINE_API', $function);
    }

    /**
     * The auth flood check matches the event code too, and not the message
     * text. Its predecessor was a LIKE over a TEXT column, which a reworded
     * sentence would have disarmed without any test noticing.
     */
    public function testTheAuthFloodCheckMatchesTheEventCode(): void
    {
        $source = $this->source('lib/auth_rate_limit.php');

        self::assertStringContainsString('event_code = ?', $source);
        self::assertStringNotContainsString('log_message LIKE', $source);
    }

    /**
     * A successful heartbeat or run report writes no audit row. The three sync
     * tasks report on an interval, so one line per report would bury every real
     * event under thousands of routine ones (ADR-0018).
     */
    public function testNormalHeartbeatsAndRunReportsProduceNoAuditRow(): void
    {
        $report = $this->withoutComments($this->source('mecm_report.php'));

        // The heartbeat branch: from `$action === 'heartbeat'` to the next
        // action branch, there must be no audit producer at all.
        $heartbeat = $this->slice($report, "if (\$action === 'heartbeat')", "if (\$action === 'reportRun')");
        self::assertNotSame('', $heartbeat, 'the heartbeat branch scan matched nothing');
        self::assertDoesNotMatchRegularExpression('/\baudit_event\s*\(|machine_api_audit_warning\s*\(/', $heartbeat);

        // The device-cause branch (MX1) sits before reportPhase and is display
        // data too: no audit producer, not even for a stale entry (ADR-0043
        // amendment). Its refusals are audited by the shared token/IP gates.
        $causes = $this->slice($report, "if (\$action === 'reportDeviceCauses')", "if (\$action === 'reportPhase')");
        self::assertNotSame('', $causes, 'the reportDeviceCauses branch scan matched nothing');
        self::assertDoesNotMatchRegularExpression('/\baudit_event\s*\(|machine_api_audit_warning\s*\(/', $causes);
        self::assertDoesNotMatchRegularExpression(
            '/\baudit_event(?:_required)?\s*\(|machine_api_audit_warning\s*\(/',
            $this->withoutComments($this->source('lib/repo/vm_sync_causes.php'))
        );

        // The reportRun branch may audit exactly one thing: the one-time
        // legacy-to-V2 ratchet, which is gated on `legacy_to_v2`.
        $runBranch = substr($report, (int) strpos($report, "if (\$action === 'reportRun')"));
        preg_match_all('/machine_api_audit_warning\s*\(/', $runBranch, $audits);
        self::assertCount(1, $audits[0], 'the reportRun branch gained an audit producer');
        self::assertStringContainsString("!empty(\$result['legacy_to_v2'])", $runBranch);
    }

    /**
     * The throttle key is the event plus a scope, not a free tag. Keyed on the
     * tag alone, one noisy caller suppressed that tag's lines for every other
     * host for an hour.
     */
    public function testTheMachineThrottleIsKeyedOnEventAndScope(): void
    {
        $source = $this->withoutComments($this->source('lib/machine_api.php'));
        $function = $this->functionBody($source, 'machine_api_audit_warning');

        self::assertStringContainsString("machine_api_throttle_allows(\$db, \$definition['category'], \$eventCode, \$throttleScope)", $function);
        self::assertStringContainsString("\$context['suppressed_count'] = \$verdict['suppressed'];", $function);
        // There is no parameter through which a caller could persist a free
        // tag, message or category.
        self::assertStringNotContainsString('$tag', $function);
        self::assertStringNotContainsString('$message', $function);
        self::assertStringNotContainsString('$category', $function);
    }

    /**
     * Every event written through the throttling wrapper must declare the two
     * throttle context fields.
     *
     * The wrapper adds `suppressed_count` and `throttle_seconds` after the
     * throttle decides, so an event whose registry entry does not allow them
     * fails normalisation inside the wrapper's own `catch (Throwable)` and
     * degrades to an error_log line. That is the quietest possible failure: the
     * endpoint keeps working, the security row silently stops being written,
     * and nothing in the portal says so. The check is static because the
     * failure only shows up on the throttled path, which a test would have to
     * arrange per event.
     */
    public function testEveryThrottledEventDeclaresTheThrottleContextFields(): void
    {
        $used = [];
        foreach ($this->firstPartyFiles() as $relative => $source) {
            preg_match_all(
                '/(?<!function )\bmachine_api_audit_warning\s*\(\s*[^,()]{1,80},\s*(VIRTUSPHERE_AUDIT_EVENT_[A-Z0-9_]+)/s',
                $this->withoutComments($source),
                $calls,
                PREG_SET_ORDER
            );
            foreach ($calls as $call) {
                $used[$call[1]][] = $relative;
            }
        }
        self::assertNotEmpty($used, 'the throttled-producer scan matched nothing');

        $registry = audit_event_registry();
        $missing = [];
        foreach (array_keys($used) as $constant) {
            self::assertTrue(defined($constant), $constant . ' is not defined');
            $code = constant($constant);
            self::assertArrayHasKey($code, $registry, $code . ' is not registered');
            foreach (['suppressed_count', 'throttle_seconds'] as $field) {
                $allowed = [...$registry[$code]['required'], ...$registry[$code]['optional']];
                if (!in_array($field, $allowed, true)) {
                    $missing[] = $code . ' misses ' . $field;
                }
            }
        }

        self::assertSame([], $missing);
    }

    /** The CSV export writes exactly one audit row, and after reading its rows. */
    public function testTheLogExportWritesExactlyOneAuditRow(): void
    {
        $source = $this->withoutComments($this->source('lib/logs_export.php'));

        preg_match_all('/\baudit_event\s*\(/', $source, $audits);
        self::assertCount(1, $audits[0], 'the export must write exactly one audit row');

        $prepare = $this->functionBody($source, 'logs_export_prepare');
        $send = $this->functionBody($source, 'logs_export_send_csv');
        $rows = strpos($prepare, 'logs_export_rows(');
        $audit = strpos($prepare, 'audit_event(');
        $prepareCall = strpos($send, 'logs_export_prepare(');
        $stream = strpos($send, 'portal_send_csv(');
        self::assertIsInt($rows);
        self::assertIsInt($audit);
        self::assertIsInt($prepareCall);
        self::assertIsInt($stream);
        self::assertLessThan($audit, $rows, 'the rows must be read before the audit, or the file contains its own audit line');
        self::assertLessThan($stream, $prepareCall, 'the audited preparation must finish before response streaming starts');
        self::assertStringNotContainsString('audit_event(', $send, 'the streaming wrapper must not add a second audit row');

        // The context describes the export, never the filter's contents.
        self::assertStringContainsString("'filter_fingerprint' => log_filter_fingerprint(\$filter)", $source);
        self::assertStringNotContainsString("\$filter['search']", $source);
        self::assertStringNotContainsString("\$filter['ip']", $source);
    }

    /**
     * The truncation travels in response headers, and only there.
     *
     * A note row inside the CSV would contradict the file's own header line:
     * RFC 4180 knows only records of equal field count, so every reader that
     * follows it parses the note as data. The three headers are the machine
     * readable half of the same statement the page makes before the download.
     */
    public function testTheExportAnnouncesItsCapInResponseHeadersAndNotInTheFile(): void
    {
        $export = $this->withoutComments($this->source('lib/logs_export.php'));
        foreach (["'Total-Rows' => \$export['total']", "'Export-Limit' => \$bounds['limit']", "'Truncated' => \$bounds['truncated'] ? 1 : 0"] as $header) {
            self::assertStringContainsString($header, $export);
        }

        $writer = $this->withoutComments($this->source('lib/portal_export.php'));
        self::assertStringContainsString("header('X-VirtuSphere-' . \$name . ': ' . (int) \$value);", $writer);

        // Only the column titles and the row values reach the file.
        $send = $this->functionBody($writer, 'portal_send_csv');
        self::assertSame(2, substr_count($send, 'fputcsv('), 'the CSV writes exactly the header row and the data rows');
    }

    /**
     * The export is behind the same permission as the page.
     *
     * `logs.php` refuses without `users.manage` and only then dispatches; if the
     * export branch ever moved above that gate, an unauthorised request could
     * stream the whole audit log and exit before the page had refused it. The
     * assertion is the ORDER, because both statements would still be present.
     */
    public function testTheExportCannotRunBeforeThePermissionGate(): void
    {
        $page = $this->withoutComments($this->source('portal/logs.php'));

        $gate = strpos($page, "portal_forbid(\$connection, \$user, 'users.manage')");
        $export = strpos($page, "logs_export_send_csv(");
        self::assertIsInt($gate, 'the logs page lost its permission gate');
        self::assertIsInt($export, 'the export dispatch was not found');
        self::assertLessThan($export, $gate, 'the export must not be reachable before the permission gate');

        // And the gate is not conditional on the request being non-export.
        self::assertStringContainsString("if (!can('users.manage', \$user)) {", $page);
    }

    /**
     * The table and the export query with the same struct. Two derivations from
     * the same query string is one too many: the download is evidence.
     *
     * The struct is passed whole rather than unpacked into positional
     * arguments. With nine same-typed fields, an unpacked list lets a caller
     * swap two and get a perfectly working query for a different question,
     * which is exactly the failure this contract exists to prevent.
     */
    public function testTheTableAndTheExportShareOneFilterDerivation(): void
    {
        $page = $this->withoutComments($this->source('portal/logs.php'));
        $export = $this->withoutComments($this->source('lib/logs_export.php'));

        self::assertStringContainsString('log_filter_from_query($_GET)', $page);
        self::assertStringContainsString('repo_count_logs($connection, $filter)', $page, 'the count reads the struct');
        self::assertStringContainsString('repo_log_page($connection, $filter,', $page, 'the table page reads the struct');
        self::assertStringContainsString('repo_count_logs($connection, $filter)', $export);
        self::assertStringContainsString('logs_export_rows($connection, $filter,', $export);

        // The page must not rebuild the filter itself any more, and the old
        // unpacked-argument helper must not come back.
        self::assertStringNotContainsString('log_filter_repo_args', $page);
        self::assertStringNotContainsString('log_filter_repo_args', $export);
        self::assertStringNotContainsString("request_trimmed(\$_GET, 'q')", $page);
        self::assertStringNotContainsString("request_trimmed(\$_GET, 'ip')", $page);
    }

    /**
     * A rejected filter value is never queried around.
     *
     * Dropping it and querying the rest shows a WIDER result while the field
     * still displays the value the operator believes is filtering, and for a
     * correlation search that is the difference between "this request did
     * nothing else" and "you searched for the wrong id". The export is gated on
     * the same answer, or the file would answer the wide question the screen
     * declined to answer.
     */
    public function testARejectedFilterIsNeverQueriedAround(): void
    {
        $page = $this->withoutComments($this->source('portal/logs.php'));

        self::assertStringContainsString('log_filter_is_usable($filter)', $page);
        self::assertStringContainsString('$usable && ($_GET[\'export\'] ?? \'\') === \'csv\'', $page, 'the export is gated on the same answer');
        self::assertMatchesRegularExpression('/\$total = \$usable \? repo_count_logs/', $page);
        self::assertMatchesRegularExpression('/\$logPage = \$usable && !\$cursor\[.invalid.\]\s+\? repo_log_page/', $page);
    }

    /** @return array<string, string> relative path => source */
    private function firstPartyFiles(): array
    {
        $app = dirname(__DIR__, 2);
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($app, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            if (!str_ends_with($path, '.php')
                || str_contains($path, '/tests/')
                || str_contains($path, '/vendor/')
                || str_contains($path, '/lang/')) {
                continue;
            }
            $files[substr($path, strlen(str_replace('\\', '/', $app)) + 1)] = (string) file_get_contents($path);
        }
        ksort($files);
        self::assertNotEmpty($files, 'the first-party file scan matched nothing');

        return $files;
    }

    private function source(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** Comment-free source, so a prose mention is not read as a call. */
    private function withoutComments(string $source): string
    {
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                $out .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
                    ? str_repeat("\n", substr_count($token[1], "\n"))
                    : $token[1];
                continue;
            }
            $out .= $token;
        }

        return $out;
    }

    private function functionBody(string $source, string $name): string
    {
        $start = strpos($source, 'function ' . $name . '(');
        self::assertIsInt($start, $name . '() not found');
        $next = strpos($source, "\nfunction ", $start + 1);

        return $next === false ? substr($source, $start) : substr($source, $start, $next - $start);
    }

    private function slice(string $source, string $from, string $to): string
    {
        $start = strpos($source, $from);
        $end = strpos($source, $to);
        if (!is_int($start) || !is_int($end) || $end <= $start) {
            return '';
        }

        return substr($source, $start, $end - $start);
    }
}
