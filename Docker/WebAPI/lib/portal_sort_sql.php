<?php

declare(strict_types=1);

// The query side of the portal sort helpers (lib/portal_sort.php), in its own
// file because it is pure: a repository that pages in SQL loads it without
// pulling the portal request and escaping helpers into a CLI closure.

/**
 * ORDER BY terms for a database-paged list: the query-side counterpart of
 * portal_sort_apply(). The key is whitelisted against $columns (an unknown key
 * takes $default) and $dir is reduced to ASC or DESC, so no request text reaches
 * the SQL. The first expression of a column follows the direction, the others
 * ascend. The caller ends every column with a unique id, so LIMIT windows cut one
 * global order instead of a per-page one.
 *
 * @param array<string,list<string>> $columns key => ORDER BY expressions (SQL, never request text)
 */
function portal_sort_sql_order(array $columns, string $sort, string $dir, string $default): string
{
    $key = isset($columns[$sort]) ? $sort : $default;
    if (!isset($columns[$key])) {
        throw new InvalidArgumentException('Unknown sort column for a paged list.');
    }

    $terms = [];
    foreach ($columns[$key] as $index => $expression) {
        $terms[] = $expression . ($index === 0 && $dir === 'desc' ? ' DESC' : ' ASC');
    }

    return implode(', ', $terms);
}
