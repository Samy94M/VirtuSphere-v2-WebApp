<?php

declare(strict_types=1);

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/envboot.php';
require_once __DIR__ . '/request.php';

/**
 * The one validated description of "which audit rows".
 *
 * The logs table and its CSV export used to derive their filters separately
 * from the same query string, which is one derivation too many: the download is
 * evidence, and evidence that silently answers a slightly different question
 * than the screen it was started from is worse than no download. So the page
 * builds this struct once and both readers take their arguments from it
 * (log_filter_repo_args()), which makes the table and the export the same query
 * by construction rather than by two matching lines of code.
 *
 * Everything here is display/diagnostic only. The filter never widens what a
 * user may see: RBAC is decided by `users.manage` at the top of the page, and
 * a filter can only narrow the rows that permission already grants.
 */

/**
 * Validates a raw query string into the closed filter struct.
 *
 * The tab is the outer scope and always resolves to a known tab; the category
 * sub-filter is scoped to that tab and anything outside it means "every
 * category in this tab", which is also what the empty placeholder means. Both
 * fall back rather than erroring, because a stale bookmark must still show
 * rows, but the fallback is recorded in the struct so nothing downstream has to
 * re-derive it.
 *
 * @param array<string,mixed> $query
 * @return array{tab:string,category:string,search:string,ip:string,categories:list<string>}
 */
function log_filter_from_query(array $query): array
{
    $tabKeys = array_keys(VIRTUSPHERE_LOG_TABS);
    $tab = request_string($query, 'tab');
    if (!in_array($tab, $tabKeys, true)) {
        $tab = (string) $tabKeys[0];
    }
    $tabCategories = VIRTUSPHERE_LOG_TABS[$tab];

    $category = request_trimmed($query, 'category');
    if (!in_array($category, $tabCategories, true)) {
        $category = '';
    }

    return [
        'tab' => $tab,
        'category' => $category,
        'search' => request_trimmed($query, 'q'),
        'ip' => request_trimmed($query, 'ip'),
        'categories' => $category !== '' ? [$category] : $tabCategories,
    ];
}

/**
 * The exact arguments both readers pass to the repository, in order.
 *
 * A single function owns this so the count, the table page and the export
 * cannot drift into three slightly different calls; the repository signature
 * itself keeps its positional parameters until Etappe 15 rewrites it.
 *
 * @param array{search:string,ip:string,categories:list<string>} $filter
 * @return array{0:string,1:string,2:list<string>}
 */
function log_filter_repo_args(array $filter): array
{
    return [$filter['search'], $filter['ip'], $filter['categories']];
}

/**
 * The filter in canonical form: fixed key order, categories sorted, no derived
 * or presentational fields. This is what gets fingerprinted, so two requests
 * that select the same rows produce the same value regardless of the order the
 * parameters arrived in or which of tab/category implied the category list.
 *
 * @param array{tab:string,category:string,search:string,ip:string,categories:list<string>} $filter
 * @return array<string,mixed>
 */
function log_filter_canonical(array $filter): array
{
    $categories = array_values(array_unique($filter['categories']));
    sort($categories, SORT_STRING);

    return [
        'categories' => $categories,
        'has_ip' => $filter['ip'] !== '',
        'has_search' => $filter['search'] !== '',
        'ip' => $filter['ip'],
        'search' => $filter['search'],
        'tab' => $filter['tab'],
    ];
}

/**
 * A stable, non-reversible name for one filter, for the export audit.
 *
 * Deliberately an HMAC and not a bare hash. The inputs are low-entropy: the tab
 * and the categories come from a list of a handful of values, an IP filter is a
 * 32-bit space, and a search term is usually a name or a host. A plain SHA-256
 * of that is a lookup table, so an audit row that promised to keep the search
 * term out of the log would hand it over to anyone who can read the row and
 * guess a few thousand candidates. Keyed with the application key, the digest
 * stays comparable across rows of this installation and means nothing outside
 * it.
 *
 * @param array{tab:string,category:string,search:string,ip:string,categories:list<string>} $filter
 */
function log_filter_fingerprint(array $filter): string
{
    $canonical = json_encode(
        log_filter_canonical($filter),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    return hash_hmac('sha256', 'virtusphere.log-filter.v1' . "\0" . $canonical, envboot_app_key_bytes());
}

/**
 * How many data rows an export of this filter may carry, and whether the
 * current match count exceeds it.
 *
 * @return array{limit:int,truncated:bool,exported:int}
 */
function log_filter_export_bounds(int $total): array
{
    $limit = VIRTUSPHERE_LOG_EXPORT_MAX_ROWS;

    return [
        'limit' => $limit,
        'truncated' => $total > $limit,
        'exported' => min($total, $limit),
    ];
}

/**
 * Rebuilds a logs.php URL from the struct plus explicit extras.
 *
 * Every link on the page (pagination, export, tab switch) is one of these, so
 * a filter field added to the struct cannot be silently dropped from one of
 * them: they all serialise through here.
 *
 * @param array{tab:string,category:string,search:string,ip:string,categories:list<string>} $filter
 * @param array<string,string|int> $extra
 */
function log_filter_url(array $filter, array $extra = [], bool $withCategory = true): string
{
    $query = ['tab' => $filter['tab']];
    if ($filter['search'] !== '') {
        $query['q'] = $filter['search'];
    }
    if ($filter['ip'] !== '') {
        $query['ip'] = $filter['ip'];
    }
    if ($withCategory && $filter['category'] !== '') {
        $query['category'] = $filter['category'];
    }

    return 'logs.php?' . http_build_query([...$query, ...$extra]);
}
