<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * crypto_*_with_key() take the key as an argument, so a caller can seal a
 * credential under any key at all. The APP_KEY rotation (K22) is the one
 * legitimate caller: it seals under the key the configuration is about to hold.
 * Any other caller could seal under a key that is not in the configuration and
 * the credential would be unreadable after the next start.
 *
 * The scan is lexical (token_get_all), not textual: a comment or a string that
 * merely mentions the name is not a call, and a definition is not a call either.
 * The file list is derived from the tree, never written by hand; vendor/ and
 * tests/ are the only exclusions.
 */
final class AppKeyWithKeyCallersContractTest extends TestCase
{
    private const CALL_NAME = '/^crypto_[a-z0-9_]*_with_key$/';

    /** @var list<string> */
    private const ALLOWED_CALLERS = ['lib/app_key_rotate.php', 'lib/crypto.php'];

    public function testOnlyTheRotationToolCallsAnExplicitKeyCrypto(): void
    {
        $scan = $this->scan($this->productionSources(dirname(__DIR__, 2)));

        // A walk that silently broke would find nothing and the caller set would
        // be empty; the absolute floor and the definitions prove the walk worked.
        self::assertGreaterThan(100, $scan['files'], 'the tree walk saw too few PHP files');
        $definedHere = $scan['definitions']['lib/crypto.php'] ?? [];
        self::assertContains('crypto_encrypt_secret_with_key', $definedHere, 'the encrypt definition is not in lib/crypto.php');
        self::assertContains('crypto_decrypt_secret_with_key', $definedHere, 'the decrypt definition is not in lib/crypto.php');

        self::assertSame(self::ALLOWED_CALLERS, $scan['callers'], 'an explicit-key crypto function is called outside the rotation tool');
    }

    public function testScannerCountsCallsAndIgnoresCommentsStringsAndDefinitions(): void
    {
        $scan = $this->scan([
            'lib/call.php' => "<?php\ncrypto_encrypt_secret_with_key(\$p, \$k);\n",
            'lib/namespaced.php' => "<?php\n\\crypto_decrypt_secret_with_key(\$s, \$k);\n",
            'lib/comment.php' => "<?php\n// crypto_encrypt_secret_with_key(\$p, \$k);\n/* crypto_decrypt_secret_with_key(\$s, \$k); */\n",
            'lib/string.php' => "<?php\n\$x = 'crypto_encrypt_secret_with_key';\nfunction_exists('crypto_decrypt_secret_with_key');\n",
            'lib/definition.php' => "<?php\nfunction crypto_encrypt_secret_with_key(string \$p, string \$k): string { return ''; }\n",
        ]);

        self::assertSame(['lib/call.php', 'lib/namespaced.php'], $scan['callers']);
        self::assertSame(['crypto_encrypt_secret_with_key'], $scan['definitions']['lib/definition.php']);
    }

    /**
     * @param array<string, string> $sources relative path => PHP source
     * @return array{files: int, callers: list<string>, definitions: array<string, list<string>>}
     */
    private function scan(array $sources): array
    {
        $callers = [];
        $definitions = [];
        foreach ($sources as $path => $source) {
            $tokens = token_get_all($source);
            $count = count($tokens);
            for ($i = 0; $i < $count; $i++) {
                $token = $tokens[$i];
                if (!is_array($token) || !in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    continue;
                }
                $parts = explode('\\', $token[1]);
                $name = end($parts);
                if (preg_match(self::CALL_NAME, $name) !== 1) {
                    continue;
                }
                $next = $this->significantToken($tokens, $i + 1, 1);
                if ($next !== '(') {
                    continue;
                }
                $previous = $this->significantToken($tokens, $i - 1, -1);
                if (is_array($previous) && $previous[0] === T_FUNCTION) {
                    $definitions[$path][] = $name;
                    continue;
                }
                $callers[$path] = true;
            }
        }

        $callerList = array_keys($callers);
        sort($callerList);

        return ['files' => count($sources), 'callers' => $callerList, 'definitions' => $definitions];
    }

    /**
     * The next token that is neither whitespace nor a comment, in the given direction.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return array{0: int, 1: string, 2: int}|string|null
     */
    private function significantToken(array $tokens, int $index, int $step): array|string|null
    {
        for ($i = $index; $i >= 0 && $i < count($tokens); $i += $step) {
            $token = $tokens[$i];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $token;
        }

        return null;
    }

    /** @return array<string, string> relative path => PHP source, derived from the tree */
    private function productionSources(string $root): array
    {
        $root = str_replace('\\', '/', $root);
        $files = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $current, mixed $key, RecursiveDirectoryIterator $iterator) use ($root): bool {
                if (!$iterator->hasChildren()) {
                    return true;
                }

                return !in_array(str_replace('\\', '/', $current->getPathname()), [$root . '/vendor', $root . '/tests'], true);
            },
        ));

        $sources = [];
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            $sources[substr($path, strlen($root) + 1)] = (string) file_get_contents($path);
        }
        self::assertNotSame([], $sources, 'the tree walk found no PHP file at all');

        return $sources;
    }
}
