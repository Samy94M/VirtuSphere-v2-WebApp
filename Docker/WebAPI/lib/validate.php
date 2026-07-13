<?php

declare(strict_types=1);

require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/mac.php';

/**
 * One DNS label: letter/digit at both ends, hyphens only inside, at most 63
 * characters. SSoT for Validator::fqdn() below and for the `pattern` attribute of
 * every domain input, which must accept exactly what the server accepts.
 *
 * The hyphen is escaped although PCRE would not require it at the end of a
 * character class: an HTML `pattern` is compiled as an ECMAScript regex with the
 * `v` flag, where a literal `-` inside `[...]` is a reserved syntax character and
 * throws "Invalid character class". Chromium then discards the whole pattern, and
 * the field silently stops validating in the browser. PCRE reads `\-` as the same
 * literal, so one string serves both engines.
 */
const VIRTUSPHERE_DNS_LABEL_PATTERN = '[A-Za-z0-9](?:[A-Za-z0-9\-]{0,61}[A-Za-z0-9])?';

/** FQDN with at least one dot, mirroring the label loop in Validator::fqdn(). */
const VIRTUSPHERE_FQDN_INPUT_PATTERN = VIRTUSPHERE_DNS_LABEL_PATTERN . '(?:\.' . VIRTUSPHERE_DNS_LABEL_PATTERN . ')+';

function validator_text(string $key, string $fallback, array $replace = []): string
{
    $text = function_exists('__t') ? __t($key, $replace) : $key;
    if ($text === $key) {
        $text = $fallback;
        foreach ($replace as $placeholder => $value) {
            $text = str_replace(':' . $placeholder, (string) $value, $text);
        }
    }

    return $text;
}

/**
 * Field label interpolated into validator messages as :field. Outside the portal
 * (machine API, workers) no catalog is loaded, so the English fallback wins and
 * wire-facing diagnostics stay English.
 */
function validator_label(string $field, string $fallback): string
{
    return validator_text('validate.field_' . $field, $fallback);
}

final class ValidationException extends RuntimeException
{
    /** @var array<string, string> */
    private array $errors;

    /**
     * @param array<string, string> $errors
     */
    public function __construct(array $errors, string $message = '')
    {
        parent::__construct($message !== '' ? $message : validator_text('validate.failed', 'Validation failed.'));
        $this->errors = $errors;
    }

    /**
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}

final class Validator
{
    /** @var array<string, string> */
    private array $errors = [];

    public function requireString(string $field, mixed $value, string $label, int $maxLength = 255): string
    {
        $value = $this->optionalString($field, $value, $label, $maxLength);
        if ($value === '') {
            $this->add($field, validator_text('validate.required', ':field is required.', ['field' => $label]));
        }

        return $value;
    }

    public function optionalString(string $field, mixed $value, string $label, int $maxLength = 255): string
    {
        $value = trim((string) ($value ?? ''));
        // Characters, not bytes: VARCHAR(n) under utf8mb4 holds n characters, and
        // mission_transfer.php measures the same limits with mb_strlen.
        if (mb_strlen($value) > $maxLength) {
            $this->add($field, validator_text('validate.max_length', ':field must be at most :max characters long.', ['field' => $label, 'max' => $maxLength]));
        }

        return $value;
    }

    public function intRange(string $field, mixed $value, string $label, int $min, int $max, ?int $default = null): int
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '' && $default !== null) {
            return $default;
        }

        if ($raw === '' || filter_var($raw, FILTER_VALIDATE_INT) === false) {
            $this->add($field, validator_text('validate.integer', ':field must be an integer.', ['field' => $label]));
            return $default ?? $min;
        }

        $int = (int) $raw;
        if ($int < $min || $int > $max) {
            $this->add($field, validator_text('validate.range', ':field must be between :min and :max.', ['field' => $label, 'min' => $min, 'max' => $max]));
        }

        return $int;
    }

    public function optionalIntRange(string $field, mixed $value, string $label, int $min, int $max): ?int
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }

        if (filter_var($raw, FILTER_VALIDATE_INT) === false) {
            $this->add($field, validator_text('validate.integer', ':field must be an integer.', ['field' => $label]));
            return null;
        }

        $int = (int) $raw;
        if ($int < $min || $int > $max) {
            $this->add($field, validator_text('validate.range', ':field must be between :min and :max.', ['field' => $label, 'min' => $min, 'max' => $max]));
        }

        return $int;
    }

    public function hostname(string $field, mixed $value, string $label, int $maxLength = 255, bool $required = false): string
    {
        $value = $required
            ? $this->requireString($field, $value, $label, $maxLength)
            : $this->optionalString($field, $value, $label, $maxLength);

        if ($value !== '' && preg_match('/^' . VIRTUSPHERE_DNS_LABEL_PATTERN . '(?:\.' . VIRTUSPHERE_DNS_LABEL_PATTERN . ')*$/', $value) !== 1) {
            $this->add($field, validator_text('validate.hostname', ':field must be a DNS name: dot-separated labels of letters and numbers, hyphens only inside a label.', ['field' => $label]));
        }

        return $value;
    }

    // Windows/NetBIOS-safe computer name: max 15 chars, no dots, no edge
    // hyphens. Anything looser gets silently truncated/sanitized by the MECM
    // hostname phase on the client (plan stage E2).
    public function netbiosHostname(string $field, mixed $value, string $label, bool $required = false): string
    {
        $value = $required
            ? $this->requireString($field, $value, $label, 15)
            : $this->optionalString($field, $value, $label, 15);

        if ($value !== '' && preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,13}[A-Za-z0-9])?$/', $value) !== 1) {
            $this->add($field, validator_text('validate.netbios_hostname', ':field must be a Windows computer name: at most 15 characters, only letters, numbers and internal hyphens, no dots.', ['field' => $label]));
        }

        return $value;
    }

    public function fqdn(string $field, mixed $value, string $label, bool $required = false): string
    {
        $value = $required
            ? $this->requireString($field, $value, $label, 255)
            : $this->optionalString($field, $value, $label, 255);

        if ($value === '') {
            return $value;
        }

        $labels = explode('.', $value);
        $valid = count($labels) >= 2;
        foreach ($labels as $part) {
            if (
                $part === ''
                || strlen($part) > 63
                || preg_match('/^' . VIRTUSPHERE_DNS_LABEL_PATTERN . '$/', $part) !== 1
            ) {
                $valid = false;
                break;
            }
        }

        if (!$valid) {
            $this->add($field, validator_text('validate.fqdn', ':field must be a DNS FQDN with at least one dot, for example corp.example.local.', ['field' => $label]));
        }

        return $value;
    }

    public function ipv4(string $field, mixed $value, string $label, bool $required = false): string
    {
        $value = $required
            ? $this->requireString($field, $value, $label, 45)
            : $this->optionalString($field, $value, $label, 45);

        if ($value !== '' && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            $this->add($field, validator_text('validate.ipv4', ':field must be a valid IPv4 address.', ['field' => $label]));
        }

        return $value;
    }

    public function ipv4OrCidrMask(string $field, mixed $value, string $label, bool $required = false): string
    {
        $value = $required
            ? $this->requireString($field, $value, $label, 45)
            : $this->optionalString($field, $value, $label, 45);

        if (
            $value !== ''
            && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            && preg_match('/^\/(?:[0-9]|[12][0-9]|30)$/', $value) !== 1
        ) {
            $this->add($field, validator_text('validate.ipv4_or_cidr', ':field must be an IPv4 subnet mask or CIDR mask from /0 to /30.', ['field' => $label]));
        }

        return $value;
    }

    /**
     * Returns the canonical MAC (uppercase, colon-separated), like enum() returns
     * the canonical enum value: every stored MAC is then in the one format the
     * exact-match lookups use.
     *
     * MECM, mecm_report and db_importMAC all resolve a VM through
     * `WHERE mac = ?` after running the incoming address through
     * virtusphere_normalize_mac(). Stored raw, the hyphen form Windows prints
     * (`ipconfig /all`) and the Cisco dotted form never match that query, so a
     * MAC typed into the portal in either notation makes the VM unresolvable for
     * MECM, and the duplicate guard in db_importMAC stops seeing it. The
     * case-insensitive collation only hides the upper/lower variant.
     * Canonicalizing here keeps migration 0008 from being re-drifted by the very
     * writer it was meant to repair; the accepted set is unchanged.
     */
    public function mac(string $field, mixed $value, string $label, bool $required = false): string
    {
        $value = $required
            ? $this->requireString($field, $value, $label, 32)
            : $this->optionalString($field, $value, $label, 32);

        if ($value === '') {
            return $value;
        }

        if (filter_var($value, FILTER_VALIDATE_MAC) === false) {
            $this->add($field, validator_text('validate.mac', ':field must be a valid MAC address.', ['field' => $label]));
            return $value;
        }

        return virtusphere_normalize_mac($value) ?? $value;
    }

    /**
     * @param list<string> $allowed
     */
    public function enum(string $field, mixed $value, string $label, array $allowed, string $default = ''): string
    {
        $value = strtolower(trim((string) ($value ?? '')));
        if ($value === '' && $default !== '') {
            $value = $default;
        }

        if ($value === '' || !in_array($value, $allowed, true)) {
            $this->add($field, validator_text('validate.enum', ':field has an invalid value.', ['field' => $label]));
        }

        return $value;
    }

    public function add(string $field, string $message): void
    {
        $this->errors[$field] = $message;
    }

    public function throwIfInvalid(): void
    {
        if ($this->errors !== []) {
            throw new ValidationException($this->errors, reset($this->errors) ?: validator_text('validate.failed', 'Validation failed.'));
        }
    }
}
