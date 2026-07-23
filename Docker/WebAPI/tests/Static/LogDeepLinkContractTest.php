<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * logs.php scopes its ?category= filter to the active tab and drops a category
 * the tab does not contain. A deep link that names only the category therefore
 * lands on the default tab and shows unrelated rows: no error, no empty list,
 * nothing php -l or the lang audit can see. `system_status.php` shipped exactly
 * that link from the day the tabs were introduced. `log_category_url()` in
 * lib/repo/log.php derives the tab from VIRTUSPHERE_LOG_TABS; this pins it as
 * the only way to build such a link.
 */
final class LogDeepLinkContractTest extends TestCase
{
    /** The one file allowed to spell the link out, because it is the builder. */
    private const BUILDER = 'lib/repo/log.php';

    private function root(): string
    {
        return str_replace('\\', '/', dirname(__DIR__, 2));
    }

    /**
     * Comments name the very pattern they forbid (this class does it too), so a
     * raw scan would report the warning as the offence.
     */
    private function stripComments(string $php): string
    {
        $stripped = '';
        foreach (token_get_all($php) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $stripped .= $token[1];
                continue;
            }
            $stripped .= $token;
        }

        return $stripped;
    }

    /** @return array<string, string> relative path => source without PHP comments */
    private function sources(): array
    {
        $root = $this->root();
        $paths = array_merge(
            glob($root . '/portal/*.php') ?: [],
            glob($root . '/lib/*.php') ?: [],
            glob($root . '/lib/repo/*.php') ?: [],
        );
        self::assertNotSame([], $paths, 'no portal or lib sources found to scan');

        $sources = [];
        foreach ($paths as $path) {
            $relative = substr(str_replace('\\', '/', $path), strlen($root) + 1);
            if ($relative === self::BUILDER) {
                continue;
            }

            $sources[$relative] = $this->stripComments((string) file_get_contents($path));
        }

        return $sources;
    }

    public function testNoPageHandWritesACategoryDeepLink(): void
    {
        $offenders = [];
        foreach ($this->sources() as $relative => $source) {
            if (preg_match_all('/logs\.php\?[^\'"]*category=/i', $source, $matches) > 0) {
                $offenders[$relative] = implode(', ', $matches[0]);
            }
        }

        self::assertSame(
            [],
            $offenders,
            'build log deep links with log_category_url() (' . self::BUILDER . '); a hand-written link omits the tab'
        );
    }

    public function testTheBuilderIsReachableFromThePortal(): void
    {
        $builder = $this->root() . '/' . self::BUILDER;
        self::assertFileExists($builder, self::BUILDER . ' must exist');
        self::assertStringContainsString(
            'function log_category_url(',
            (string) file_get_contents($builder),
            self::BUILDER . ' must define log_category_url(); deleting it turns every deep link back into a hand-written one'
        );

        $callers = [];
        foreach ($this->sources() as $relative => $source) {
            if (str_contains($source, 'log_category_url(')) {
                $callers[] = $relative;
            }
        }

        self::assertNotSame(
            [],
            $callers,
            'nothing calls log_category_url() any more; either a page went back to a raw href or the deep link was dropped'
        );
    }
}
