<?php

declare(strict_types=1);

/**
 * Portal timezone handling (Paket B / ADR-0022).
 *
 * The database stores and compares everything in UTC (db() pins the session to
 * +00:00). This module converts UTC values to the configured *display* timezone
 * at render time only. The timezone is display-only and must never drive auth,
 * RBAC, deploy decisions or wire contracts (.claude/rules/i18n.md).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/repo/settings.php';

/**
 * Configured portal display timezone (cached per request). Falls back to the
 * default when unset or invalid, so a bad setting can never fatal a page.
 */
function portal_timezone(): string
{
    static $tz = null;
    if ($tz !== null) {
        return $tz;
    }

    $tz = VIRTUSPHERE_PORTAL_TIMEZONE_DEFAULT;
    try {
        $value = repo_setting_value(db(), VIRTUSPHERE_SETTING_PORTAL_TIMEZONE, VIRTUSPHERE_PORTAL_TIMEZONE_DEFAULT);
        if ($value !== '' && portal_timezone_is_valid($value)) {
            $tz = $value;
        }
    } catch (Throwable) {
        // Keep the default; display must survive a DB hiccup.
    }

    return $tz;
}

function portal_timezone_is_valid(string $tz): bool
{
    return in_array($tz, DateTimeZone::listIdentifiers(), true);
}

/**
 * Formats a UTC database timestamp string ("Y-m-d H:i:s") in the portal
 * timezone. Empty input yields an empty string; unparsable input is returned
 * verbatim rather than throwing.
 */
function portal_format_datetime(?string $utc): string
{
    if ($utc === null || trim($utc) === '') {
        return '';
    }
    try {
        $dt = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    } catch (Throwable) {
        return (string) $utc;
    }

    return $dt->setTimezone(new DateTimeZone(portal_timezone()))->format('d.m.Y H:i:s');
}

/**
 * Formats a Unix epoch in the portal timezone.
 */
function portal_format_epoch(int $epoch): string
{
    return (new DateTimeImmutable('@' . $epoch))
        ->setTimezone(new DateTimeZone(portal_timezone()))
        ->format('d.m.Y H:i:s');
}

/**
 * Current server time rendered in the portal timezone, with the short offset,
 * for the settings "time" card (e.g. "09.07.2026 08:54:32 (Europe/Berlin, +02:00)").
 */
function portal_now_label(): string
{
    $now = new DateTimeImmutable('now', new DateTimeZone(portal_timezone()));

    return $now->format('d.m.Y H:i:s') . ' (' . portal_timezone() . ', ' . $now->format('P') . ')';
}

/**
 * Curated timezone options for the settings select: a DACH-plus-UTC "common"
 * group first, then one well-known city per UTC offset worldwide. Saving still
 * accepts every IANA identifier (portal_timezone_is_valid()); when the
 * configured timezone is outside the short list it is appended under "other"
 * so the stored value never disappears from the form. That includes a stored
 * value that is not a valid identifier at all (a hand-edited row, an identifier
 * a tz-database update dropped): hiding it would make the browser mark the
 * first option as selected, and the next save would silently overwrite the
 * misconfiguration instead of surfacing it. Rendered as its raw id, it is
 * visible, and saving it back fails validation with a message.
 * Returns [groupKey => [identifier, ...]]; group keys map to the
 * settings.timezone_group_* catalog entries.
 */
function portal_timezone_choices(string $current): array
{
    $groups = [
        'common' => ['Europe/Berlin', 'Europe/Vienna', 'Europe/Zurich', 'UTC'],
        'america' => ['America/Los_Angeles', 'America/Denver', 'America/Chicago', 'America/New_York', 'America/Sao_Paulo'],
        'europe_africa' => ['Europe/London', 'Europe/Athens', 'Europe/Moscow', 'Africa/Cairo'],
        'asia_pacific' => ['Asia/Dubai', 'Asia/Kolkata', 'Asia/Bangkok', 'Asia/Shanghai', 'Asia/Tokyo', 'Australia/Sydney', 'Pacific/Auckland'],
    ];

    if ($current !== '' && !in_array($current, array_merge(...array_values($groups)), true)) {
        $groups['other'] = [$current];
    }

    return $groups;
}

/**
 * Option label for one identifier: localized city name from the
 * settings.timezone_city_* catalog plus the current UTC offset, e.g.
 * "Wien (UTC+02:00)". Identifiers without a catalog entry (a stored exotic
 * timezone) fall back to the IANA-derived place name, e.g.
 * "Buenos Aires, Argentina (UTC-03:00)". The offset reflects DST at render
 * time and is display context only.
 */
function portal_timezone_option_label(string $id): string
{
    try {
        $offset = (new DateTimeImmutable('now', new DateTimeZone($id)))->format('P');
    } catch (Throwable) {
        return $id;
    }

    $key = 'settings.timezone_city_' . strtolower(str_replace(['/', '-'], '_', $id));
    $place = function_exists('__t') ? __t($key) : $key;
    if ($place === $key) {
        $slash = strpos($id, '/');
        $place = str_replace('_', ' ', $slash === false ? $id : substr($id, $slash + 1));
        $parts = explode('/', $place);
        if (count($parts) > 1) {
            $place = implode(', ', array_reverse($parts));
        }
    }

    return $place . ' (UTC' . $offset . ')';
}
