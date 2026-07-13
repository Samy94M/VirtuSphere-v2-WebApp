<?php

declare(strict_types=1);

/**
 * Shared CSV list export (Paket A3).
 *
 * Streams a UTF-8 CSV download with a BOM (so German Excel detects the encoding)
 * and a semicolon delimiter (German Excel opens comma CSVs into a single column).
 * Every cell runs through a CSV-injection guard so a value starting with a
 * formula character cannot be executed by a spreadsheet.
 *
 * This is a convenience list export only; the JSON mission export is the real
 * transfer/backup format.
 */

/**
 * Neutralizes CSV/formula-injection: a leading =,+,-,@ (or a tab/CR that some
 * apps treat as a formula lead-in) is prefixed with a single quote.
 */
function portal_csv_guard(string $value): string
{
    if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
        return "'" . $value;
    }

    return $value;
}

/**
 * Reduces a list name to what may appear inside a quoted Content-Disposition
 * filename. The name can carry user input (a mission name), and a quote in it
 * would end the quoted value early, so this runs where the header is written
 * rather than being an unwritten contract each caller has to remember. PHP
 * already refuses a header containing CR/LF, so this is about a well-formed
 * header, not about response splitting.
 */
function portal_csv_filename_slug(string $listName): string
{
    $slug = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $listName), '_');

    return $slug === '' ? 'export' : $slug;
}

/**
 * Streams a CSV download and exits. Never returns.
 *
 * @param string             $listName Name for the file (e.g. "missionen"), slugified here.
 * @param array<int, string> $header   Column titles.
 * @param array<int, array<int, string|int|float|null>> $rows Row value lists.
 */
function portal_send_csv(string $listName, array $header, array $rows): never
{
    $filename = 'virtusphere-' . portal_csv_filename_slug($listName) . '-' . date('Ymd-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');

    $out = fopen('php://output', 'w');
    // UTF-8 BOM: makes Excel pick UTF-8 instead of the system code page.
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_map('portal_csv_guard', $header), ';', '"', '');
    foreach ($rows as $row) {
        $cells = array_map(static fn ($value): string => portal_csv_guard((string) ($value ?? '')), $row);
        fputcsv($out, $cells, ';', '"', '');
    }
    fclose($out);
    exit;
}
