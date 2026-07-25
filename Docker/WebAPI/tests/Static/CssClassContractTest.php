<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Every class the portal renders must have a rule somewhere in assets/css.
 *
 * Nothing else notices when markup and stylesheet drift apart, and the drift is
 * silent in the direction that hurts: a renamed block keeps rendering, just
 * unstyled. That is how `<ul class="ampel-legend">` ended up on two pages with
 * browser-default bullets and how the failure line of a broken integration
 * source rendered as ordinary body text, while the rules of their predecessors
 * sat unused a few hundred lines further down.
 *
 * Only this direction is checked. The other one (a rule nothing renders) needs
 * an exception list for every dynamically composed name, and a guard whose
 * exception list is hand-maintained is the failure mode this file exists to
 * prevent. Dead rules stay a periodic cleanup.
 *
 * A dynamically built name is derived, not listed: `class="phase-step-<?= $s ?>"`
 * and `'badge badge-' . $variant` both leave a static prefix, and the prefix has
 * to match at least one selector. That catches a renamed family without naming
 * its members.
 */
final class CssClassContractTest extends TestCase
{
    /**
     * Classes that deliberately carry no style. Each is a hook a stylesheet must
     * NOT own, and each entry is a decision: prefer a `data-` attribute for a new
     * one, which is what every JS hook in this project already uses.
     *
     * @var array<string, string>
     */
    private const UNSTYLED_HOOKS = [];

    private function repoRoot(): string
    {
        return str_replace('\\', '/', dirname(__DIR__, 2));
    }

    /** @return list<string> */
    private function filesIn(string $dir, string $extension): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $out = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            if (str_ends_with($path, '.' . $extension)) {
                $out[] = $path;
            }
        }
        sort($out);

        return $out;
    }

    /**
     * Every class selector in every stylesheet, lower-cased key set.
     *
     * @return array<string, true>
     */
    private function styledClasses(): array
    {
        $classes = [];
        foreach ($this->filesIn($this->repoRoot() . '/portal/assets/css', 'css') as $file) {
            preg_match_all('/\.([A-Za-z][A-Za-z0-9_-]*)/', (string) file_get_contents($file), $matches);
            foreach ($matches[1] as $class) {
                $classes[$class] = true;
            }
        }

        self::assertNotSame([], $classes, 'no stylesheet was read; the assets path moved');

        return $classes;
    }

    /**
     * Class tokens the markup renders. A token that ends in a PHP/JS expression
     * is returned with a trailing "*", which the assertion reads as a prefix.
     *
     * @return array<string, list<string>> token => the files that render it
     */
    private function renderedClasses(): array
    {
        $found = [];
        $files = array_merge(
            $this->filesIn($this->repoRoot() . '/lib', 'php'),
            $this->filesIn($this->repoRoot() . '/portal', 'php'),
            $this->filesIn($this->repoRoot() . '/portal/assets', 'js'),
        );

        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);
            $short = ltrim(str_replace($this->repoRoot(), '', $file), '/');

            $values = [];
            // class="..." and class='...' in template markup and in echoed strings.
            preg_match_all('/\bclass=(["\'])(.*?)\1/s', $contents, $attributes);
            foreach ($attributes[2] as $value) {
                $values[] = $value;
            }
            // 'badge badge-' . $variant: a class list built outside an attribute.
            preg_match_all('/\bclassList\.(?:add|toggle|remove)\(\s*[\'"]([^\'"]+)[\'"]/', $contents, $jsClasses);
            foreach ($jsClasses[1] as $value) {
                $values[] = $value;
            }

            foreach ($values as $value) {
                // Anything the server or the browser fills in becomes one marker,
                // so a token split around it keeps only its static part. \x01 and
                // not \x00, because trim() strips NUL by default and the marker
                // would vanish from exactly the tokens it has to survive in.
                $masked = (string) preg_replace(['/<\?php.*?\?>/s', '/<\?=.*?\?>/s', '/\$\{.*?\}/s'], "\x01", $value);
                foreach (preg_split('/\s+/', $masked) ?: [] as $token) {
                    $token = trim($token);
                    if ($token === '' || $token === "\x01") {
                        continue;
                    }
                    if (str_contains($token, "\x01")) {
                        // A token whose tail is an echoed expression keeps only
                        // its static head: "phase-step-" for the phase list.
                        $prefix = substr($token, 0, (int) strpos($token, "\x01"));
                        if ($prefix === '' || !preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $prefix)) {
                            continue;
                        }
                        $found[$prefix . '*'][$short] = true;
                        continue;
                    }
                    if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $token)) {
                        continue;
                    }
                    $found[$token][$short] = true;
                }
            }

            // A class list assembled in PHP and concatenated with an expression:
            // portal_badge() builds 'badge badge-' . $variant this way.
            preg_match_all('/\bclass="([A-Za-z][A-Za-z0-9_ -]*)\'\s*\./', $contents, $phpLists);
            foreach ($phpLists[1] as $value) {
                $parts = preg_split('/\s+/', trim($value)) ?: [];
                foreach ($parts as $index => $token) {
                    if ($token === '') {
                        continue;
                    }
                    $isLast = $index === count($parts) - 1;
                    $found[$token . ($isLast ? '*' : '')][$short] = true;
                }
            }
        }

        self::assertNotSame([], $found, 'no markup was scanned; the portal paths moved');

        return array_map(static fn (array $files): array => array_keys($files), $found);
    }

    public function testEveryRenderedClassHasAStylesheetRule(): void
    {
        $styled = $this->styledClasses();
        $offenders = [];

        foreach ($this->renderedClasses() as $token => $files) {
            if (str_ends_with($token, '*')) {
                $prefix = substr($token, 0, -1);
                foreach (array_keys($styled) as $class) {
                    if (str_starts_with($class, $prefix)) {
                        continue 2;
                    }
                }
                $offenders[$token] = $files;
                continue;
            }

            if (isset($styled[$token]) || array_key_exists($token, self::UNSTYLED_HOOKS)) {
                continue;
            }

            $offenders[$token] = $files;
        }

        ksort($offenders);
        $report = [];
        foreach ($offenders as $token => $files) {
            $report[] = $token . '  (' . implode(', ', array_slice($files, 0, 3)) . ')';
        }

        self::assertSame(
            [],
            $report,
            "markup renders a class no stylesheet has a rule for.\n"
            . "A trailing * is a dynamically built family: at least one selector must start with that prefix.\n"
            . 'Add the rule, drop the class from the markup, or declare it in UNSTYLED_HOOKS with its reason.'
        );
    }

    public function testTheUnstyledHookListHasNoStaleEntries(): void
    {
        $rendered = $this->renderedClasses();
        foreach (self::UNSTYLED_HOOKS as $class => $reason) {
            self::assertNotSame('', trim($reason), $class . ' must carry the reason it is unstyled');
            self::assertArrayHasKey($class, $rendered, 'UNSTYLED_HOOKS names a class the portal no longer renders: ' . $class);
        }
    }
}
