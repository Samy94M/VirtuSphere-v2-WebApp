<?php

declare(strict_types=1);

/** @param array<string,mixed> $filter The validated struct (lib/log_filter.php). */
function repo_recent_logs(mysqli $db, array $filter, int $limit = 50): array
{
    $limit = max(1, min(500, $limit));
    $filter = repo_log_filter($filter);
    $sql = 'SELECT l.id, l.ip, l.category, l.log_message, l.user_id, u.name AS user_name, l.correlation_id, l.created_at FROM deploy_logs l LEFT JOIN deploy_users u ON u.id = l.user_id'
        . $filter['sql'] . ' ORDER BY l.id DESC LIMIT ?';
    $types = $filter['types'] . 'i';
    $params = [...$filter['params'], $limit];

    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();

    return repo_fetch_all($stmt->get_result());
}

/**
 * One stable audit-log window, ordered newest first.
 *
 * `before` walks to older ids. `after` reads the nearest newer ids ascending,
 * then reverses that bounded result for display; this returns to the exact
 * preceding window even when a concurrent insert has become the new maximum.
 * A 51st row is look-ahead evidence only and is never rendered.
 *
 * @param array<string,mixed> $filter The validated struct (lib/log_filter.php).
 * @return array{rows:list<array<string,mixed>>,has_older:bool,has_newer:bool,stale:bool}
 */
function repo_log_page(
    mysqli $db,
    array $filter,
    int $limit = 50,
    ?int $before = null,
    ?int $after = null
): array {
    $limit = max(1, min(500, $limit));
    if (($before !== null && $before < 1) || ($after !== null && $after < 1) || ($before !== null && $after !== null)) {
        throw new InvalidArgumentException('Exactly one positive log cursor may be used.');
    }

    if ($before !== null) {
        $filter['_before_id'] = $before;
    } elseif ($after !== null) {
        $filter['_after_id'] = $after;
    }
    $query = repo_log_filter($filter);
    $ascending = $after !== null;
    $fetch = $limit + 1;
    $sql = 'SELECT l.id, l.ip, l.category, l.log_message, l.user_id, u.name AS user_name, l.correlation_id, l.created_at FROM deploy_logs l LEFT JOIN deploy_users u ON u.id = l.user_id'
        . $query['sql'] . ' ORDER BY l.id ' . ($ascending ? 'ASC' : 'DESC') . ' LIMIT ?';
    $types = $query['types'] . 'i';
    $params = [...$query['params'], $fetch];

    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = repo_fetch_all($stmt->get_result());
    $hasLookAhead = count($rows) > $limit;
    if ($hasLookAhead) {
        array_pop($rows);
    }
    if ($ascending) {
        $rows = array_reverse($rows);
    }

    $supplied = $before !== null || $after !== null;
    return [
        'rows' => $rows,
        'has_older' => $ascending ? $rows !== [] : $hasLookAhead,
        'has_newer' => $ascending ? $hasLookAhead : ($before !== null && $rows !== []),
        'stale' => $supplied && $rows === [],
    ];
}
