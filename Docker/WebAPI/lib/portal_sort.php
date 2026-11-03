<?php

declare(strict_types=1);

/**
 * Shared server-side sorting for portal list tables.
 *
 * Column keys are whitelisted by the caller, so untrusted `sort`/`dir` GET
 * input can never leak into SQL or reach the sort link unescaped. usort() is
 * stable on PHP 8, so rows that tie on the active column keep the repository's
 * default order (usually name). Locale plays no part here (ADR-0014): sorting
 * is a display concern, not a wire/auth decision.
 */

/**
 * Resolves the active sort column/direction from GET against $comparators and
 * sorts $rows in place. Returns [column, direction] for header rendering.
 *
 * @param array<int,array<string,mixed>>          $rows        sorted in place
 * @param array<string,callable(array,array):int> $comparators column key => comparator
 * @param string                                  $default     fallback column key
 * @return array{0:string,1:string}
 */
function portal_sort_apply(array &$rows, array $comparators, string $default): array
{
    // request_string, not a raw cast: `?sort[]=x` would otherwise throw into a
    // 500 (and a system audit row) from a stray bracket in the URL. The column
    // is whitelisted just below, so an unknown value already falls back safely.
    $sort = request_string($_GET, 'sort', $default);
    if (!isset($comparators[$sort])) {
        $sort = isset($comparators[$default]) ? $default : (string) array_key_first($comparators);
    }
    $dir = request_string($_GET, 'dir', 'asc') === 'desc' ? 'desc' : 'asc';

    if ($rows !== [] && isset($comparators[$sort])) {
        $comparator = $comparators[$sort];
        usort($rows, static function (array $a, array $b) use ($comparator, $dir): int {
            $result = $comparator($a, $b);
            return $dir === 'desc' ? -$result : $result;
        });
    }

    return [$sort, $dir];
}

/**
 * Renders one sortable column header <th>. Clicking toggles direction on the
 * active column; aria-sort and an arrow glyph expose the current order.
 * $baseParams are extra query params preserved on the link (filters, parent
 * ids). Emits a CSP-safe link, not an inline handler.
 *
 * @param array<string,string> $baseParams
 */
function portal_sort_header(
    string $script,
    string $column,
    string $label,
    string $activeSort,
    string $activeDir,
    array $baseParams = []
): string {
    $isActive = $activeSort === $column;
    $nextDir = ($isActive && $activeDir === 'asc') ? 'desc' : 'asc';
    $arrow = $isActive ? ($activeDir === 'asc' ? " \u{25B2}" : " \u{25BC}") : '';
    $ariaSort = $isActive ? ($activeDir === 'asc' ? 'ascending' : 'descending') : 'none';
    $params = array_merge($baseParams, ['sort' => $column, 'dir' => $nextDir]);
    $href = $script . '?' . http_build_query($params);

    return '<th aria-sort="' . $ariaSort . '">'
        . '<a class="sort-link" href="' . h($href) . '" title="' . h(__t('common.sort_by')) . '">'
        . h($label) . h($arrow) . '</a></th>';
}

/**
 * Case-insensitive, natural-order comparator for a string row key. Natural
 * order keeps "item-9" before "item-10" and "1.9" before "1.10".
 *
 * @return callable(array,array):int
 */
function portal_sort_text(string $key): callable
{
    return static fn (array $a, array $b): int => strnatcasecmp(
        (string) ($a[$key] ?? ''),
        (string) ($b[$key] ?? '')
    );
}

/**
 * Numeric comparator for a row key.
 *
 * @return callable(array,array):int
 */
function portal_sort_number(string $key): callable
{
    return static fn (array $a, array $b): int =>
        ((float) ($a[$key] ?? 0)) <=> ((float) ($b[$key] ?? 0));
}
