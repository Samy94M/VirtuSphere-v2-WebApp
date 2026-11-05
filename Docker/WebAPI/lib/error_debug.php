<?php

declare(strict_types=1);

// The debug gate of the error renderers in lib/errors.php, kept on its own so
// the error-handling module stays inside its ADR-0006 size budget. Every
// render path asks only virtusphere_debug_enabled(); nothing else reads the flag.

function virtusphere_debug_enabled(): bool
{
    if (function_exists('envboot_optional')) {
        $value = envboot_optional('VIRTUSPHERE_DEBUG', '0');
    } else {
        $raw = getenv('VIRTUSPHERE_DEBUG');
        $value = $raw === false ? '0' : (string) $raw;
    }

    if (!in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true)) {
        return false;
    }

    // SEC2 (C-13): a debug flag left on in production must not hand stack
    // details to every LAN client. Details go only to a CLI run or a request
    // from the loopback address; everyone else sees the reference id, and the
    // full detail is in logs/error.log as always.
    return PHP_SAPI === 'cli' || virtusphere_is_loopback_address((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
}

function virtusphere_is_loopback_address(string $address): bool
{
    $packed = @inet_pton($address);
    if ($packed === false) {
        return false;
    }
    if (strlen($packed) === 4) {
        return $packed[0] === "\x7f";
    }
    if ($packed === inet_pton('::1')) {
        return true;
    }

    // An IPv4-mapped loopback (::ffff:127.0.0.0/104).
    return str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff\x7f");
}
