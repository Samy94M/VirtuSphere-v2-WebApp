<?php

declare(strict_types=1);

/**
 * Portal-wide value formatters. Dependency-free on purpose: helpers here also run
 * from lib code that can be loaded before the portal bootstrap.
 */

/**
 * Human-readable byte size in binary units. Returns '' for null/negative, so a
 * caller can render an empty-value placeholder without a second null check.
 *
 * The decimal separator stays a dot in both locales. The number is a technical
 * size next to unit symbols (GB/TB) that are not translated either, and the ESXi
 * inventory tables have shown it this way since ADR-0023; a locale-dependent
 * comma would make the same datastore read differently on two pages.
 *
 * No thousands separator either. A value can reach 1023.9 in its unit, and the
 * deploy page renders the same number twice: once from here and once from the
 * humanBytes() mirror in deploy.js, which keeps the queue table live. "1,010.0 GB"
 * next to "1010.0 GB" would be the same cell disagreeing with itself.
 */
function virtusphere_human_bytes(?int $bytes): string
{
    if ($bytes === null || $bytes < 0) {
        return '';
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $value = (float) $bytes;
    $unit = 0;
    while ($value >= 1024 && $unit < count($units) - 1) {
        $value /= 1024;
        $unit++;
    }

    return ($unit === 0 ? (string) (int) $value : number_format($value, 1, '.', '')) . ' ' . $units[$unit];
}
