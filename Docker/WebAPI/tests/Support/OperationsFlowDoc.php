<?php

declare(strict_types=1);

/**
 * Shared string checks for the flow documents under docs/operations (FC4).
 *
 * Every function is pure over strings, so each guard that uses it can prove its
 * negative cases without file IO. The rules mirror docs/operations/flows.md:
 * the node limit comes from its `flow-node-limit` comment and the only allowed
 * classDef lines are the ones of its legend.
 */
final class OperationsFlowDoc
{
    /** Declares a node: `id["text"]`, `id[text]`, `id{"text"}` or `id{text}`. */
    private const NODE_PATTERN = '/(?<![A-Za-z0-9_])([a-z][A-Za-z0-9_]*)(\[|\{)"?([^\]\}\n"]*)"?(\]|\})/';

    public static function repoRoot(): string
    {
        return dirname(__DIR__, 4);
    }

    /** The document with LF line ends, or null when docs/ is not mounted. */
    public static function load(string $relative): ?string
    {
        $root = self::repoRoot();
        if (!is_dir($root . '/docs')) {
            return null;
        }
        $path = $root . '/' . $relative;
        if (!is_file($path)) {
            return '';
        }

        return str_replace("\r\n", "\n", (string) file_get_contents($path));
    }

    /** Body of the `## <heading>` section, '' when there is none. */
    public static function section(string $doc, string $heading): string
    {
        $parts = preg_split('/^## /m', $doc) ?: [];
        array_shift($parts);
        foreach ($parts as $part) {
            $newline = strpos($part, "\n");
            $title = trim($newline === false ? $part : substr($part, 0, $newline));
            if ($title === $heading) {
                return $newline === false ? '' : substr($part, $newline + 1);
            }
        }

        return '';
    }

    /** @return list<string> the body of every mermaid fence of the text */
    public static function mermaidBlocks(string $text): array
    {
        preg_match_all('/```mermaid\s*\R(.*?)```/s', $text, $matches);

        return $matches[1];
    }

    /**
     * Rows of every markdown table in the text, header and separator excluded.
     *
     * @return list<list<string>> trimmed cells per row
     */
    public static function tableRows(string $text): array
    {
        $rows = [];
        $header = true;
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (!str_starts_with($line, '|')) {
                $header = true;
                continue;
            }
            if ($header) {
                $header = false;
                continue;
            }
            if (preg_match('/^\|[\s:|-]+\|$/', $line) === 1) {
                continue;
            }
            $rows[] = array_map('trim', explode('|', trim(trim($line), '|')));
        }

        return $rows;
    }

    /** @return list<string> every `backticked` value of a cell */
    public static function ticks(string $cell): array
    {
        preg_match_all('/`([^`]+)`/', $cell, $matches);

        return $matches[1];
    }

    /** @return array<string,list<string>> node id => every text declared for it */
    public static function nodes(string $body): array
    {
        $declared = [];
        preg_match_all(self::NODE_PATTERN, $body, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $declared[$match[1]][] = trim($match[3]);
        }

        return $declared;
    }

    public static function nodeLimit(string $flows): ?int
    {
        return preg_match('/flow-node-limit:\s*(\d+)/', $flows, $match) === 1 ? (int) $match[1] : null;
    }

    /** @return list<string> the classDef lines of the flows.md legend */
    public static function legend(string $flows): array
    {
        preg_match_all('/^classDef [^\r\n]+/m', $flows, $matches);

        return array_map('trim', $matches[0]);
    }

    /**
     * The flows.md rules for one flowchart: a flowchart at all, at most the node
     * limit, classDef lines only from the legend, class lines only with legend
     * classes and declared nodes, edges only between declared nodes.
     *
     * @param list<string> $legend
     * @return list<string>
     */
    public static function flowchartProblems(string $label, string $body, int $nodeLimit, array $legend): array
    {
        $problems = [];
        if (preg_match('/^\s*flowchart\s+(TD|LR)\b/', $body) !== 1) {
            $problems[] = $label . ' is not a flowchart.';
        }
        $declared = self::nodes($body);
        if ($declared === []) {
            $problems[] = $label . ' declares no node (zero-match must not pass).';
        }
        if (count($declared) > $nodeLimit) {
            $problems[] = sprintf('%s has %d nodes, the limit in flows.md is %d.', $label, count($declared), $nodeLimit);
        }
        $legendClasses = [];
        foreach ($legend as $definition) {
            if (preg_match('/^classDef\s+([A-Za-z0-9_]+)/', $definition, $name) === 1) {
                $legendClasses[] = $name[1];
            }
        }
        $undeclared = [];
        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $trimmed = trim($line);
            if (str_starts_with($trimmed, 'classDef ')) {
                if (!in_array($trimmed, $legend, true)) {
                    $problems[] = sprintf('%s defines a classDef outside the flows.md legend: %s', $label, $trimmed);
                }
                continue;
            }
            if (preg_match('/^class\s+([A-Za-z0-9_,]+)\s+([A-Za-z0-9_]+);?$/', $trimmed, $match) === 1) {
                foreach (explode(',', $match[1]) as $id) {
                    if ($id !== '' && !isset($declared[$id])) {
                        $undeclared[$id] = true;
                    }
                }
                if (!in_array($match[2], $legendClasses, true)) {
                    $problems[] = sprintf('%s uses class `%s` outside the flows.md legend.', $label, $match[2]);
                }
                continue;
            }
            if (preg_match('/-->|-\.->|==>/', $line) !== 1) {
                continue;
            }
            $bare = (string) preg_replace(['/\|[^|\n]*\|/', '/(\[|\{)"?[^\]\}\n"]*"?(\]|\})/'], ' ', $line);
            preg_match_all('/(?<![A-Za-z0-9_])[a-z][A-Za-z0-9_]*/', $bare, $ids);
            foreach ($ids[0] as $id) {
                if (!isset($declared[$id])) {
                    $undeclared[$id] = true;
                }
            }
        }
        foreach (array_keys($undeclared) as $id) {
            $problems[] = sprintf('%s uses undeclared node `%s`.', $label, $id);
        }

        return $problems;
    }

    /**
     * Each flowchart of a section through flowchartProblems(), plus the expected
     * number of charts.
     *
     * @param list<string> $legend
     * @return list<string>
     */
    public static function sectionChartProblems(string $label, string $section, int $expectedCharts, int $nodeLimit, array $legend): array
    {
        $blocks = self::mermaidBlocks($section);
        if (count($blocks) !== $expectedCharts) {
            return [sprintf('%s must contain %d mermaid block(s), found %d.', $label, $expectedCharts, count($blocks))];
        }
        $problems = [];
        foreach ($blocks as $index => $block) {
            $problems = array_merge($problems, self::flowchartProblems($label . ($expectedCharts > 1 ? ' #' . ($index + 1) : ''), $block, $nodeLimit, $legend));
        }

        return $problems;
    }

    /**
     * Each value must occur as a whole token in the text.
     *
     * @param list<string> $values
     * @return list<string>
     */
    public static function missingTokens(string $label, array $values, string $text): array
    {
        $problems = [];
        if ($values === []) {
            $problems[] = $label . ' has no values derived from code (zero-match must not pass).';
        }
        foreach ($values as $value) {
            if (preg_match('/(?<![A-Za-z0-9_-])' . preg_quote($value, '/') . '(?![A-Za-z0-9_-])/', $text) !== 1) {
                $problems[] = sprintf('%s `%s` is missing.', $label, $value);
            }
        }

        return $problems;
    }
}
