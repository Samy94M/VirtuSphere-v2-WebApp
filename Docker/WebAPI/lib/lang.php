<?php

declare(strict_types=1);

/**
 * Portal i18n (ADR-0014).
 *
 * Loads the DE/EN string catalog from lib/../lang/{locale}/*.php and exposes
 * translations through Lang::get() / the global __t('module.key') helper.
 *
 * - German ('de') is the default locale, English ('en') the second locale.
 * - Locale is display-only. It must never drive auth, RBAC, deploy decisions,
 *   status transitions or machine API wire contracts (see .claude/rules/i18n.md).
 * - Missing keys return the key itself, so gaps stay visible during development
 *   instead of silently falling back to unrelated prose.
 *
 * Usage:
 *   __t('portal.invalid_request')            -> "Ungueltige Abmeldeanfrage."
 *   __t('portal.locked', ['minutes' => 5])   -> placeholder :minutes is replaced
 *   Lang::locale()                           -> "de"
 */
final class Lang
{
    public const DEFAULT_LOCALE = 'de';

    /** @var array<int, string> Supported UI locales; DE first (default), EN second. */
    public const LOCALES = ['de', 'en'];

    /** @var array<string, string> Flat "module.key" => text for the active locale. */
    private static array $strings = [];

    private static string $locale = self::DEFAULT_LOCALE;

    private static bool $loaded = false;

    /** @var array<string, array<string, string>> Per-locale catalog cache. */
    private static array $catalogCache = [];

    /**
     * Loads the catalog for a locale. Unknown locales fall back to the default.
     * Called once from the portal bootstrap after the session is started.
     */
    public static function load(string $locale): void
    {
        if (!in_array($locale, self::LOCALES, true)) {
            $locale = self::DEFAULT_LOCALE;
        }
        self::$locale = $locale;
        self::$strings = self::catalog($locale);
        self::$loaded = true;
    }

    /**
     * Loads and caches the flat key catalog for a locale.
     *
     * @return array<string, string>
     */
    private static function catalog(string $locale): array
    {
        if (isset(self::$catalogCache[$locale])) {
            return self::$catalogCache[$locale];
        }

        $strings = [];
        $dir = __DIR__ . '/../lang/' . $locale;
        $files = glob($dir . '/*.php') ?: [];
        sort($files); // deterministic, alphabetical
        foreach ($files as $file) {
            $data = require $file;
            if (!is_array($data)) {
                error_log("Language file {$file} did not return an array.");
                continue;
            }
            $module = basename($file, '.php');
            foreach ($data as $key => $value) {
                if (is_string($key)) {
                    $strings["{$module}.{$key}"] = is_string($value) ? $value : '';
                }
            }
        }

        self::$catalogCache[$locale] = $strings;
        return $strings;
    }

    /**
     * Returns the translated text for a dotted "module.key".
     * Missing keys return the key itself as a visible gap marker.
     *
     * @param array<string, string|int> $replace Placeholders, e.g. ['name' => 'x'] replaces :name
     */
    public static function get(string $key, array $replace = []): string
    {
        $text = self::$strings[$key] ?? $key;
        foreach ($replace as $placeholder => $value) {
            $text = str_replace(':' . $placeholder, (string) $value, $text);
        }
        return $text;
    }

    /** Active locale, e.g. "de". */
    public static function locale(): string
    {
        return self::$locale;
    }

    public static function isLoaded(): bool
    {
        return self::$loaded;
    }
}

/**
 * Short form for Lang::get(). Safe to call before Lang::load() runs: it simply
 * returns the key (visible gap marker) rather than fataling.
 *
 * @param array<string, string|int> $replace
 */
function __t(string $key, array $replace = []): string
{
    return Lang::get($key, $replace);
}

/**
 * Resolves the effective locale for this request.
 *
 * Precedence:
 *   1. ?lang=de|en|auto (explicit switch; persisted in the session)
 *   2. previously persisted session choice
 *   3. Accept-Language against the allowlist ('auto' mode)
 *   4. default locale ('de')
 *
 * Locale is display-only and must not be reused for any security or wire
 * decision. Kept as a pure-ish resolver so it stays predictable and testable.
 */
function __locale_resolve(): string
{
    $mode = null;

    $requested = $_GET['lang'] ?? null;
    if (is_string($requested)) {
        $requested = strtolower(trim($requested));
        if ($requested === 'auto' || in_array($requested, Lang::LOCALES, true)) {
            $mode = $requested;
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['locale_mode'] = $mode;
            }
        }
    }

    if ($mode === null && session_status() === PHP_SESSION_ACTIVE) {
        $stored = $_SESSION['locale_mode'] ?? null;
        if (is_string($stored) && ($stored === 'auto' || in_array($stored, Lang::LOCALES, true))) {
            $mode = $stored;
        }
    }

    if ($mode === null || $mode === 'auto') {
        $accept = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null;
        return __locale_resolve_auto(is_string($accept) ? $accept : null, Lang::LOCALES, Lang::DEFAULT_LOCALE);
    }

    return in_array($mode, Lang::LOCALES, true) ? $mode : Lang::DEFAULT_LOCALE;
}

/**
 * RFC 4647 lookup: q-sort the Accept-Language header, reduce each tag to its
 * primary subtag and return the first that is on the allowlist, else default.
 * Pure function -> deterministic and unit-testable.
 *
 * @param array<int, string> $allow
 */
function __locale_resolve_auto(?string $accept, array $allow, string $default): string
{
    if ($accept === null || $accept === '') {
        return $default;
    }

    $items = [];
    foreach (explode(',', $accept) as $order => $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        $tag = $part;
        $q = 1.0;
        if (stripos($part, ';q=') !== false) {
            [$tag, $qstr] = explode(';q=', $part, 2);
            $q = (float) $qstr;
        }
        $tag = strtolower(trim($tag));
        if ($tag === '' || $tag === '*') {
            continue;
        }
        $items[] = ['tag' => $tag, 'q' => $q, 'order' => $order];
    }

    usort($items, static fn (array $a, array $b): int => ($b['q'] <=> $a['q']) ?: ($a['order'] <=> $b['order']));

    foreach ($items as $item) {
        $primary = explode('-', $item['tag'])[0];
        if (in_array($primary, $allow, true)) {
            return $primary;
        }
    }

    return $default;
}
