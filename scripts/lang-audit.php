<?php

declare(strict_types=1);

/**
 * Audits the VirtuSphere portal language catalog for DE/EN key parity,
 * placeholder parity (a `:placeholder` missing in one locale silently drops
 * the interpolated value there, .claude/rules/i18n.md), the quote form (i18n
 * R11/R12), the sentence dash (R8) and German replacement spellings (R14).
 *
 * CLI contract:
 *   php scripts/lang-audit.php          Full report, exit 1 on findings.
 *   php scripts/lang-audit.php --ci     CI-style output, exit 1 on findings.
 *   php scripts/lang-audit.php --quiet  Only output on failure.
 *   php scripts/lang-audit.php --help   Usage.
 *
 * Tests may override the catalog root with VIRTUSPHERE_LANG_BASE (or point
 * VIRTUSPHERE_CHECK_ROOT at a fixture repo root, guard harness convention).
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

$failed = $fileErrors !== [] || $gaps !== [] || $placeholderDrifts !== [] || $quoteMixes !== []
    || $sentenceDashes !== [] || $umlautSpellings !== [] || $zeroMatches !== [];
$summary = count($fileErrors) . ' file error(s), ' . count($gaps) . ' parity gap(s), ' . count($placeholderDrifts) . ' placeholder drift(s), '
    . count($quoteMixes) . ' quote mix(es), ' . count($sentenceDashes) . ' sentence dash(es), ' . count($umlautSpellings) . ' umlaut spelling(s), '
    . count($zeroMatches) . ' zero match(es).';

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
        exit(1);
    }

    fwrite(STDOUT, 'OK: Lang-Audit - DE/EN catalog clean: parity, placeholders, quotes, dashes, umlauts (' . count($modules) . " module(s)).\n");
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
echo 'Zero matches: ' . count($zeroMatches) . "\n";
foreach ($zeroMatches as $zero) {
    echo 'ZERO [lang-audit.zero-match] ' . $zero . "\n";
}

exit($failed ? 1 : 0);