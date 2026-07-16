<?php

declare(strict_types=1);

/**
 * Audits the VirtuSphere portal language catalog for DE/EN key parity and
 * placeholder parity (a `:placeholder` missing in one locale silently drops
 * the interpolated value there, .claude/rules/i18n.md).
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

$failed = $fileErrors !== [] || $gaps !== [] || $placeholderDrifts !== [];

if ($mode === 'quiet') {
    if ($failed) {
        fwrite(STDERR, 'Lang-Audit: ' . count($fileErrors) . ' file error(s), ' . count($gaps) . ' parity gap(s), ' . count($placeholderDrifts) . " placeholder drift(s).\n");
        exit(1);
    }
    exit(0);
}

if ($mode === 'ci') {
    if ($failed) {
        fwrite(STDERR, '::error::Lang-Audit: ' . count($fileErrors) . ' file error(s), ' . count($gaps) . ' parity gap(s), ' . count($placeholderDrifts) . " placeholder drift(s).\n");
        foreach ($fileErrors as $error) {
            fwrite(STDERR, "  - [lang-audit.file-error] {$error}\n");
        }
        foreach ($gaps as $gap) {
            fwrite(STDERR, '  - [lang-audit.parity-gap] ' . $gap['key'] . ' present=[' . implode(',', $gap['present']) . '] missing=[' . implode(',', $gap['missing']) . "]\n");
        }
        foreach ($placeholderDrifts as $drift) {
            fwrite(STDERR, '  - [lang-audit.placeholder-drift] ' . $drift['key'] . ' ' . $drift['detail'] . "\n");
        }
        exit(1);
    }

    fwrite(STDOUT, 'OK: Lang-Audit - DE/EN catalog parity clean (' . count($modules) . " module(s)).\n");
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

exit($failed ? 1 : 0);