<?php

declare(strict_types=1);

/**
 * The pure rules of the Ansible host key pin (UX-HK): how a typed or pasted
 * value is read, which OpenSSH key type it belongs to, and whether a
 * confirmation deviates from the observed or the pinned key.
 *
 * No I/O and no database. The repository runs ssh_host_key_confirmation_plan()
 * inside its locked transaction, and the unit tests call the same functions.
 *
 * Trust rule (ADR-0045): the pin is only ever set from a value the administrator
 * read on the Ubuntu host. The portal's own observation never sets it. It only
 * decides the key type of a value that matches it, and whether the change needs
 * a second, explicit step.
 */

/** The OpenSSH names a key type typed in advance may take: the form's select. */
const SSH_HOST_KEY_PINNABLE_TYPES = [
    'ssh-ed25519',
    'ecdsa-sha2-nistp256',
    'ecdsa-sha2-nistp384',
    'ecdsa-sha2-nistp521',
    'ssh-rsa',
];

/** The anchor of one Ansible credential's identity row on credentials.php. */
function credential_host_identity_anchor(int $credentialId): string
{
    return 'host-identity-' . $credentialId;
}

/** The link to that row. System status and the test result point here (HK-5). */
function credential_host_identity_url(int $credentialId): string
{
    return 'credentials.php#' . credential_host_identity_anchor($credentialId);
}

/**
 * The public host key file and the copy-ready command per key family. ECDSA is
 * one file for all three curves.
 *
 * @return list<array{family: string, file: string, command: string}>
 */
function ssh_host_key_commands(): array
{
    $files = ['ed25519' => 'ssh_host_ed25519_key.pub', 'ecdsa' => 'ssh_host_ecdsa_key.pub', 'rsa' => 'ssh_host_rsa_key.pub'];
    $commands = [];
    foreach ($files as $family => $file) {
        $commands[] = [
            'family' => $family,
            'file' => $file,
            'command' => 'ssh-keygen -l -E sha256 -f /etc/ssh/' . $file,
        ];
    }

    return $commands;
}

/** The key family of an OpenSSH host key type name, or null for a type without a command. */
function ssh_host_key_family(string $type): ?string
{
    return match (true) {
        $type === 'ssh-ed25519' => 'ed25519',
        str_starts_with($type, 'ecdsa-sha2-nistp') => 'ecdsa',
        $type === 'ssh-rsa' => 'rsa',
        default => null,
    };
}

/** SHA256:<43 base64 characters> that decodes to the 32-byte digest it names. */
function ssh_host_key_fingerprint_valid(string $fingerprint): bool
{
    if (preg_match('/^SHA256:[A-Za-z0-9+\/]{43}$/D', $fingerprint) !== 1) {
        return false;
    }
    $digest = base64_decode(substr($fingerprint, 7) . '=', true);

    return $digest !== false && strlen($digest) === 32
        && hash_equals($fingerprint, 'SHA256:' . rtrim(base64_encode($digest), '='));
}

/**
 * The OpenSSH name for the short type ssh-keygen prints in brackets, from its
 * bit count. Null for a type the portal does not accept (DSA, security keys).
 */
function ssh_host_key_type_from_keygen(string $shortType, int $bits): ?string
{
    return match ($shortType) {
        'ED25519' => $bits === 256 ? 'ssh-ed25519' : null,
        'ECDSA' => in_array($bits, [256, 384, 521], true) ? 'ecdsa-sha2-nistp' . $bits : null,
        'RSA' => $bits > 0 ? 'ssh-rsa' : null,
        default => null,
    };
}

/**
 * Reads what the administrator typed or pasted: the bare SHA256 fingerprint, or a
 * whole line of `ssh-keygen -l -E sha256`, for example
 * "256 SHA256:... root@host (ED25519)". The comment is optional and may hold
 * spaces. Surrounding and inner whitespace is free.
 *
 * @return array{fingerprint: string, type: ?string}|null null when the text is neither form
 */
function ssh_host_key_parse_input(string $input): ?array
{
    $text = trim($input);
    if (preg_match('/^SHA256:[A-Za-z0-9+\/]{43}$/D', $text) === 1) {
        return ssh_host_key_fingerprint_valid($text) ? ['fingerprint' => $text, 'type' => null] : null;
    }
    if (preg_match('/^(\d{1,5})\s+(SHA256:[A-Za-z0-9+\/]{43})(?:\s+.*?)?\s+\(([A-Z0-9-]{1,16})\)$/D', $text, $match) !== 1) {
        return null;
    }
    if (!ssh_host_key_fingerprint_valid($match[2])) {
        return null;
    }
    $type = ssh_host_key_type_from_keygen($match[3], (int) $match[1]);

    return $type === null ? null : ['fingerprint' => $match[2], 'type' => $type];
}

/**
 * Decides one confirmation from the posted value, the form's type select and the
 * identities the repository read under its row lock.
 *
 * The type is taken from the line, or from the observed or pinned key the value
 * matches. The select is only needed for a value that matches neither and names
 * no type. A value that differs from the anchor (the pin, or the observation while
 * nothing is pinned) is reported as deviating, so the caller can ask once more.
 *
 * @param array{fingerprint: string, type: string}|null $observed the last identity the portal's own connection saw
 * @param array{fingerprint: string, type: string}|null $pinned the identity that currently gates logins
 * @return array{ok: false, error: string}|array{ok: true, fingerprint: string, type: string, deviates: bool}
 */
function ssh_host_key_confirmation_plan(string $input, string $selectedType, ?array $observed, ?array $pinned): array
{
    $parsed = ssh_host_key_parse_input($input);
    if ($parsed === null) {
        return ['ok' => false, 'error' => 'invalid_fingerprint'];
    }
    $fingerprint = $parsed['fingerprint'];

    $known = $parsed['type'] === null ? [] : [$parsed['type']];
    foreach ([$pinned, $observed] as $identity) {
        if ($identity !== null && $identity['type'] !== '' && hash_equals($identity['fingerprint'], $fingerprint)) {
            $known[] = $identity['type'];
            break;
        }
    }
    $known = array_values(array_unique($known));
    if (count($known) > 1) {
        return ['ok' => false, 'error' => 'type_conflict'];
    }

    $selected = trim($selectedType);
    if ($known !== []) {
        if ($selected !== '' && $selected !== $known[0]) {
            return ['ok' => false, 'error' => 'type_conflict'];
        }
        $type = $known[0];
    } elseif ($selected !== '') {
        if (!in_array($selected, SSH_HOST_KEY_PINNABLE_TYPES, true)) {
            return ['ok' => false, 'error' => 'type_unknown'];
        }
        $type = $selected;
    } elseif ($observed !== null || $pinned !== null) {
        return ['ok' => false, 'error' => 'needs_line'];
    } else {
        return ['ok' => false, 'error' => 'type_needed'];
    }

    if ($type === '') {
        return ['ok' => false, 'error' => 'type_needed'];
    }

    $anchor = $pinned ?? $observed;
    $deviates = $anchor !== null
        && (!hash_equals($anchor['fingerprint'], $fingerprint) || $anchor['type'] !== $type);

    return ['ok' => true, 'fingerprint' => $fingerprint, 'type' => $type, 'deviates' => $deviates];
}
