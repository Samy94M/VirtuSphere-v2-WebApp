<?php

declare(strict_types=1);

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/envboot.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/request.php';
require_once __DIR__ . '/portal_time.php';
require_once __DIR__ . '/log_filter_vocabulary.php';

/**
 * The one validated description of "which audit rows".
 *
 * The logs table and its CSV export used to derive their filters separately
 * from the same query string, which is one derivation too many: the download is
 * evidence, and evidence that silently answers a slightly different question
 * than the screen it was started from is worse than no download. So the page
 * builds this struct once and every reader takes it whole - the count, the
 * table page, the export and the URL builder.
 *
 * Everything here is display/diagnostic only. The filter never widens what a
 * user may see: RBAC is decided by `users.manage` at the top of the page, and
 * a filter can only narrow the rows that permission already grants.
 */

/**
 * The fields a caller may hand back to the page as query parameters, in the
 * order the URL builder writes them. Sorting is not cosmetic here: it is what
 * makes two links with the same filter the same string, so the browser treats
 * them as the same page.
 */
const VIRTUSPHERE_LOG_FILTER_PARAMS = [
    'q' => 'search',
    'ip' => 'ip',
    'from' => 'from',
    'to' => 'to',
    'correlation' => 'correlation',
    'event' => 'event_code',
    'object_type' => 'object_type',
    'object_id' => 'object_id',
    'result' => 'result',
];

/** Longest accepted object id in a filter; the column itself is this wide. */
const VIRTUSPHERE_LOG_FILTER_OBJECT_ID_MAX = 191;

/**
 * Validates a raw query string into the closed filter struct.
 *
 * Two kinds of input are handled deliberately differently.
 *
 * The tab and the category FALL BACK: an unknown value means "every category in
 * this tab", which is also what the empty placeholder means, because a stale
 * bookmark must still show rows. The fallback is recorded in the struct so
 * nothing downstream re-derives it.
 *
 * Everything an operator types is REJECTED rather than dropped. A malformed
 * date or a correlation id with a typo used to be silently ignored, so the page
 * answered a wider question than the one on screen and looked like it had
 * answered the narrow one; for a correlation search that is the difference
 * between "this request did nothing else" and "you searched for the wrong id".
 * A rejected value stays visible in the struct, produces a field error, and
 * `log_filter_is_usable()` is false, which is what stops the page from querying
 * at all.
 *
 * @param array<string,mixed> $query
 * @return array<string,mixed>
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

    $errors = [];

    $from = request_trimmed($query, 'from');
    $to = request_trimmed($query, 'to');
    $range = log_filter_local_range($from, $to, $errors);

    $correlation = strtolower(request_trimmed($query, 'correlation'));
    if ($correlation !== '' && !virtusphere_correlation_id_is_valid($correlation)) {
        // Exact only, by decision (ADR-0032, Etappe 15.8): a substring search
        // over a diagnostic id makes it behave like free text, so "a1b2" would
        // quietly return the traces of a dozen unrelated requests and read as
        // if they belonged together.
        $errors['correlation'] = 'logs.err_correlation';
    }

    $eventCode = request_trimmed($query, 'event');
    if ($eventCode !== '' && !in_array($eventCode, log_filter_event_codes(), true)) {
        $errors['event'] = 'logs.err_event';
    }

    $objectType = request_trimmed($query, 'object_type');
    if ($objectType !== '' && !in_array($objectType, log_filter_object_types(), true)) {
        $errors['object_type'] = 'logs.err_object_type';
    }

    $objectId = request_trimmed($query, 'object_id');
    if (strlen($objectId) > VIRTUSPHERE_LOG_FILTER_OBJECT_ID_MAX) {
        // Never truncated: a shortened id selects rows about a different
        // object than the one asked for, and nothing downstream can tell.
        $errors['object_id'] = 'logs.err_object_id';
    }

    $result = request_trimmed($query, 'result');
    if ($result !== '' && !in_array($result, VIRTUSPHERE_AUDIT_RESULTS, true)) {
        $errors['result'] = 'logs.err_result';
    }

    return [
        'tab' => $tab,
        'category' => $category,
        'categories' => $category !== '' ? [$category] : $tabCategories,
        'search' => request_trimmed($query, 'q'),
        'ip' => request_trimmed($query, 'ip'),
        'from' => $from,
        'to' => $to,
        'from_utc' => $range['from_utc'],
        'to_utc' => $range['to_utc'],
        'correlation' => $correlation,
        'event_code' => $eventCode,
        'object_type' => $objectType,
        'object_id' => $objectId,
        'result' => $result,
        'errors' => $errors,
    ];
}

/**
 * The two local dates as one half-open UTC interval.
 *
 * The lower bound is the START of the "from" day and the upper bound is the
 * start of the day AFTER "to", exclusive, both taken in the configured portal
 * timezone and then converted to UTC, because that is where the rows live
 * (ADR-0022). The day after is computed by adding one DAY, never 86400 seconds:
 * on the two DST days of a year a local day is 23 or 25 hours long, so the fixed
 * arithmetic would cut an hour off one boundary and add an hour of the next day
 * to the other - silently, and only twice a year, which is the worst possible
 * way for a date filter to be wrong.
 *
 * An unparsable date is an error rather than an ignored parameter, and a
 * reversed range is reported on the field the operator most likely mistyped
 * rather than being quietly swapped: swapping would answer a question nobody
 * asked and hide the typo.
 *
 * @param array<string,string> $errors
 * @return array{from_utc:?string,to_utc:?string}
 */
function log_filter_local_range(string $from, string $to, array &$errors): array
{
    $zone = new DateTimeZone(portal_timezone());
    $fromDay = log_filter_parse_day($from, $zone);
    $toDay = log_filter_parse_day($to, $zone);

    if ($from !== '' && $fromDay === null) {
        $errors['from'] = 'logs.err_date';
    }
    if ($to !== '' && $toDay === null) {
        $errors['to'] = 'logs.err_date';
    }
    if ($fromDay !== null && $toDay !== null && $fromDay > $toDay) {
        $errors['to'] = 'logs.err_date_order';
    }
    if ($errors !== []) {
        return ['from_utc' => null, 'to_utc' => null];
    }

    $utc = new DateTimeZone('UTC');

    return [
        'from_utc' => $fromDay?->setTimezone($utc)->format('Y-m-d H:i:s'),
        'to_utc' => $toDay?->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s'),
    ];
}

/**
 * `YYYY-MM-DD` at local midnight, or null.
 *
 * checkdate() before construction, because DateTimeImmutable happily rolls
 * 2026-02-31 forward to March and a filter that answers about a different month
 * than the one typed is worse than one that refuses.
 */
function log_filter_parse_day(string $value, DateTimeZone $zone): ?DateTimeImmutable
{
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
        return null;
    }
    if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return null;
    }

    try {
        return new DateTimeImmutable($value . ' 00:00:00', $zone);
    } catch (Throwable) {
        return null;
    }
}

/**
 * The unfiltered view of one tab: what "reset" means, and what switching tabs
 * lands on.
 *
 * Dropping everything is the decision. A category, an event code or an object
 * type belongs to the section it was chosen in; carried into another tab it
 * selects nothing while still looking like an active filter, which reads as an
 * empty log rather than as a filter that cannot match here.
 *
 * @return array<string,mixed>
 */
function log_filter_empty(string $tab): array
{
    $query = ['tab' => $tab];

    return log_filter_from_query($query);
}

/** Whether anything at all narrows this view beyond its tab. */
function log_filter_is_narrowed(array $filter): bool
{
    if ((string) ($filter['category'] ?? '') !== '') {
        return true;
    }
    foreach (VIRTUSPHERE_LOG_FILTER_PARAMS as $field) {
        if ((string) ($filter[$field] ?? '') !== '') {
            return true;
        }
    }

    return false;
}

/**
 * Whether this filter may be handed to the repository at all.
 *
 * A rejected value must not be dropped and queried around: the page would then
 * show a wider result while the field still displays the value the operator
 * believes is filtering.
 */
function log_filter_is_usable(array $filter): bool
{
    return ($filter['errors'] ?? []) === [];
}

/**
 * The filter in canonical form: fixed key order, categories sorted, no derived
 * or presentational fields. This is what gets fingerprinted, so two requests
 * that select the same rows produce the same value regardless of the order the
 * parameters arrived in or which of tab/category implied the category list.
 *
 * The UTC bounds are canonicalised, not the typed dates: two readers in
 * different display timezones who select the same rows must fingerprint the
 * same, and the same two dates do not mean the same interval to both.
 *
 * @return array<string,mixed>
 */
function log_filter_canonical(array $filter): array
{
    $categories = array_values(array_unique($filter['categories']));
    sort($categories, SORT_STRING);

    return [
        'categories' => $categories,
        'correlation' => $filter['correlation'],
        'event_code' => $filter['event_code'],
        'from_utc' => $filter['from_utc'],
        'has_ip' => $filter['ip'] !== '',
        'has_search' => $filter['search'] !== '',
        'ip' => $filter['ip'],
        'object_id' => $filter['object_id'],
        'object_type' => $filter['object_type'],
        'result' => $filter['result'],
        'search' => $filter['search'],
        'tab' => $filter['tab'],
        'to_utc' => $filter['to_utc'],
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
 * Every link on the page (pagination, tab switch, reset, export, a correlation
 * jump) is one of these, so a filter field added to the struct cannot be
 * silently dropped from one of them: they all serialise through here, over one
 * parameter list (VIRTUSPHERE_LOG_FILTER_PARAMS). A rejected value is carried
 * too - it stays on screen with its error instead of vanishing from the URL of
 * the very page that is complaining about it.
 *
 * @param array<string,string|int> $extra
 */
function log_filter_url(array $filter, array $extra = [], bool $withCategory = true): string
{
    $query = ['tab' => $filter['tab']];
    foreach (VIRTUSPHERE_LOG_FILTER_PARAMS as $param => $field) {
        if ((string) ($filter[$field] ?? '') !== '') {
            $query[$param] = (string) $filter[$field];
        }
    }
    if ($withCategory && $filter['category'] !== '') {
        $query['category'] = $filter['category'];
    }

    return 'logs.php?' . http_build_query([...$query, ...$extra]);
}

/**
 * The link that answers "what else did this request do": the same tab, every
 * other filter dropped, one correlation id.
 *
 * Dropping the rest is the point. A trace is read to see the WHOLE request, and
 * carrying the category or the date range that happened to be set would show a
 * slice of it while looking like the complete answer.
 */
function log_filter_correlation_url(string $tab, string $correlation): string
{
    $query = ['tab' => $tab, 'correlation' => $correlation];

    return 'logs.php?' . http_build_query($query);
}
