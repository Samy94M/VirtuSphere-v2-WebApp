<?php

declare(strict_types=1);

/**
 * Audits the VirtuSphere portal language catalog for DE/EN key parity,
 * placeholder parity (a `:placeholder` missing in one locale silently drops
 * the interpolated value there, .claude/rules/i18n.md), the quote form (i18n
 * R11/R12), the sentence dash (R8), German replacement spellings (R14) and
 * written-out MECM terms instead of `{mecm:<name>}` placeholders (R15, PR-R3M).
 *
 * CLI contract:
 *   php scripts/lang-audit.php          Full report, exit 1 on findings.
 *   php scripts/lang-audit.php --ci     CI-style output, exit 1 on findings.
 *   php scripts/lang-audit.php --quiet  Only output on failure.
 *   php scripts/lang-audit.php --help   Usage.
 *
 * Tests may override the catalog root with VIRTUSPHERE_LANG_BASE (or point
 * VIRTUSPHERE_CHECK_ROOT at a fixture repo root, guard harness convention) and
 * the MECM term sets with VIRTUSPHERE_MECM_TERMS_BASE (default: lib/mecm_terms
 * next to the catalog root).
 * Finding lines carry stable [lang-audit.*] IDs as the diagnostic contract.
 */

$mode = 'full';
$args = array_slice(array_values((array) ($_SERVER['argv'] ?? [])), 1);
foreach ($args as $arg) {
    switch ($arg) {
        case '--ci':
            $mode = 'ci';
            break;
        case '--quiet':
        case '-q':
            $mode = 'quiet';
            break;
        case '--help':
        case '-h':
            fwrite(STDOUT, "Usage: php scripts/lang-audit.php [--ci|--quiet|-q|--help|-h]\n");
            exit(0);
        default:
            fwrite(STDERR, "Unknown option: {$arg}\n");
            exit(2);
    }
}

$envBase = getenv('VIRTUSPHERE_LANG_BASE');
$envRoot = getenv('VIRTUSPHERE_CHECK_ROOT');
$root = is_string($envRoot) && $envRoot !== '' ? $envRoot : dirname(__DIR__);
$base = is_string($envBase) && $envBase !== ''
    ? $envBase
    : $root . DIRECTORY_SEPARATOR . 'Docker' . DIRECTORY_SEPARATOR . 'WebAPI' . DIRECTORY_SEPARATOR . 'lang';

$locales = ['de', 'en'];
$catalog = [];
$modules = [];
$fileErrors = [];

if (is_dir($base)) {
    foreach ($locales as $locale) {
        $dir = $base . DIRECTORY_SEPARATOR . $locale;
        if (!is_dir($dir)) {
            continue;
        }

        $files = glob($dir . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($files);
        foreach ($files as $file) {
            $module = basename($file, '.php');
            $modules[$module] = true;

            $data = require $file;
            if (!is_array($data)) {
                $fileErrors[] = $file . ' did not return an array.';
                continue;
            }

            foreach ($data as $key => $value) {
                if (!is_string($key)) {
                    $fileErrors[] = $file . ' contains a non-string key.';
                    continue;
                }
                $catalog[$locale][$module][$key] = is_scalar($value) || $value === null ? (string) $value : '[non-scalar]';
            }
        }
    }
}

ksort($modules);

$gaps = [];
foreach (array_keys($modules) as $module) {
    $keysByLocale = [];
    foreach ($locales as $locale) {
        $keysByLocale[$locale] = array_keys($catalog[$locale][$module] ?? []);
    }

    $allKeys = array_values(array_unique(array_merge(...array_values($keysByLocale))));
    sort($allKeys);
    foreach ($allKeys as $key) {
        $present = [];
        $missing = [];
        foreach ($locales as $locale) {
            if (in_array($key, $keysByLocale[$locale], true)) {
                $present[] = $locale;
            } else {
                $missing[] = $locale;
            }
        }

        if ($missing !== []) {
            $gaps[] = [
                'key' => $module . '.' . $key,
                'present' => $present,
                'missing' => $missing,
            ];
        }
    }
}

/**
 * Placeholder parity: for a key present in both locales, the set of
 * `:placeholder` tokens must match, or one locale silently drops the value.
 *
 * @var array<int, array{key: string, detail: string}> $placeholderDrifts
 */
$placeholderDrifts = [];
$placeholdersOf = static function (string $text): array {
    // MECM term placeholders {mecm:<name>} are filled by Lang itself; their
    // DE/EN parity is the term parity rule below ([lang-audit.mecm-term-drift]),
    // which ignores the case of the first letter.
    $text = (string) preg_replace('/\{mecm:[^}]*\}/', '', $text);
    preg_match_all('/:([a-z][a-z0-9_]*)/', $text, $matches);
    $tokens = array_values(array_unique($matches[1]));
    sort($tokens);
    return $tokens;
};
foreach (array_keys($modules) as $module) {
    $deKeys = $catalog['de'][$module] ?? [];
    $enKeys = $catalog['en'][$module] ?? [];
    foreach ($deKeys as $key => $deText) {
        if (!array_key_exists($key, $enKeys)) {
            continue;
        }
        $dePlaceholders = $placeholdersOf($deText);
        $enPlaceholders = $placeholdersOf($enKeys[$key]);
        if ($dePlaceholders !== $enPlaceholders) {
            $placeholderDrifts[] = [
                'key' => $module . '.' . $key,
                'detail' => 'de=[' . implode(',', $dePlaceholders) . '] en=[' . implode(',', $enPlaceholders) . ']',
            ];
        }
    }
}

/**
 * German quote form (i18n R11, Welle A2): a DE value quotes with the German pair
 * U+201E ... U+201C only. A straight ASCII quote or an English closing U+201D is a
 * finding, and so is a pair that is not closed, closed twice or opened twice. Text
 * inside <code>...</code> and <pre>...</pre> is a literal and is not checked. The
 * values come from the `require` above, so PHP comments never reach this check.
 *
 * @var array<int, array{key: string, detail: string}> $quoteMixes
 */
$quoteMixes = [];
$quoteProblemOf = static function (string $text): ?string {
    $text = (string) preg_replace('~<code\b[^>]*>.*?</code>|<pre\b[^>]*>.*?</pre>~su', '', $text);
    if (str_contains($text, '"')) {
        return 'straight ASCII quote, use the German pair U+201E/U+201C';
    }
    if (str_contains($text, "\u{201D}")) {
        return 'English closing quote U+201D, close with U+201C';
    }
    $open = false;
    foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
        if ($character === "\u{201E}") {
            if ($open) {
                return 'U+201E opened again before the previous pair was closed';
            }
            $open = true;
        } elseif ($character === "\u{201C}") {
            if (!$open) {
                return 'U+201C closes without an opening U+201E';
            }
            $open = false;
        }
    }

    return $open ? 'U+201E is never closed with U+201C' : null;
};
/**
 * English quote form (i18n R12): an EN value quotes with the straight ASCII pair
 * "..." and writes the apostrophe as a straight ', so a quoted literal the portal
 * or the job log prints reads the same as the value itself. Any typographic,
 * German or French mark is a finding, and so is an odd number of straight quotes
 * (a pair that is never closed). Same literal exemption as the German check.
 */
$quoteProblemOfEnglish = static function (string $text): ?string {
    $text = (string) preg_replace('~<code\b[^>]*>.*?</code>|<pre\b[^>]*>.*?</pre>~su', '', $text);
    $marks = [
        "\u{201C}" => '201C', "\u{201D}" => '201D', "\u{2018}" => '2018', "\u{2019}" => '2019',
        "\u{201E}" => '201E', "\u{201A}" => '201A', "\u{00AB}" => '00AB', "\u{00BB}" => '00BB',
    ];
    foreach ($marks as $mark => $codepoint) {
        if (str_contains($text, $mark)) {
            return 'en: typographic quote mark U+' . $codepoint . ', use the straight form " or \'';
        }
    }

    return substr_count($text, '"') % 2 === 1 ? 'en: straight quote " is never closed' : null;
};
$quoteChecks = ['de' => $quoteProblemOf, 'en' => $quoteProblemOfEnglish];
foreach ($quoteChecks as $locale => $check) {
    foreach ($catalog[$locale] ?? [] as $module => $entries) {
        foreach ($entries as $key => $text) {
            $problem = $check($text);
            if ($problem !== null) {
                $quoteMixes[] = ['key' => $module . '.' . $key, 'detail' => $problem];
            }
        }
    }
}

/**
 * Sentence dash (i18n R8): portal prose in DE and EN uses a
 * colon, semicolon, comma or a new sentence instead of a dash. U+2014 is a
 * finding anywhere, U+2013 everywhere except directly between two digits (a
 * range such as 1-5 written with U+2013). A value that is only the dash is an empty-cell
 * placeholder, not punctuation. Same literal exemption as the quote checks.
 *
 * @var array<int, array{key: string, detail: string}> $sentenceDashes
 */
$sentenceDashes = [];
$withoutLiterals = static fn (string $text): string => (string) preg_replace('~<code\b[^>]*>.*?</code>|<pre\b[^>]*>.*?</pre>~su', '', $text);
$dashProblemOf = static function (string $text) use ($withoutLiterals): ?string {
    if (in_array(trim($text), ["\u{2014}", "\u{2013}"], true)) {
        return null;
    }
    $text = $withoutLiterals($text);
    if (str_contains($text, "\u{2014}")) {
        return 'em dash U+2014 as sentence punctuation';
    }
    if (preg_match('/(?<!\d)\x{2013}|\x{2013}(?!\d)/u', $text) === 1) {
        return 'en dash U+2013 between words (allowed only in a digit range)';
    }

    return null;
};
foreach ($locales as $locale) {
    foreach ($catalog[$locale] ?? [] as $module => $entries) {
        foreach ($entries as $key => $text) {
            $problem = $dashProblemOf($text);
            if ($problem !== null) {
                $sentenceDashes[] = ['key' => $module . '.' . $key, 'detail' => $locale . ': ' . $problem];
            }
        }
    }
}

/**
 * Real umlauts in DE (i18n R14): a German value writes real umlauts. The list holds
 * stems of typical replacement spellings; each matches anywhere in a word,
 * case-insensitive, except `fuer`, which matches only as a whole word. Every
 * stem is chosen so that no real word contains it (aktuell, neue, Quelle,
 * Queue, Steuer, erneut stay clean; the harness case lang-audit.umlaut-clean
 * proves it). A word that starts with `ue` or `Ue` before a lower-case letter
 * is a finding as well (uebrig, Uebersicht): no German or English word starts
 * that way, and the case-sensitive form leaves an acronym such as UEFI alone.
 * Literals in <code>/<pre> are exempt.
 */
$umlautStems = [
    'ueber', 'koenn', 'muess', 'waehl', 'loesch', 'groess', 'schluessel', 'haeufig', 'spaeter',
    'aender', 'pruef', 'oeffn', 'zurueck', 'moeglich', 'naechst', 'wuerd', 'fuehr', 'gueltig',
    'verfueg', 'benoetig', 'erklaer', 'waehrend', 'hinzufueg', 'eintraeg', 'geraet', 'schliess',
];
$umlautPattern = '/\bfuer\b|(?-i:(?<!\p{L})[Uu]e\p{Ll}+)|' . implode('|', array_map(static fn (string $stem): string => preg_quote($stem, '/'), $umlautStems)) . '/iu';
/** @var array<int, array{key: string, detail: string}> $umlautSpellings */
$umlautSpellings = [];
foreach ($catalog['de'] ?? [] as $module => $entries) {
    foreach ($entries as $key => $text) {
        if (preg_match($umlautPattern, $withoutLiterals($text), $match) === 1) {
            $umlautSpellings[] = ['key' => $module . '.' . $key, 'detail' => 'de: replacement spelling "' . $match[0] . '", write the real umlaut or sharp s'];
        }
    }
}

/**
 * MECM terms (i18n R15, PR-R3M, PR-R3MP): the MECM object names (Task Sequence,
 * Distribution Point, Site, SMS Provider, Collection, Deployment, Application,
 * Deployment Type) and console paths come only from the term set of the
 * environment's console language, as `{mecm:<name>}`.
 *
 * The word list is derived from the term sets themselves (Docker/WebAPI/lib/
 * mecm_terms/<language>.php): every form of every set, matched as a whole word,
 * case-insensitive, with space and hyphen treated alike ("SMS Provider" also
 * finds "SMS-Provider"). A catalog value that writes one of them out is
 * `[lang-audit.mecm-term-literal]`, unless the exemption file
 * (scripts/lang-audit-mecm-exemptions.php) names the phrase, the key or the
 * module with a reason, or lists the key as not yet converted. Literals in
 * <code>/<pre> are exempt as in R11.
 *
 * Also: `[lang-audit.mecm-term-drift]` when DE and EN of a key name different
 * term placeholders (first-letter case ignored, deliberate cases in
 * `term_parity`), `[lang-audit.mecm-term-unknown]` for a placeholder no set defines,
 * `[lang-audit.mecm-terms]` for a missing default set, a malformed entry or
 * sets whose names differ, and `[lang-audit.mecm-term-stale]` for an exemption
 * or pending key that no longer matches anything, so the lists only shrink.
 *
 * @var array{set: list<string>, unknown: list<string>, literal: list<string>, drift: list<string>, stale: list<string>} $mecmTermFindings
 */
$mecmTermFindings = ['set' => [], 'unknown' => [], 'literal' => [], 'drift' => [], 'stale' => []];
$envTermsBase = getenv('VIRTUSPHERE_MECM_TERMS_BASE');
$termsBase = is_string($envTermsBase) && $envTermsBase !== ''
    ? $envTermsBase
    : dirname($base) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'mecm_terms';
$termSets = [];
foreach (glob($termsBase . DIRECTORY_SEPARATOR . '*.php') ?: [] as $termFile) {
    $language = basename($termFile, '.php');
    $data = require $termFile;
    if (!is_array($data) || $data === []) {
        $mecmTermFindings['set'][] = $language . ': term set did not return a non-empty array';
        continue;
    }
    foreach ($data as $name => $forms) {
        if (!is_string($name) || preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
            $mecmTermFindings['set'][] = $language . ': name ' . var_export($name, true) . ' is not lower snake case';
            continue;
        }
        foreach ($locales as $locale) {
            if (!is_array($forms) || !isset($forms[$locale]) || !is_string($forms[$locale]) || trim($forms[$locale]) === '') {
                $mecmTermFindings['set'][] = $language . ': ' . $name . ' has no ' . $locale . ' form';
                continue 2;
            }
        }
        $termSets[$language][$name] = $forms;
    }
}
ksort($termSets);
if (!isset($termSets['de'])) {
    $mecmTermFindings['set'][] = 'no default term set de.php under ' . $termsBase;
}
$termNames = [];
foreach ($termSets as $language => $terms) {
    $termNames[$language] = array_keys($terms);
    sort($termNames[$language]);
}
$referenceNames = $termNames['de'] ?? (reset($termNames) ?: []);
foreach ($termNames as $language => $names) {
    if ($names !== $referenceNames) {
        $mecmTermFindings['set'][] = $language . ': names differ from de: missing=[' . implode(',', array_diff($referenceNames, $names))
            . '] extra=[' . implode(',', array_diff($names, $referenceNames)) . ']';
    }
}

/** A phrase as a whole-word, case-insensitive pattern with space and hyphen alike. */
$mecmPhrasePattern = static function (string $phrase): string {
    $parts = preg_split('/[\s\-]+/u', trim($phrase), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $quoted = array_map(static fn (string $part): string => preg_quote($part, '/'), $parts);

    return '(?<![\p{L}\p{N}])' . implode('[\s\x{00A0}\-]+', $quoted) . '(?![\p{L}\p{N}])';
};
$mecmWords = [];
foreach ($termSets as $terms) {
    foreach ($terms as $forms) {
        foreach ($locales as $locale) {
            $mecmWords[mb_strtolower($forms[$locale])] = $forms[$locale];
        }
    }
}
uksort($mecmWords, static fn (string $a, string $b): int => (mb_strlen($b) <=> mb_strlen($a)) ?: strcmp($a, $b));
$mecmWordPattern = $mecmWords === []
    ? null
    : '/' . implode('|', array_map($mecmPhrasePattern, array_values($mecmWords))) . '/iu';

$mecmExemptionFile = __DIR__ . DIRECTORY_SEPARATOR . 'lang-audit-mecm-exemptions.php';
$mecmExemptions = is_file($mecmExemptionFile) ? require $mecmExemptionFile : null;
if (!is_array($mecmExemptions)) {
    $mecmTermFindings['set'][] = 'exemption file ' . $mecmExemptionFile . ' missing or not an array';
    $mecmExemptions = [];
}
/** @var array<string,string> $exemptPhrases */
$exemptPhrases = (array) ($mecmExemptions['phrases'] ?? []);
/** @var array<string,string> $exemptKeys locale:module.key => reason */
$exemptKeys = (array) ($mecmExemptions['keys'] ?? []);
/** @var array<string,string> $exemptModules */
$exemptModules = (array) ($mecmExemptions['modules'] ?? []);
/** @var list<string> $pendingKeys module.key, both locales */
$pendingKeys = array_values((array) ($mecmExemptions['pending'] ?? []));
$pendingLookup = array_fill_keys($pendingKeys, true);

$phraseUsed = array_fill_keys(array_keys($exemptPhrases), false);
$keyUsed = array_fill_keys(array_keys($exemptKeys), false);
$moduleUsed = array_fill_keys(array_keys($exemptModules), false);
$pendingUsed = array_fill_keys($pendingKeys, false);
$knownNames = array_fill_keys($referenceNames, true);
foreach ($locales as $locale) {
    foreach ($catalog[$locale] ?? [] as $module => $entries) {
        foreach ($entries as $key => $text) {
            $id = $module . '.' . $key;
            preg_match_all('/\{mecm:([^}]*)\}|\{mecm:(?![^}]*\})/', $text, $placeholderMatches, PREG_SET_ORDER);
            foreach ($placeholderMatches as $placeholder) {
                $name = lcfirst((string) ($placeholder[1] ?? ''));
                if (!isset($knownNames[$name])) {
                    $mecmTermFindings['unknown'][] = $id . ' ' . $locale . ': ' . $placeholder[0] . ' is not in the term sets';
                }
            }
            if ($mecmWordPattern === null) {
                continue;
            }
            $plain = (string) preg_replace('/\{mecm:[^}]*\}/', ' ', $withoutLiterals($text));
            foreach (array_keys($exemptPhrases) as $phrase) {
                $phrasePattern = '/' . $mecmPhrasePattern((string) $phrase) . '/iu';
                if (preg_match($phrasePattern, $plain) === 1) {
                    $phraseUsed[$phrase] = true;
                    $plain = (string) preg_replace($phrasePattern, ' ', $plain);
                }
            }
            if (preg_match($mecmWordPattern, $plain, $wordMatch) !== 1) {
                continue;
            }
            if (isset($exemptModules[$module])) {
                $moduleUsed[$module] = true;
            } elseif (isset($exemptKeys[$locale . ':' . $id])) {
                $keyUsed[$locale . ':' . $id] = true;
            } elseif (isset($pendingLookup[$id])) {
                $pendingUsed[$id] = true;
            } else {
                $mecmTermFindings['literal'][] = $id . ' ' . $locale . ': "' . $wordMatch[0] . '" written out, use {mecm:<name>} from the term set';
            }
        }
    }
}
/*
 * Term parity: a key present in both locales names the same term placeholders in
 * DE and EN, compared without the case of the first letter (sentence start and
 * running text are one term). A placeholder in one locale only would show the
 * MECM word in one language and drop it in the other, like a :placeholder drift.
 * A deliberate difference stands in `term_parity` of the exemption file.
 */
$termParityExempt = (array) ($mecmExemptions['term_parity'] ?? []);
$termParityUsed = array_fill_keys(array_keys($termParityExempt), false);
$termNamesOf = static function (string $text): array {
    preg_match_all('/\{mecm:([^}]*)\}/', $text, $matches);
    $names = array_values(array_unique(array_map('lcfirst', $matches[1])));
    sort($names);

    return $names;
};
foreach (array_keys($modules) as $module) {
    foreach ($catalog['de'][$module] ?? [] as $key => $deText) {
        $enText = $catalog['en'][$module][$key] ?? null;
        if ($enText === null) {
            continue;
        }
        $deNames = $termNamesOf($deText);
        $enNames = $termNamesOf($enText);
        if ($deNames === $enNames) {
            continue;
        }
        $id = $module . '.' . $key;
        if (isset($termParityExempt[$id])) {
            $termParityUsed[$id] = true;
            continue;
        }
        $mecmTermFindings['drift'][] = $id . ' de=[' . implode(',', $deNames) . '] en=[' . implode(',', $enNames) . ']';
    }
}
foreach ($termParityUsed as $entry => $hit) {
    if (!$hit) {
        $mecmTermFindings['stale'][] = 'term_parity ' . $entry . ' names the same terms in both locales; remove it from the exemption file';
    }
}

if ($mecmWordPattern !== null) {
    foreach (['phrase' => $phraseUsed, 'key' => $keyUsed, 'module' => $moduleUsed, 'pending key' => $pendingUsed] as $kind => $used) {
        foreach ($used as $entry => $hit) {
            if (!$hit) {
                $mecmTermFindings['stale'][] = $kind . ' ' . $entry . ' matches no MECM term any more; remove it from the exemption file';
            }
        }
    }
}

/**
 * Zero match: an empty catalog is never proof of a clean one. Every rule above
 * walks the values of both locales; with no value in one of them, each would be
 * vacuously green.
 *
 * @var list<string> $zeroMatches
 */
$zeroMatches = [];
foreach ($locales as $locale) {
    $values = 0;
    foreach ($catalog[$locale] ?? [] as $entries) {
        $values += count($entries);
    }
    if ($values === 0) {
        $zeroMatches[] = $locale . ': no catalog value under ' . $base . DIRECTORY_SEPARATOR . $locale . '; the scan would be vacuously green.';
    }
}

$mecmTermIds = ['set' => 'mecm-terms', 'unknown' => 'mecm-term-unknown', 'literal' => 'mecm-term-literal', 'drift' => 'mecm-term-drift', 'stale' => 'mecm-term-stale'];
$mecmTermCount = array_sum(array_map('count', $mecmTermFindings));
$failed = $fileErrors !== [] || $gaps !== [] || $placeholderDrifts !== [] || $quoteMixes !== []
    || $sentenceDashes !== [] || $umlautSpellings !== [] || $zeroMatches !== [] || $mecmTermCount > 0;
$summary = count($fileErrors) . ' file error(s), ' . count($gaps) . ' parity gap(s), ' . count($placeholderDrifts) . ' placeholder drift(s), '
    . count($quoteMixes) . ' quote mix(es), ' . count($sentenceDashes) . ' sentence dash(es), ' . count($umlautSpellings) . ' umlaut spelling(s), '
    . $mecmTermCount . ' MECM term finding(s), ' . count($zeroMatches) . ' zero match(es).';

if ($mode === 'quiet') {
    if ($failed) {
        fwrite(STDERR, 'Lang-Audit: ' . $summary . "\n");
        exit(1);
    }
    exit(0);
}

if ($mode === 'ci') {
    if ($failed) {
        fwrite(STDERR, '::error::Lang-Audit: ' . $summary . "\n");
        foreach ($zeroMatches as $zero) {
            fwrite(STDERR, "  - [lang-audit.zero-match] {$zero}\n");
        }
        foreach ($fileErrors as $error) {
            fwrite(STDERR, "  - [lang-audit.file-error] {$error}\n");
        }
        foreach ($gaps as $gap) {
            fwrite(STDERR, '  - [lang-audit.parity-gap] ' . $gap['key'] . ' present=[' . implode(',', $gap['present']) . '] missing=[' . implode(',', $gap['missing']) . "]\n");
        }
        foreach ($placeholderDrifts as $drift) {
            fwrite(STDERR, '  - [lang-audit.placeholder-drift] ' . $drift['key'] . ' ' . $drift['detail'] . "\n");
        }
        foreach ($quoteMixes as $mix) {
            fwrite(STDERR, '  - [lang-audit.quote-mix] ' . $mix['key'] . ' ' . $mix['detail'] . "\n");
        }
        foreach ($sentenceDashes as $dash) {
            fwrite(STDERR, '  - [lang-audit.sentence-dash] ' . $dash['key'] . ' ' . $dash['detail'] . "\n");
        }
        foreach ($umlautSpellings as $spelling) {
            fwrite(STDERR, '  - [lang-audit.umlaut-spelling] ' . $spelling['key'] . ' ' . $spelling['detail'] . "\n");
        }
        foreach ($mecmTermFindings as $kind => $findings) {
            foreach ($findings as $finding) {
                fwrite(STDERR, '  - [lang-audit.' . $mecmTermIds[$kind] . '] ' . $finding . "\n");
            }
        }
        exit(1);
    }

    fwrite(STDOUT, 'OK: Lang-Audit - DE/EN catalog clean: parity, placeholders, quotes, dashes, umlauts, MECM terms (' . count($modules) . ' module(s), '
        . count($termSets) . " term set(s)).\n");
    exit(0);
}

echo "VirtuSphere Lang-Audit\n";
echo 'Catalog: ' . $base . "\n";
echo 'Locales: ' . implode(', ', $locales) . "\n";
echo 'Modules: ' . count($modules) . "\n";
echo 'File errors: ' . count($fileErrors) . "\n";
foreach ($fileErrors as $error) {
    echo 'ERROR ' . $error . "\n";
}
echo 'Parity gaps: ' . count($gaps) . "\n";
foreach ($gaps as $gap) {
    echo 'GAP [lang-audit.parity-gap] ' . $gap['key'] . ' present=[' . implode(',', $gap['present']) . '] missing=[' . implode(',', $gap['missing']) . "]\n";
}
echo 'Placeholder drifts: ' . count($placeholderDrifts) . "\n";
foreach ($placeholderDrifts as $drift) {
    echo 'DRIFT [lang-audit.placeholder-drift] ' . $drift['key'] . ' ' . $drift['detail'] . "\n";
}
echo 'Quote mixes (de, en): ' . count($quoteMixes) . "\n";
foreach ($quoteMixes as $mix) {
    echo 'QUOTE [lang-audit.quote-mix] ' . $mix['key'] . ' ' . $mix['detail'] . "\n";
}
echo 'Sentence dashes (de, en): ' . count($sentenceDashes) . "\n";
foreach ($sentenceDashes as $dash) {
    echo 'DASH [lang-audit.sentence-dash] ' . $dash['key'] . ' ' . $dash['detail'] . "\n";
}
echo 'Umlaut spellings (de): ' . count($umlautSpellings) . "\n";
foreach ($umlautSpellings as $spelling) {
    echo 'UMLAUT [lang-audit.umlaut-spelling] ' . $spelling['key'] . ' ' . $spelling['detail'] . "\n";
}
echo 'MECM term findings: ' . $mecmTermCount . ' (term sets: ' . implode(', ', array_keys($termSets)) . ")\n";
foreach ($mecmTermFindings as $kind => $findings) {
    foreach ($findings as $finding) {
        echo 'MECM [lang-audit.' . $mecmTermIds[$kind] . '] ' . $finding . "\n";
    }
}
echo 'Zero matches: ' . count($zeroMatches) . "\n";
foreach ($zeroMatches as $zero) {
    echo 'ZERO [lang-audit.zero-match] ' . $zero . "\n";
}

exit($failed ? 1 : 0);