<?php

declare(strict_types=1);

// A point in time as an operator reads it on a status page (SY3, operator
// decision 09.11.2026 "relative and absolute"): "vor 3 Minuten (09.11.2026
// 14:02:05)". The relative part answers "is this fresh?" without arithmetic;
// the absolute part stays visible (not only in a tooltip, which touch and
// keyboard users cannot reach) and is the one SSoT format of
// portal_format_timestamp(). The page is server-rendered, so "ago" is measured
// against the moment the page was built, which the page head states.
require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/portal_time.php';

// Beyond a week "vor 40 Tagen" stops helping; only the date is shown.
const VIRTUSPHERE_TIME_RELATIVE_MAX_SECONDS = 7 * 86400;

/**
 * The relative part alone: "gerade eben", "vor 3 Minuten", "in 2 Stunden". One
 * unit, rounded down, because "vor 3 Minuten" is the question's answer; the
 * absolute value next to it carries the precision.
 */
function portal_format_relative(int $deltaSeconds): string
{
    $past = $deltaSeconds >= 0;
    $seconds = abs($deltaSeconds);
    if ($seconds < 60) {
        return __t($past ? 'common.time_relative_now' : 'common.time_relative_soon');
    }
    [$count, $one, $many] = match (true) {
        $seconds < 3600 => [intdiv($seconds, 60), 'minute', 'minutes'],
        $seconds < 86400 => [intdiv($seconds, 3600), 'hour', 'hours'],
        default => [intdiv($seconds, 86400), 'day', 'days'],
    };
    $duration = __t('common.time_unit_' . ($count === 1 ? $one : $many), ['count' => $count]);

    return __t($past ? 'common.time_ago' : 'common.time_in', ['duration' => $duration]);
}

/**
 * The machine-readable `datetime` value of a stored UTC timestamp: ISO 8601
 * with the display zone's offset, or '' when the value is empty or unreadable.
 */
function portal_time_iso(?string $utc): string
{
    $value = trim((string) ($utc ?? ''));
    if ($value === '') {
        return '';
    }
    try {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(portal_timezone()))->format(DATE_ATOM);
    } catch (Throwable) {
        return '';
    }
}

/**
 * A stored UTC timestamp as `<time datetime="…">relative (absolute)</time>`.
 *
 * Empty input is '' (the caller decides its placeholder). An unparsable value is
 * shown escaped as it is, without a `<time>` element, rather than as a wrong
 * time. `datetime` carries ISO 8601 with the display zone's offset.
 */
function portal_time_html(?string $utc, ?int $now = null): string
{
    $value = trim((string) ($utc ?? ''));
    $iso = portal_time_iso($value);
    if ($iso === '') {
        return h($value);
    }
    $absolute = portal_format_timestamp($value);
    $delta = ($now ?? time()) - (new DateTimeImmutable($iso))->getTimestamp();
    $text = abs($delta) > VIRTUSPHERE_TIME_RELATIVE_MAX_SECONDS
        ? $absolute
        : __t('common.time_relative_absolute', ['relative' => portal_format_relative($delta), 'absolute' => $absolute]);

    return '<time datetime="' . h($iso) . '">' . h($text) . '</time>';
}
