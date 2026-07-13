<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The `pattern` attribute of an <input> is compiled by the browser as an
 * ECMAScript regex with the `v` flag, not as PCRE. Under `v` a literal hyphen
 * inside a character class is a reserved syntax character and throws
 * "Invalid character class"; the browser then discards the whole pattern and the
 * field stops validating client-side. Nothing warns: php -l, node --check and the
 * unit suite all stay green, and the server-side validator still catches the bad
 * value on submit, so the only symptom is a console error nobody reads.
 *
 * That is exactly what `[A-Za-z0-9-]` did to both domain fields. This test pins
 * the two halves of the fix: the pattern is escaped, and it is not copied.
 */
final class DomainInputPatternTest extends TestCase
{
    private function root(): string
    {
        return str_replace('\\', '/', dirname(__DIR__, 2));
    }

    /** @return array<string, string> relative path => contents */
    private function portalPages(): array
    {
        $pages = [];
        foreach (glob($this->root() . '/portal/*.php') ?: [] as $path) {
            $pages['portal/' . basename($path)] = (string) file_get_contents($path);
        }
        self::assertNotSame([], $pages, 'no portal pages were scanned');

        return $pages;
    }

    public function testNoPatternAttributeCarriesAnUnescapedHyphenInACharacterClass(): void
    {
        // A `-` directly before `]` or directly after `[` is a literal, and under
        // the `v` flag a literal hyphen must be written `\-`. Ranges (`A-Z`) are
        // untouched by this check.
        $offenders = [];
        foreach ($this->portalPages() as $page => $contents) {
            preg_match_all('/pattern="([^"]*)"/', $contents, $matches);
            foreach ($matches[1] as $pattern) {
                if (preg_match('/\[[^\]]*(?<!\\\\)-\]/', $pattern) === 1 || preg_match('/\[(?<!\\\\)-/', $pattern) === 1) {
                    $offenders[] = $page . ': ' . $pattern;
                }
            }
        }

        self::assertSame([], $offenders, 'an unescaped hyphen in a character class makes Chromium drop the whole pattern');
    }

    public function testTheDomainFieldsRenderTheSharedConstant(): void
    {
        // Two byte-identical copies of a regex in two files are two chances to fix
        // only one of them.
        $offenders = [];
        foreach ($this->portalPages() as $page => $contents) {
            if (preg_match('/pattern="\[A-Za-z0-9\]/', $contents) === 1) {
                $offenders[] = $page;
            }
        }
        self::assertSame([], $offenders, 'domain inputs must echo VIRTUSPHERE_FQDN_INPUT_PATTERN, not a literal copy');

        foreach (['portal/mission_details.php', 'portal/vm_edit.php'] as $page) {
            self::assertStringContainsString(
                'pattern="<?php echo h(VIRTUSPHERE_FQDN_INPUT_PATTERN); ?>"',
                $this->portalPages()[$page],
                $page . ' must render the shared FQDN pattern'
            );
        }
    }

    /**
     * The browser rule and the server rule have to accept the same domains. A
     * pattern that is stricter would reject a value the server stores; a looser
     * one would promise a submit that then fails.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function domains(): array
    {
        return [
            'plain fqdn' => ['corp.example.local', true],
            'two labels' => ['a.b', true],
            'inner hyphens' => ['ab-cd.ef-gh.local', true],
            'digits only' => ['1.2', true],
            'uppercase' => ['A.B', true],
            'leading hyphen' => ['-bad.example.com', false],
            'trailing hyphen' => ['bad-.example.com', false],
            'no dot' => ['nodot', false],
            'empty label' => ['a..b', false],
            'trailing dot' => ['a.b.', false],
            'underscore' => ['a_b.example.com', false],
            'space' => ['a b.example.com', false],
        ];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('domains')]
    public function testTheHtmlPatternAgreesWithTheServerValidator(string $domain, bool $expected): void
    {
        // The HTML pattern is implicitly anchored by the browser.
        $htmlAccepts = preg_match('/^(?:' . VIRTUSPHERE_FQDN_INPUT_PATTERN . ')$/', $domain) === 1;
        self::assertSame($expected, $htmlAccepts, 'HTML pattern verdict for ' . $domain);

        $validator = new Validator();
        $validator->fqdn('domain', $domain, 'Domain', true);
        $serverAccepts = true;
        try {
            $validator->throwIfInvalid();
        } catch (ValidationException) {
            $serverAccepts = false;
        }
        self::assertSame($expected, $serverAccepts, 'server verdict for ' . $domain);
    }
}
