<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The logs page renders a row through the display module, never its stored text
 * directly, and both list queries carry the columns that module needs. The CSV
 * export keeps the stored English text on purpose, and says so where it reads it.
 */
final class AuditLogDisplayContractTest extends TestCase
{
    private const STRUCTURED_COLUMNS = ['l.event_code', 'l.object_type', 'l.object_id', 'l.result', 'l.context_json'];

    private function source(string $relative): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $relative);
        self::assertIsString($source, $relative . ' must be readable');

        return $source;
    }

    public function testTheLogsPageEchoesTheDisplayModuleNotTheStoredText(): void
    {
        $page = $this->source('portal/logs.php');

        self::assertStringContainsString('audit_log_display_message(', $page, 'the logs page must render rows through the display module');
        self::assertStringNotContainsString("\$row['log_message']", $page, 'the logs page must not echo the stored log_message');
    }

    public function testBothListQueriesSelectTheStructuredColumns(): void
    {
        $queries = $this->source('lib/repo/log_queries.php');

        self::assertSame(2, substr_count($queries, 'FROM deploy_logs l LEFT JOIN deploy_users u'), 'the zero-match guard: both list queries must be found');
        foreach (self::STRUCTURED_COLUMNS as $column) {
            self::assertSame(2, substr_count($queries, $column . ','), $column . ' must be selected by both list queries');
        }
    }

    public function testTheCsvExportKeepsTheStoredEnglishTextOnPurpose(): void
    {
        $export = $this->source('lib/logs_export.php');

        self::assertStringContainsString("row['log_message']", $export);
        self::assertStringContainsString('Stored English text on purpose', $export);
    }

    public function testTheLanguageHelperExposesTheLocaleSpecificLookup(): void
    {
        self::assertStringContainsString('public static function getIn(string $locale, string $key', $this->source('lib/lang.php'));
    }
}
