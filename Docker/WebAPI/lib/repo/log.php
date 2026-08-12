<?php

declare(strict_types=1);

require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/helpers.php';
// log_category_labels()/log_tab_labels() call __t(). Every portal caller has the
// bootstrap, but this module is also reachable from a CLI entrypoint that has
// none: lib/seed.php loads db.php, whose error handler requires this file to
// write its audit line. lang.php has no requires and no top-level side effects,
// so closing the closure here costs nothing.
require_once __DIR__ . '/../lang.php';

function addLog($ip, string $category, $request, $authToken, $connection)
{
    $logMessage = 'Request: ' . (string) $request . ' | Auth-Token: ' . (string) $authToken;
    $userId = $_SESSION['user_id'] ?? null;

    $stmt = $connection->prepare('INSERT INTO deploy_logs (ip, category, log_message, user_id, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())');
    $stmt->bind_param('sssi', $ip, $category, $logMessage, $userId);

    return $stmt->execute();
}

function audit(mysqli $connection, string $category, string $message, ?int $userId = null, ?string $ip = null): bool
{
    $ip = $ip ?? (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
    // ADR-0032: every audit row carries the correlation id of the execution
    // that wrote it (request id, or the adopted job id inside the worker).
    $correlationId = virtusphere_correlation_id();
    $stmt = $connection->prepare('INSERT INTO deploy_logs (ip, category, log_message, user_id, correlation_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())');
    $stmt->bind_param('sssis', $ip, $category, $message, $userId, $correlationId);

    return $stmt->execute();
}

function log_category_label(string $category): string
{
    return match ($category) {
        VIRTUSPHERE_LOG_CATEGORY_AUTH => __t('logs.category_auth'),
        VIRTUSPHERE_LOG_CATEGORY_SYSTEM => __t('logs.category_system'),
        VIRTUSPHERE_LOG_CATEGORY_LEGACY_API => __t('logs.category_legacy_api'),
        VIRTUSPHERE_LOG_CATEGORY_USERS => __t('logs.category_users'),
        VIRTUSPHERE_LOG_CATEGORY_CREDENTIALS => __t('logs.category_credentials'),
        VIRTUSPHERE_LOG_CATEGORY_MISSIONS => __t('logs.category_missions'),
        VIRTUSPHERE_LOG_CATEGORY_OS => __t('logs.category_os'),
        VIRTUSPHERE_LOG_CATEGORY_SETTINGS => __t('logs.category_settings'),
        VIRTUSPHERE_LOG_CATEGORY_VMS => __t('logs.category_vms'),
        VIRTUSPHERE_LOG_CATEGORY_VLANS => __t('logs.category_vlans'),
        VIRTUSPHERE_LOG_CATEGORY_DEPLOY => __t('logs.category_deploy'),
        VIRTUSPHERE_LOG_CATEGORY_MECM => __t('logs.category_mecm'),
        VIRTUSPHERE_LOG_CATEGORY_MACHINE_API => __t('logs.category_machine_api'),
        default => $category,
    };
}

function log_tab_label(string $tab): string
{
    return match ($tab) {
        VIRTUSPHERE_LOG_TAB_SECURITY => __t('logs.tab_security'),
        VIRTUSPHERE_LOG_TAB_RESOURCES => __t('logs.tab_resources'),
        VIRTUSPHERE_LOG_TAB_DEPLOY => __t('logs.tab_deploy'),
        VIRTUSPHERE_LOG_TAB_SYSTEM => __t('logs.tab_system'),
        default => $tab,
    };
}

// Retention window of the rows a tab shows (ADR-0026). Keyed on the tab, not
// on a second category list, so VIRTUSPHERE_LOG_TABS stays the only place that
// says which categories are security. Unknown tabs get the general window.
function log_retention_days_for_tab(string $tab): int
{
    return $tab === VIRTUSPHERE_LOG_TAB_SECURITY
        ? VIRTUSPHERE_LOG_RETENTION_SECURITY_DAYS
        : VIRTUSPHERE_LOG_RETENTION_DAYS;
}

// Colour variant of the category badge on the logs page. Display-only, like
// log_category_label(); unknown categories fall back to neutral. Colours
// separate categories within one tab, they do not grade severity.
function log_category_badge_class(string $category): string
{
    return match ($category) {
        VIRTUSPHERE_LOG_CATEGORY_AUTH,
        VIRTUSPHERE_LOG_CATEGORY_MISSIONS,
        VIRTUSPHERE_LOG_CATEGORY_MECM,
        VIRTUSPHERE_LOG_CATEGORY_SETTINGS => 'badge-info',
        VIRTUSPHERE_LOG_CATEGORY_CREDENTIALS,
        VIRTUSPHERE_LOG_CATEGORY_OS,
        VIRTUSPHERE_LOG_CATEGORY_LEGACY_API => 'badge-warning',
        VIRTUSPHERE_LOG_CATEGORY_VMS,
        VIRTUSPHERE_LOG_CATEGORY_DEPLOY => 'badge-success',
        default => 'badge-neutral',
    };
}

// Deep link into the log view filtered to one category. The tab has to travel
// with it: logs.php scopes the category filter to the active tab and drops a
// category that does not belong to it, so a bare `?category=` lands on the
// default tab with no filter at all.
function log_category_url(string $category): string
{
    foreach (VIRTUSPHERE_LOG_TABS as $tab => $categories) {
        if (in_array($category, $categories, true)) {
            $query = ['tab' => $tab, 'category' => $category];

            return 'logs.php?' . http_build_query($query);
        }
    }

    return 'logs.php';
}

/**
 * Prunes deploy_logs on two windows (ADR-0026). Rows of the security categories
 * (derived from the security tab, never restated here) keep $securityDays;
 * every other row keeps $generalDays. The general delete matches by NOT IN, so
 * a category outside today's taxonomy (pre-taxonomy rows, a category later
 * removed from the enum) decays on the general window instead of surviving
 * forever; deploy_logs.category is NOT NULL, so NOT IN never sees NULL.
 *
 * @return int Total rows purged across both windows.
 */
function removeLog(mysqli $db, int $securityDays = VIRTUSPHERE_LOG_RETENTION_SECURITY_DAYS, int $generalDays = VIRTUSPHERE_LOG_RETENTION_DAYS): int
{
    $security = VIRTUSPHERE_LOG_TABS[VIRTUSPHERE_LOG_TAB_SECURITY];
    $placeholders = implode(', ', array_fill(0, count($security), '?'));
    $types = str_repeat('s', count($security)) . 'i';

    $stmt = $db->prepare('DELETE FROM deploy_logs WHERE category IN (' . $placeholders . ') AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)');
    $stmt->bind_param($types, ...[...$security, $securityDays]);
    $stmt->execute();
    $purged = max(0, $stmt->affected_rows);

    $stmt = $db->prepare('DELETE FROM deploy_logs WHERE category NOT IN (' . $placeholders . ') AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)');
    $stmt->bind_param($types, ...[...$security, $generalDays]);
    $stmt->execute();

    return $purged + max(0, $stmt->affected_rows);
}

/**
 * Prunes the login-attempt table, which is a lockout counter, not an archive:
 * only the last 15 minutes decide whether an account or an IP is locked. The
 * sign-in story lives on the `auth` audit channel (every failure below the IP
 * rate limit, plus the onset of the limit itself) and is retained on the
 * security window there, so the rows here have no forensic value of their own.
 * Deliberately its own short window (ADR-0026), not the audit windows: this
 * table only needs to outlive the lockout decision by a comfortable margin.
 */
function repo_purge_login_attempts(mysqli $db, int $retentionDays = VIRTUSPHERE_LOGIN_ATTEMPT_RETENTION_DAYS): int
{
    $stmt = $db->prepare('DELETE FROM deploy_login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL ? DAY)');
    $stmt->bind_param('i', $retentionDays);
    $stmt->execute();

    return max(0, $stmt->affected_rows);
}

/**
 * @param array<int, string> $categories Restrict to these categories (OR-combined
 *        via IN). Unknown values are dropped; an empty list means no restriction.
 * @return array{sql: string, types: string, params: array<int, string>}
 */
function repo_log_filter(string $search, string $ip, array $categories = []): array
{
    $conditions = [];
    $types = '';
    $params = [];
    if ($search !== '') {
        $conditions[] = '(l.log_message LIKE ? OR u.name LIKE ?)';
        $needle = '%' . addcslashes($search, '%_\\') . '%';
        $types .= 'ss';
        $params[] = $needle;
        $params[] = $needle;
    }
    if ($ip !== '') {
        $conditions[] = 'l.ip = ?';
        $types .= 's';
        $params[] = $ip;
    }
    $categories = array_values(array_filter(
        $categories,
        static fn (string $category): bool => in_array($category, VIRTUSPHERE_LOG_CATEGORIES, true)
    ));
    if ($categories !== []) {
        $placeholders = implode(', ', array_fill(0, count($categories), '?'));
        $conditions[] = 'l.category IN (' . $placeholders . ')';
        $types .= str_repeat('s', count($categories));
        foreach ($categories as $category) {
            $params[] = $category;
        }
    }
    $sql = $conditions !== [] ? ' WHERE ' . implode(' AND ', $conditions) : '';

    return ['sql' => $sql, 'types' => $types, 'params' => $params];
}

/**
 * @param array<int, string> $categories
 */
function repo_count_logs(mysqli $db, string $search = '', string $ip = '', array $categories = []): int
{
    $filter = repo_log_filter($search, $ip, $categories);
    $sql = 'SELECT COUNT(*) AS total FROM deploy_logs l LEFT JOIN deploy_users u ON u.id = l.user_id' . $filter['sql'];

    $stmt = $db->prepare($sql);
    if ($filter['types'] !== '') {
        $stmt->bind_param($filter['types'], ...$filter['params']);
    }
    $stmt->execute();
    $row = repo_fetch_all($stmt->get_result())[0] ?? ['total' => 0];

    return (int) $row['total'];
}

/**
 * The IPs whose machine access was refused within the given window, newest
 * first, with the time of the most recent refusal.
 *
 * This is what lets the System status tell three states apart that all rendered
 * as the same grey "no data yet": never configured, configured but the first run
 * is still pending, and configured but REJECTED. The third is the commonest
 * setup mistake in the product - a missing IP allowlist entry - and it looked
 * exactly like a server on which MECM was never installed. A refusal is
 * positive evidence that somebody is knocking, so it is the one signal that
 * distinguishes them.
 *
 * @return list<array{ip: string, last_at: string, hits: int}>
 */
function repo_recent_machine_api_denials(mysqli $db, int $withinSeconds = 86400, int $limit = 5): array
{
    $limit = max(1, min(50, $limit));
    $category = VIRTUSPHERE_LOG_CATEGORY_MACHINE_API;
    $stmt = $db->prepare(
        'SELECT ip, MAX(created_at) AS last_at, COUNT(*) AS hits
         FROM deploy_logs
         WHERE category = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? SECOND) AND ip <> \'\'
         GROUP BY ip
         ORDER BY last_at DESC
         LIMIT ' . $limit
    );
    $stmt->bind_param('si', $category, $withinSeconds);
    $stmt->execute();

    $rows = [];
    foreach (repo_fetch_all($stmt->get_result()) as $row) {
        $rows[] = ['ip' => (string) $row['ip'], 'last_at' => (string) $row['last_at'], 'hits' => (int) $row['hits']];
    }

    return $rows;
}

/**
 * @param array<int, string> $categories
 */
function repo_recent_logs(mysqli $db, int $limit = 50, int $offset = 0, string $search = '', string $ip = '', array $categories = []): array
{
    $limit = max(1, min(500, $limit));
    $offset = max(0, $offset);
    $filter = repo_log_filter($search, $ip, $categories);
    $sql = 'SELECT l.id, l.ip, l.category, l.log_message, l.user_id, u.name AS user_name, l.created_at FROM deploy_logs l LEFT JOIN deploy_users u ON u.id = l.user_id'
        . $filter['sql'] . ' ORDER BY l.id DESC LIMIT ? OFFSET ?';
    $types = $filter['types'] . 'ii';
    $params = [...$filter['params'], $limit, $offset];

    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();

    return repo_fetch_all($stmt->get_result());
}