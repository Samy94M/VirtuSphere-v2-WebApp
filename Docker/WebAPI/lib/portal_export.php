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
 * The characters a spreadsheet may read as "this cell is a formula" when they
 * open the cell. `=`, `+`, `-` and `@` are the documented four; TAB, CR and LF
 * are lead-ins that some importers strip before looking at the next character,
 * which puts one of the four back in first position.
 *
 * The full-width forms are here because they are not decoration: an
 * autocorrecting locale (and several spreadsheet importers directly) fold
 * U+FF1D and its siblings onto their ASCII equivalents, so a cell starting
 * `＝cmd|'/c calc'!A1` arrives as a formula while an ASCII-only guard sees a
 * harmless CJK punctuation mark and passes it through untouched.
 */
const VIRTUSPHERE_CSV_FORMULA_LEAD_INS = [
    '=', '+', '-', '@', "\t", "\r", "\n",
    "\u{FF1D}", "\u{FF0B}", "\u{FF0D}", "\u{FF20}",
];

/**
 * Neutralizes CSV/formula-injection by prefixing a single quote, which every
 * spreadsheet reads as "the rest of this cell is literal text".
 *
 * Compares whole leading characters, not bytes: the full-width forms are three
 * bytes each, and a byte comparison would both miss them and, on a value
 * beginning with any other multibyte character, test a continuation byte
 * against an ASCII table for no reason.
 */
function portal_csv_guard(string $value): string
{
    if ($value === '') {
        return $value;
    }
    $first = mb_substr($value, 0, 1, 'UTF-8');

    return in_array($first, VIRTUSPHERE_CSV_FORMULA_LEAD_INS, true) ? "'" . $value : $value;
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
 * $meta carries the facts about the download that the file itself must not
 * state. A capped export needs to say so, but a comment or note row inside the
 * CSV would make the file disagree with its own header line: RFC 4180 knows
 * only records of equal field count, and every reader that follows it would
 * parse the note as data. So the truncation travels in response headers, where
 * a script can read it and a spreadsheet cannot be confused by it.
 *
 * @param string             $listName Name for the file (e.g. "missionen"), slugified here.
 * @param array<int, string> $header   Column titles.
 * @param array<int, array<int, string|int|float|null>> $rows Row value lists.
 * @param array<string, string|int> $meta Extra response headers, name without prefix.
 */
function portal_send_csv(string $listName, array $header, array $rows, array $meta = []): never
{
    $filename = 'virtusphere-' . portal_csv_filename_slug($listName) . '-' . date('Ymd-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    foreach ($meta as $name => $value) {
        // The name is a literal at every call site, never user input; the
        // assertion is here so a future dynamic caller fails loudly instead of
        // splitting the header block.
        if (preg_match('/^[A-Za-z0-9-]+$/', (string) $name) !== 1) {
            throw new InvalidArgumentException('CSV response meta name is not a header token');
        }
        header('X-VirtuSphere-' . $name . ': ' . (int) $value);
    }

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
