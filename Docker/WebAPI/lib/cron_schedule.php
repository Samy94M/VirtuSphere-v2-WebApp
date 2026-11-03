<?php

declare(strict_types=1);

/**
 * Minimal cron expression evaluator (ADR-0024).
 *
 * `scripts/backup.sh` reports the schedule it actually runs under; the portal
 * turns that expression into the next expected run, so the backup card can name
 * a time instead of guessing "last run plus 24 hours".
 *
 * Supported: the five standard fields (minute hour day-of-month month
 * day-of-week) with `*`, `a`, `a-b`, `a-b/n`, `a/n`, `*\/n`, comma lists, the
 * usual three-letter month and weekday names, `7` as a second Sunday, and the
 * `@daily`/`@hourly`/... shorthands. Anything else (`@reboot`, a seconds field,
 * `L`, `W`, `#`, `?`) yields null, so the caller falls back to its own estimate
 * rather than displaying a wrong date.
 *
 * Dependency-free on purpose: lib/backup_status.php reads it before the portal
 * bootstrap and the unit tests load it standalone.
 */

/** Search horizon for the next occurrence. A backup schedule never exceeds it. */
const VIRTUSPHERE_CRON_HORIZON_DAYS = 366;

/** Non-standard but universal shorthands, expanded before parsing. */
const VIRTUSPHERE_CRON_SHORTHANDS = [
    '@yearly' => '0 0 1 1 *',
    '@annually' => '0 0 1 1 *',
    '@monthly' => '0 0 1 * *',
    '@weekly' => '0 0 * * 0',
    '@daily' => '0 0 * * *',
    '@midnight' => '0 0 * * *',
    '@hourly' => '0 * * * *',
];

const VIRTUSPHERE_CRON_MONTH_NAMES = [
    'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
    'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
];

const VIRTUSPHERE_CRON_DOW_NAMES = [
    'sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6,
];

/**
 * Parses a five-field cron expression into per-field value sets.
 *
 * The `*_restricted` flags carry the day-of-month / day-of-week quirk of cron:
 * when both fields are restricted, a day matches if *either* one matches.
 *
 * @return array{minute:array<int,bool>,hour:array<int,bool>,dom:array<int,bool>,month:array<int,bool>,dow:array<int,bool>,dom_restricted:bool,dow_restricted:bool}|null
 */
function cron_schedule_parse(string $expression): ?array
{
    $expression = trim($expression);
    if ($expression === '') {
        return null;
    }

    $shorthand = VIRTUSPHERE_CRON_SHORTHANDS[strtolower($expression)] ?? null;
    if ($shorthand !== null) {
        $expression = $shorthand;
    } elseif (str_starts_with($expression, '@')) {
        // @reboot and friends have no calendar meaning.
        return null;
    }

    $fields = preg_split('/\s+/', $expression) ?: [];
    if (count($fields) !== 5) {
        return null;
    }

    $minute = cron_field_set($fields[0], 0, 59, []);
    $hour = cron_field_set($fields[1], 0, 23, []);
    $dom = cron_field_set($fields[2], 1, 31, []);
    $month = cron_field_set($fields[3], 1, 12, VIRTUSPHERE_CRON_MONTH_NAMES);
    $dow = cron_field_set($fields[4], 0, 7, VIRTUSPHERE_CRON_DOW_NAMES);

    if ($minute === null || $hour === null || $dom === null || $month === null || $dow === null) {
        return null;
    }

    // Cron accepts both 0 and 7 for Sunday.
    if (isset($dow[7])) {
        $dow[0] = true;
        unset($dow[7]);
    }

    return [
        'minute' => $minute,
        'hour' => $hour,
        'dom' => $dom,
        'month' => $month,
        'dow' => $dow,
        'dom_restricted' => trim($fields[2]) !== '*',
        'dow_restricted' => trim($fields[4]) !== '*',
    ];
}

/**
 * Expands one field ("*", "1,3", "8-17/2", "mon-fri") into a value set.
 *
 * @param array<string,int> $names Accepted three-letter names for this field.
 * @return array<int,bool>|null Null on anything this evaluator does not understand.
 */
function cron_field_set(string $field, int $min, int $max, array $names): ?array
{
    $field = trim($field);
    if ($field === '') {
        return null;
    }

    $set = [];
    foreach (explode(',', $field) as $part) {
        $part = trim($part);
        if ($part === '') {
            return null;
        }

        $step = 1;
        if (str_contains($part, '/')) {
            [$part, $stepRaw] = explode('/', $part, 2);
            if ($stepRaw === '' || !ctype_digit($stepRaw) || (int) $stepRaw < 1) {
                return null;
            }
            $step = (int) $stepRaw;
            $part = trim($part);
        }

        if ($part === '*') {
            $from = $min;
            $to = $max;
        } elseif (str_contains($part, '-')) {
            [$fromRaw, $toRaw] = explode('-', $part, 2);
            $from = cron_field_value($fromRaw, $min, $max, $names);
            $to = cron_field_value($toRaw, $min, $max, $names);
        } else {
            $from = cron_field_value($part, $min, $max, $names);
            // Vixie cron reads "5/15" as "5-max/15"; a bare "5" is just 5.
            $to = $step > 1 ? $max : $from;
        }

        if ($from === null || $to === null || $from > $to) {
            return null;
        }

        for ($value = $from; $value <= $to; $value += $step) {
            $set[$value] = true;
        }
    }

    return $set === [] ? null : $set;
}

/**
 * Resolves one numeric or named field value, bounds-checked.
 *
 * @param array<string,int> $names
 */
function cron_field_value(string $raw, int $min, int $max, array $names): ?int
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }

    if (ctype_digit($raw)) {
        $value = (int) $raw;
    } else {
        $value = $names[strtolower($raw)] ?? null;
        if ($value === null) {
            return null;
        }
    }

    return ($value >= $min && $value <= $max) ? $value : null;
}

/**
 * Next occurrence strictly after $after, as a Unix epoch.
 *
 * $timezone is the zone the *scheduler* runs in (the host's zone, or CRON_TZ),
 * not the portal display zone; backup.sh reports it alongside the expression.
 * Returns null when the expression is unsupported or never fires within the
 * horizon (for example "0 0 30 2 *").
 */
function cron_schedule_next(string $expression, int $after, string $timezone = 'UTC'): ?int
{
    $parsed = cron_schedule_parse($expression);
    if ($parsed === null) {
        return null;
    }

    try {
        $tz = new DateTimeZone($timezone === '' ? 'UTC' : $timezone);
    } catch (Throwable) {
        $tz = new DateTimeZone('UTC');
    }

    $cursor = (new DateTimeImmutable('@' . $after))->setTimezone($tz);
    // Truncate to the minute, then step past the current one: "strictly after".
    $cursor = $cursor->setTime((int) $cursor->format('G'), (int) $cursor->format('i'))->modify('+1 minute');
    $limit = $after + VIRTUSPHERE_CRON_HORIZON_DAYS * 86400;

    while ($cursor->getTimestamp() <= $limit) {
        if (!isset($parsed['month'][(int) $cursor->format('n')])) {
            $cursor = $cursor->modify('first day of next month')->setTime(0, 0);
            continue;
        }

        if (!cron_day_matches($parsed, (int) $cursor->format('j'), (int) $cursor->format('w'))) {
            $cursor = $cursor->modify('+1 day')->setTime(0, 0);
            continue;
        }

        if (!isset($parsed['hour'][(int) $cursor->format('G')])
            || !isset($parsed['minute'][(int) $cursor->format('i')])) {
            $cursor = $cursor->modify('+1 minute');
            continue;
        }

        return $cursor->getTimestamp();
    }

    return null;
}

/**
 * Cron's day rule: with both day fields restricted the day matches when either
 * one does; with one restricted only that one counts.
 *
 * @param array{dom:array<int,bool>,dow:array<int,bool>,dom_restricted:bool,dow_restricted:bool} $parsed
 */
function cron_day_matches(array $parsed, int $dayOfMonth, int $dayOfWeek): bool
{
    $domOk = isset($parsed['dom'][$dayOfMonth]);
    $dowOk = isset($parsed['dow'][$dayOfWeek]);

    if ($parsed['dom_restricted'] && $parsed['dow_restricted']) {
        return $domOk || $dowOk;
    }
    if ($parsed['dom_restricted']) {
        return $domOk;
    }
    if ($parsed['dow_restricted']) {
        return $dowOk;
    }

    return true;
}
