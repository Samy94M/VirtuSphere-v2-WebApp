<?php

declare(strict_types=1);

/**
 * Audits the VirtuSphere portal language catalog for DE/EN key parity.
 *
 * CLI contract:
 *   php scripts/lang-audit.php          Full report, exit 1 on parity gaps.
 *   php scripts/lang-audit.php --ci     CI-style output, exit 1 on parity gaps.
 *   php scripts/lang-audit.php --quiet  Only output on failure.
 *   php scripts/lang-audit.php --help   Usage.
 *
 * Tests may override the catalog root with VIRTUSPHERE_LANG_BASE.
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
$base = is_string($envBase) && $envBase !== ''
    ? $envBase
    : dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Docker' . DIRECTORY_SEPARATOR . 'WebAPI' . DIRECTORY_SEPARATOR . 'lang';

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

$failed = $fileErrors !== [] || $gaps !== [];

if ($mode === 'quiet') {
    if ($failed) {
        fwrite(STDERR, 'Lang-Audit: ' . count($fileErrors) . ' file error(s), ' . count($gaps) . " parity gap(s).\n");
        exit(1);
    }
    exit(0);
}

if ($mode === 'ci') {
    if ($failed) {
        fwrite(STDERR, '::error::Lang-Audit: ' . count($fileErrors) . ' file error(s), ' . count($gaps) . " parity gap(s).\n");
        foreach ($fileErrors as $error) {
            fwrite(STDERR, "  - {$error}\n");
        }
        foreach ($gaps as $gap) {
            fwrite(STDERR, '  - ' . $gap['key'] . ' present=[' . implode(',', $gap['present']) . '] missing=[' . implode(',', $gap['missing']) . "]\n");
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
    echo 'GAP ' . $gap['key'] . ' present=[' . implode(',', $gap['present']) . '] missing=[' . implode(',', $gap['missing']) . "]\n";
}

exit($failed ? 1 : 0);