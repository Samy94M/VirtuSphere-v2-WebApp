<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/forms.php';

/**
 * form_remember() stashes the posted values of a failed form in the session,
 * minus VIRTUSPHERE_FORM_SENSITIVE_FIELDS (QL-05: a failed directory save kept
 * bind_password in plaintext). This guard derives the name of every
 * type="password" input under lib/ and portal/ and fails on any name that is not
 * an exact member of that list, so a new password field cannot be forgotten.
 *
 * Membership is exact on purpose. A substring rule would also drop
 * password_min_length, a setting whose value must survive a validation error.
 */
final class FormSensitiveFieldsContractTest extends TestCase
{
    private function webapiRoot(): string
    {
        return str_replace('\\', '/', dirname(__DIR__, 2));
    }

    /**
     * PHP source of lib/ and portal/, keyed by its path relative to Docker/WebAPI,
     * with every PHP block replaced by one marker byte so an echoed attribute can
     * neither end a tag early nor pass as a literal name.
     *
     * @return array<string, string>
     */
    private function maskedSources(): array
    {
        $root = $this->webapiRoot();
        $out = [];
        foreach (['lib', 'portal'] as $dir) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }
                $path = str_replace('\\', '/', $file->getPathname());
                $source = (string) file_get_contents($path);
                $out[substr($path, strlen($root) + 1)] = (string) preg_replace('/<\?(?:php|=)?.*?\?>/s', "\x01", $source);
            }
        }
        ksort($out);

        return $out;
    }

    public function testEveryPasswordInputNameIsAnExactSensitiveField(): void
    {
        $sources = $this->maskedSources();
        self::assertNotEmpty($sources, 'No lib/ or portal/ PHP files were scanned; the paths moved.');

        $names = [];
        $inputCount = 0;
        foreach ($sources as $relative => $masked) {
            preg_match_all('/<input\b[^>]*>/i', $masked, $tags);
            $passwordTags = array_values(array_filter(
                $tags[0],
                static fn (string $tag): bool => preg_match('/\btype=(["\'])password\1/i', $tag) === 1,
            ));
            $inputCount += count($passwordTags);

            // A type="password" outside an <input> tag (a textarea, a string built in
            // PHP) would be invisible to the tag scan, so every occurrence must be one.
            $occurrences = preg_match_all('/\btype=(["\'])password\1/i', $masked);
            self::assertSame(
                $occurrences,
                count($passwordTags),
                $relative . ' has a type="password" that is not an <input> tag; this guard cannot derive its name.',
            );

            foreach ($passwordTags as $tag) {
                self::assertSame(
                    1,
                    preg_match('/\sname="([^"]*)"/', $tag, $match),
                    $relative . ' has a password input without a name: ' . $tag,
                );
                self::assertStringNotContainsString(
                    "\x01",
                    $match[1],
                    $relative . ' has a password input whose name is computed in PHP; the name must be a literal.',
                );
                $names[$match[1]][] = $relative;
            }
        }

        self::assertGreaterThan(0, $inputCount, 'No type="password" input found; the scan matches nothing.');

        foreach ($names as $name => $where) {
            self::assertTrue(
                in_array($name, VIRTUSPHERE_FORM_SENSITIVE_FIELDS, true),
                sprintf(
                    'Password input "%s" (%s) is missing from VIRTUSPHERE_FORM_SENSITIVE_FIELDS in lib/forms.php.',
                    $name,
                    implode(', ', array_unique($where)),
                ),
            );
        }
    }

    public function testThePasswordMinLengthSettingIsNotASensitiveField(): void
    {
        // Read at run time: the constant's literal value would let the analyser
        // decide the assertion statically, which is exactly what it must not do.
        $sensitive = (array) constant('VIRTUSPHERE_FORM_SENSITIVE_FIELDS');
        self::assertNotContains(
            'password_min_length',
            $sensitive,
            'password_min_length is a setting; it must keep its value after a validation error.',
        );
    }
}
