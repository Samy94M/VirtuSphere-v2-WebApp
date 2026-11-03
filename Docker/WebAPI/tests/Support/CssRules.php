<?php

declare(strict_types=1);

/**
 * A minimal reader for the portal stylesheets, shared by the CSS contract tests.
 *
 * It exists because a regex over a whole stylesheet cannot flatten it: `@media
 * (...) {` matches as a selector and swallows the first rule nested inside it,
 * so the guarded declarations of that rule are never seen. The brace walker
 * below descends into at-rules instead and returns every real rule with its
 * selector, wherever it sits.
 *
 * Extracted from ModalAxisContractTest when StatusSpacingContractTest needed the
 * same walk. Two hand-copied parsers would drift, and a CSS guard that reads the
 * file slightly differently from its sibling is a guard that goes quiet on the
 * construct the other one still catches.
 */
final class CssRules
{
    /**
     * The stylesheet basenames in the order lib/layout.php links them.
     *
     * The cascade resolves a tie between two equally specific rules by position,
     * and once the rules live in more than one file the file order *is* the
     * position. Read from the layout rather than listed, so it cannot describe a
     * `<link>` order the page no longer has.
     *
     * @return list<string>
     */
    public static function linkOrder(string $webApiRoot): array
    {
        $layout = (string) file_get_contents(str_replace('\\', '/', $webApiRoot) . '/lib/layout.php');
        preg_match_all('#assets/css/([A-Za-z0-9_-]+\.css)#', $layout, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Every stylesheet under portal/assets/css in cascade order, path => contents.
     *
     * Globbed, never listed: a rule that moves into a new page stylesheet must
     * stay inside the guard that owns it, and a hardcoded filename is how that
     * guard goes silently out (the same trap the portal rules describe for
     * renderer scans). A sheet the layout does not link sorts last; it is dead
     * weight either way, and CssClassContractTest is what notices.
     *
     * @return array<string, string>
     */
    public static function stylesheets(string $webApiRoot): array
    {
        $order = array_flip(self::linkOrder($webApiRoot));
        $paths = array_map(
            static fn (string $path): string => str_replace('\\', '/', $path),
            glob(str_replace('\\', '/', $webApiRoot) . '/portal/assets/css/*.css') ?: []
        );
        usort(
            $paths,
            static fn (string $a, string $b): int
                => ($order[basename($a)] ?? PHP_INT_MAX) <=> ($order[basename($b)] ?? PHP_INT_MAX)
        );

        $sheets = [];
        foreach ($paths as $path) {
            $sheets[$path] = (string) file_get_contents($path);
        }

        return $sheets;
    }

    /**
     * Comments quote the declarations these tests reason about ("fit-content",
     * "text-align: left", a px value a rule no longer uses), so scanning raw
     * would read the prose instead of the rules.
     */
    public static function stripComments(string $css): string
    {
        return preg_replace('#/\*.*?\*/#s', '', $css) ?? $css;
    }

    /**
     * Flatten a stylesheet to selector/body pairs, descending into at-rules.
     *
     * @return list<array{selector: string, body: string}>
     */
    public static function rules(string $css): array
    {
        $rules = [];
        $length = strlen($css);
        $prelude = '';

        for ($i = 0; $i < $length; $i++) {
            if ($css[$i] !== '{') {
                $prelude .= $css[$i];
                continue;
            }

            $depth = 1;
            $body = '';
            for ($i++; $i < $length && $depth > 0; $i++) {
                if ($css[$i] === '{') {
                    $depth++;
                } elseif ($css[$i] === '}') {
                    if (--$depth === 0) {
                        break;
                    }
                }
                $body .= $css[$i];
            }

            $selector = trim(preg_replace('/\s+/', ' ', $prelude) ?? $prelude);
            $prelude = '';

            if (str_starts_with($selector, '@')) {
                // @media/@supports wrap rules; @keyframes wrap `from`/`to` blocks,
                // which carry no selector we guard. Both are safe to descend into.
                foreach (self::rules($body) as $nested) {
                    $rules[] = $nested;
                }
                continue;
            }

            $rules[] = ['selector' => $selector, 'body' => $body];
        }

        return $rules;
    }

    /**
     * The declarations of one rule body.
     *
     * The property pattern accepts the leading double dash and digits of a
     * custom property (`--space-1`), not only plain CSS names: the token scale
     * is declared in a rule body like everything else, and a reader that cannot
     * see it would report an empty :root instead of a missing step.
     *
     * @return array<string, string> property => value
     */
    public static function declarations(string $body): array
    {
        preg_match_all('/(-{0,2}[a-z][-a-z0-9]*)\s*:\s*([^;]+)/i', $body, $matches, PREG_SET_ORDER);

        $declarations = [];
        foreach ($matches as $match) {
            $declarations[strtolower($match[1])] = trim($match[2]);
        }

        return $declarations;
    }
}
