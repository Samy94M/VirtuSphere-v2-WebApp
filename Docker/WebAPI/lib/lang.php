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
 * - MECM terms are placeholders `{mecm:<name>}` filled from the term set of the
 *   environment's MECM console language (lib/mecm_terms/, PR-R3M), independent
 *   of the portal locale and just as display-only.
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
     * MECM console language whose term set fills `{mecm:<name>}` (PR-R3M, i18n
     * R15). Display-only like the locale; without useMecmTerms() (CLI, workers,
     * tests) the default applies, so this class never reads the database.
     */
    public const MECM_CONSOLE_DEFAULT = 'de';

    private static string $mecmConsole = self::MECM_CONSOLE_DEFAULT;

    /** @var array<string, array<string, string>>|null Per-locale `{mecm:...}` => word pairs of the active term set. */
    private static ?array $mecmPairs = null;

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
        return self::fill(self::$strings[$key] ?? $key, $replace, self::$locale);
    }

    /**
     * Text of a key in a given locale, whatever the active one is. For output that
     * must exist in two languages in one request (the audit presenter renders the
     * stored English text and a German display from the same row). An unknown
     * locale falls back to the default, and a missing key returns the key, as get().
     *
     * @param array<string, string|int> $replace
     */
    public static function getIn(string $locale, string $key, array $replace = []): string
    {
        if (!in_array($locale, self::LOCALES, true)) {
            $locale = self::DEFAULT_LOCALE;
        }
        $strings = self::catalog($locale);

        return self::fill($strings[$key] ?? $key, $replace, $locale);
    }

    /**
     * How many keys `<prefix>1`, `<prefix>2`, ... a catalog holds without a gap
     * (active locale, or the given one). For lists numbered in the catalog: the
     * template loops to this count instead of writing it down, so an added item
     * cannot stay unrendered. Before Lang::load() the active count is 0.
     */
    public static function countNumbered(string $prefix, ?string $locale = null): int
    {
        if ($locale !== null && !in_array($locale, self::LOCALES, true)) {
            $locale = self::DEFAULT_LOCALE;
        }
        $strings = $locale === null ? self::$strings : self::catalog($locale);
        $count = 0;
        while (isset($strings[$prefix . ($count + 1)])) {
            $count++;
        }

        return $count;
    }

    /**
     * Fills :placeholders in one pass. strtr() tries the longest key first, so
     * :page cannot eat the start of :pages, and a filled-in value is never
     * searched again for a later placeholder.
     *
     * @param array<string, string|int> $replace
     */
    public static function replace(string $text, array $replace): string
    {
        $pairs = [];
        foreach ($replace as $placeholder => $value) {
            $pairs[':' . $placeholder] = (string) $value;
        }

        return $pairs === [] ? $text : strtr($text, $pairs);
    }

    /**
     * Catalog text as shown in a locale: fills the MECM term placeholders
     * `{mecm:<name>}` from the active term set and the call-site :placeholders
     * in the same single strtr() pass, so a value passed in by a caller (a VM
     * name that happens to read `{mecm:site}`) is never searched again. An
     * unknown term placeholder stays visible (R7). `<locale>` picks the form of
     * the word inside that portal language; an unknown one falls back to the
     * default.
     *
     * @param array<string, string|int> $replace
     */
    public static function fill(string $text, array $replace, string $locale): string
    {
        $pairs = [];
        if (str_contains($text, '{mecm:')) {
            $terms = self::mecmPairs();
            $pairs = $terms[$locale] ?? $terms[self::DEFAULT_LOCALE] ?? [];
        }
        foreach ($replace as $placeholder => $value) {
            $pairs[':' . $placeholder] = (string) $value;
        }

        return $pairs === [] ? $text : strtr($text, $pairs);
    }

    /**
     * Selects the term set of a MECM console language for this request. Called
     * by the portal bootstrap with mecm_console_language(); an unknown language
     * falls back to the default. Display-only: never feed it into auth, RBAC,
     * deploy decisions, status transitions or machine API wire contracts.
     */
    public static function useMecmTerms(string $console): void
    {
        self::$mecmConsole = in_array($console, self::mecmConsoleLanguages(), true) ? $console : self::MECM_CONSOLE_DEFAULT;
        self::$mecmPairs = null;
    }

    /** Active MECM console language, e.g. "de". */
    public static function mecmConsole(): string
    {
        return self::$mecmConsole;
    }

    /**
     * Console languages that have a term set: one file per language in
     * lib/mecm_terms/, so another language is another file and no code. Sorted.
     *
     * @return list<string>
     */
    public static function mecmConsoleLanguages(): array
    {
        static $languages = null;
        if ($languages === null) {
            $languages = [];
            foreach (glob(__DIR__ . '/mecm_terms/*.php') ?: [] as $termSetFile) {
                $languages[] = basename($termSetFile, '.php');
            }
            sort($languages);
        }

        return $languages;
    }

    /**
     * Term set of a console language: name => [portal locale => word]. An
     * unknown language falls back to the default; a broken file yields [].
     *
     * @return array<string, array<string, string>>
     */
    public static function mecmTerms(string $console): array
    {
        if (!in_array($console, self::mecmConsoleLanguages(), true)) {
            $console = self::MECM_CONSOLE_DEFAULT;
        }
        // A glob over the literal directory, so the portal require-closure
        // analysis sees every term set as a static edge.
        $data = null;
        foreach (glob(__DIR__ . '/mecm_terms/*.php') ?: [] as $termFile) {
            if (basename($termFile, '.php') === $console) {
                $data = require $termFile;
            }
        }
        if (!is_array($data)) {
            error_log("MECM term set {$console} did not return an array.");

            return [];
        }
        $terms = [];
        foreach ($data as $name => $forms) {
            if (!is_string($name) || !is_array($forms)) {
                continue;
            }
            foreach ($forms as $locale => $word) {
                if (is_string($locale) && is_string($word)) {
                    $terms[$name][$locale] = $word;
                }
            }
        }

        return $terms;
    }

    /**
     * `{mecm:name}` and `{mecm:Name}` (first letter upper case) per portal
     * locale for the active term set, built once per term set.
     *
     * @return array<string, array<string, string>>
     */
    private static function mecmPairs(): array
    {
        if (self::$mecmPairs !== null) {
            return self::$mecmPairs;
        }
        $pairs = [];
        foreach (self::mecmTerms(self::$mecmConsole) as $name => $forms) {
            foreach ($forms as $locale => $word) {
                $pairs[$locale]['{mecm:' . $name . '}'] = $word;
                $pairs[$locale]['{mecm:' . ucfirst($name) . '}'] = mb_ucfirst($word);
            }
        }

        return self::$mecmPairs = $pairs;
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
