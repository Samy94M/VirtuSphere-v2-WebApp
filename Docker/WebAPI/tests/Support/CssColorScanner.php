<?php

declare(strict_types=1);

require_once __DIR__ . '/CssRules.php';

/**
 * Finds colours that are spelled out instead of named.
 *
 * The rule this serves is one file wide: `base.css` declares the palette, every
 * other portal stylesheet consumes it through `var(--token)`. A regex over the
 * file cannot enforce that. A hex hides inside a gradient stop, inside a shadow,
 * inside a `var()` fallback, inside a nested function and inside a data URL that
 * only becomes CSS after decoding; meanwhile a token NAME may legitimately read
 * `--danger` and a comment may legitimately quote `#ffffff` while explaining why
 * it is gone. So the value is parsed: strings and comments are removed first,
 * data URLs are decoded and scanned as their own text, and custom property names
 * are taken out before the named-colour pass, which is the one pass a token name
 * could otherwise trip.
 *
 * Allowed everywhere: `var()`, `transparent`, `currentColor`, the CSS-wide
 * keywords, and `color-mix()` whose colour arguments are themselves allowed.
 *
 * System colours are a deliberate exception rather than an oversight. Forbidding
 * them outright would be wrong: under `forced-colors: active` a page that keeps
 * painting its own palette is the accessibility defect, so they are permitted
 * inside `@media (forced-colors: active)` and required to appear in matching
 * pairs, because a `Canvas` background with a foreground the user agent did not
 * choose is how a high-contrast mode ends up unreadable.
 */
final class CssColorScanner
{
    /** Diagnostic ids. Stable: they are quoted in failures and in docs/QA.md. */
    public const ID_HEX = 'color.hex';
    public const ID_FUNCTION = 'color.function';
    public const ID_NAMED = 'color.named';
    public const ID_SYSTEM_OUTSIDE = 'color.system-outside-forced-colors';
    public const ID_SYSTEM_UNPAIRED = 'color.system-unpaired';
    public const ID_DATA_URI = 'color.data-uri';

    /** Colour functions that take channel values rather than a token. */
    private const COLOR_FUNCTIONS = ['rgb', 'rgba', 'hsl', 'hsla', 'hwb', 'lab', 'lch', 'oklab', 'oklch', 'color'];

    /**
     * The CSS named colours. `transparent` and `currentcolor` are deliberately
     * absent: they name no colour of ours and are allowed everywhere.
     */
    private const NAMED_COLORS = [
        'aliceblue', 'antiquewhite', 'aqua', 'aquamarine', 'azure', 'beige', 'bisque', 'black',
        'blanchedalmond', 'blue', 'blueviolet', 'brown', 'burlywood', 'cadetblue', 'chartreuse',
        'chocolate', 'coral', 'cornflowerblue', 'cornsilk', 'crimson', 'cyan', 'darkblue', 'darkcyan',
        'darkgoldenrod', 'darkgray', 'darkgreen', 'darkgrey', 'darkkhaki', 'darkmagenta',
        'darkolivegreen', 'darkorange', 'darkorchid', 'darkred', 'darksalmon', 'darkseagreen',
        'darkslateblue', 'darkslategray', 'darkslategrey', 'darkturquoise', 'darkviolet', 'deeppink',
        'deepskyblue', 'dimgray', 'dimgrey', 'dodgerblue', 'firebrick', 'floralwhite', 'forestgreen',
        'fuchsia', 'gainsboro', 'ghostwhite', 'gold', 'goldenrod', 'gray', 'green', 'greenyellow',
        'grey', 'honeydew', 'hotpink', 'indianred', 'indigo', 'ivory', 'khaki', 'lavender',
        'lavenderblush', 'lawngreen', 'lemonchiffon', 'lightblue', 'lightcoral', 'lightcyan',
        'lightgoldenrodyellow', 'lightgray', 'lightgreen', 'lightgrey', 'lightpink', 'lightsalmon',
        'lightseagreen', 'lightskyblue', 'lightslategray', 'lightslategrey', 'lightsteelblue',
        'lightyellow', 'lime', 'limegreen', 'linen', 'magenta', 'maroon', 'mediumaquamarine',
        'mediumblue', 'mediumorchid', 'mediumpurple', 'mediumseagreen', 'mediumslateblue',
        'mediumspringgreen', 'mediumturquoise', 'mediumvioletred', 'midnightblue', 'mintcream',
        'mistyrose', 'moccasin', 'navajowhite', 'navy', 'oldlace', 'olive', 'olivedrab', 'orange',
        'orangered', 'orchid', 'palegoldenrod', 'palegreen', 'paleturquoise', 'palevioletred',
        'papayawhip', 'peachpuff', 'peru', 'pink', 'plum', 'powderblue', 'purple', 'rebeccapurple',
        'red', 'rosybrown', 'royalblue', 'saddlebrown', 'salmon', 'sandybrown', 'seagreen',
        'seashell', 'sienna', 'silver', 'skyblue', 'slateblue', 'slategray', 'slategrey', 'snow',
        'springgreen', 'steelblue', 'tan', 'teal', 'thistle', 'tomato', 'turquoise', 'violet',
        'wheat', 'white', 'whitesmoke', 'yellow', 'yellowgreen',
    ];

    /** System colour => the foreground the user agent pairs it with. */
    private const SYSTEM_PAIRS = [
        'canvas' => 'canvastext',
        'buttonface' => 'buttontext',
        'field' => 'fieldtext',
        'highlight' => 'highlighttext',
        'selecteditem' => 'selecteditemtext',
        'mark' => 'marktext',
        'accentcolor' => 'accentcolortext',
    ];

    /** Every system colour keyword, background and foreground alike. */
    private const SYSTEM_COLORS = [
        'canvas', 'canvastext', 'linktext', 'visitedtext', 'activetext', 'buttonface', 'buttontext',
        'buttonborder', 'field', 'fieldtext', 'highlight', 'highlighttext', 'selecteditem',
        'selecteditemtext', 'mark', 'marktext', 'graytext', 'accentcolor', 'accentcolortext',
    ];

    /**
     * @return array{findings: list<array{id: string, sheet: string, selector: string, property: string, value: string}>, declarations: int}
     */
    public static function scan(string $css, string $sheet): array
    {
        $findings = [];
        $declarations = 0;

        foreach (self::blocks(CssRules::stripComments($css)) as $block) {
            $forced = str_contains(strtolower($block['context']), 'forced-colors');
            $systemHere = [];

            foreach (self::declarations($block['body']) as [$property, $value]) {
                $declarations++;
                foreach (self::inspect($property, $value, $forced) as $found) {
                    $findings[] = [
                        'id' => $found['id'],
                        'sheet' => $sheet,
                        'selector' => $block['selector'],
                        'property' => $property,
                        'value' => trim($value),
                    ];
                }
                if ($forced) {
                    foreach (self::systemKeywords($value) as $keyword) {
                        $systemHere[$property][] = $keyword;
                    }
                }
            }

            foreach (self::unpairedSystemColors($systemHere) as $keyword) {
                $findings[] = [
                    'id' => self::ID_SYSTEM_UNPAIRED,
                    'sheet' => $sheet,
                    'selector' => $block['selector'],
                    'property' => 'background',
                    'value' => $keyword . ' without its paired ' . self::SYSTEM_PAIRS[$keyword] . ' foreground',
                ];
            }
        }

        return ['findings' => $findings, 'declarations' => $declarations];
    }

    /**
     * Rules with their at-rule context, so a `forced-colors` block can be told
     * from an ordinary one. CssRules::rules() flattens that context away, which
     * is exactly the distinction this guard needs.
     *
     * @return list<array{context: string, selector: string, body: string}>
     */
    private static function blocks(string $css, string $context = ''): array
    {
        $blocks = [];
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

            if (str_starts_with($selector, '@media') || str_starts_with($selector, '@supports')) {
                foreach (self::blocks($body, trim($context . ' ' . $selector)) as $nested) {
                    $blocks[] = $nested;
                }
                continue;
            }

            $blocks[] = ['context' => $context, 'selector' => $selector, 'body' => $body];
        }

        return $blocks;
    }

    /**
     * The declarations of one block, split on structure rather than on a regex.
     *
     * `CssRules::declarations()` reads a value as "everything up to the next
     * semicolon", which is right until a value CONTAINS one:
     * `url(data:image/svg+xml;base64,...)` is cut at the media type, the payload
     * never reaches the decoder, and a hex hidden in a base64 SVG walks straight
     * past the guard. That is the exact evasion this scanner exists to close, so
     * the split respects parentheses and quotes.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function declarations(string $body): array
    {
        $pieces = [];
        $buffer = '';
        $depth = 0;
        $quote = null;
        $length = strlen($body);

        for ($i = 0; $i < $length; $i++) {
            $char = $body[$i];
            if ($quote !== null) {
                $buffer .= $char;
                if ($char === '\\' && $i + 1 < $length) {
                    $buffer .= $body[++$i];
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                $buffer .= $char;
                continue;
            }
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth = max(0, $depth - 1);
            }
            if ($char === ';' && $depth === 0) {
                $pieces[] = $buffer;
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }
        $pieces[] = $buffer;

        $declarations = [];
        foreach ($pieces as $piece) {
            $colon = self::topLevelColon($piece);
            if ($colon === null) {
                continue;
            }
            $property = strtolower(trim(substr($piece, 0, $colon)));
            $value = trim(substr($piece, $colon + 1));
            if ($value === '' || preg_match('/\A-{0,2}[a-z][-a-z0-9]*\z/', $property) !== 1) {
                continue;
            }
            $declarations[] = [$property, $value];
        }

        return $declarations;
    }

    /** Offset of the property/value colon, ignoring colons inside parentheses. */
    private static function topLevelColon(string $piece): ?int
    {
        $depth = 0;
        $length = strlen($piece);
        for ($i = 0; $i < $length; $i++) {
            if ($piece[$i] === '(') {
                $depth++;
            } elseif ($piece[$i] === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($piece[$i] === ':' && $depth === 0) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @return list<array{id: string}>
     */
    private static function inspect(string $property, string $value, bool $forced): array
    {
        $out = [];

        foreach (self::dataUriPayloads($value) as $payload) {
            if (self::looksLikeColor($payload)) {
                $out[] = ['id' => self::ID_DATA_URI];
            }
        }

        // Strings carry prose and font family names, never a colour this guard
        // owns; url() payloads were already decoded and scanned above, so they
        // come out here rather than being reported a second time as a bare hex.
        $bare = (string) preg_replace('/url\([^)]*\)/i', 'url()', self::withoutStrings($value));

        if (preg_match('/#[0-9a-f]{3,8}\b/i', $bare) === 1) {
            $out[] = ['id' => self::ID_HEX];
        }
        foreach (self::COLOR_FUNCTIONS as $function) {
            if (preg_match('/\b' . $function . '\s*\(/i', $bare) === 1) {
                $out[] = ['id' => self::ID_FUNCTION];
                break;
            }
        }

        // Custom property names come out before the word pass: `--danger` and
        // `--surface-muted` are token names, and a guard that reads them as
        // colour words would forbid the very tokens it exists to require.
        $words = preg_replace('/--[a-z0-9-]+/i', ' ', $bare) ?? $bare;
        foreach (array_unique(preg_split('/[^a-z]+/i', strtolower($words)) ?: []) as $word) {
            if ($word === '') {
                continue;
            }
            if (in_array($word, self::NAMED_COLORS, true)) {
                $out[] = ['id' => self::ID_NAMED];
            } elseif (in_array($word, self::SYSTEM_COLORS, true) && !$forced) {
                $out[] = ['id' => self::ID_SYSTEM_OUTSIDE];
            }
        }

        return $out;
    }

    /** The system colour keywords one value names. @return list<string> */
    public static function systemKeywords(string $value): array
    {
        $bare = preg_replace('/--[a-z0-9-]+/i', ' ', self::withoutStrings($value)) ?? $value;
        $out = [];
        foreach (preg_split('/[^a-z]+/i', strtolower($bare)) ?: [] as $word) {
            if ($word !== '' && in_array($word, self::SYSTEM_COLORS, true)) {
                $out[] = $word;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * System backgrounds in one rule whose paired foreground the rule does not set.
     *
     * @param array<string, list<string>> $byProperty
     * @return list<string>
     */
    public static function unpairedSystemColors(array $byProperty): array
    {
        $foregrounds = [];
        foreach (['color', '-webkit-text-fill-color'] as $property) {
            foreach ($byProperty[$property] ?? [] as $keyword) {
                $foregrounds[$keyword] = true;
            }
        }

        $out = [];
        foreach (['background', 'background-color'] as $property) {
            foreach ($byProperty[$property] ?? [] as $keyword) {
                if (isset(self::SYSTEM_PAIRS[$keyword]) && !isset($foregrounds[self::SYSTEM_PAIRS[$keyword]])) {
                    $out[] = $keyword;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /** Decoded payloads of every data: URL in the value. @return list<string> */
    private static function dataUriPayloads(string $value): array
    {
        preg_match_all('/url\(\s*["\']?\s*(data:[^)"\']+)/i', $value, $matches);
        $out = [];
        foreach ($matches[1] as $uri) {
            $comma = strpos($uri, ',');
            if ($comma === false) {
                continue;
            }
            $meta = substr($uri, 0, $comma);
            $payload = substr($uri, $comma + 1);
            $out[] = str_contains(strtolower($meta), 'base64')
                ? (string) base64_decode($payload, true)
                : rawurldecode($payload);
        }

        return $out;
    }

    /** Whether a decoded payload spells a colour out. */
    private static function looksLikeColor(string $text): bool
    {
        if (preg_match('/#[0-9a-f]{3,8}\b/i', $text) === 1) {
            return true;
        }
        foreach (self::COLOR_FUNCTIONS as $function) {
            if (preg_match('/\b' . $function . '\s*\(/i', $text) === 1) {
                return true;
            }
        }
        foreach (preg_split('/[^a-z]+/i', strtolower($text)) ?: [] as $word) {
            if ($word !== '' && in_array($word, self::NAMED_COLORS, true)) {
                return true;
            }
        }

        return false;
    }

    /** The value with quoted strings blanked out, quotes kept so nothing merges. */
    private static function withoutStrings(string $value): string
    {
        return (string) preg_replace('/([\'"])(?:\\\\.|(?!\1).)*\1/s', '""', $value);
    }
}
