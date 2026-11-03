<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Hand-built `<span class="badge ...">` markup bypasses portal_badge(), the one
 * renderer of the badge palette (GROK.md 1.2, SD-03).
 *
 * The defining file is derived from its `function portal_badge(` declaration,
 * not named here. Every other `class="badge` hit under lib/ and portal/ is a
 * violation unless it is listed in EXCEPTIONS. An exception is only legitimate
 * for a badge that needs an attribute portal_badge() cannot render, and it names
 * that attribute; it goes stale, and fails, once no hit in its file carries the
 * attribute any more.
 */
final class PortalBadgeContractTest extends TestCase
{
    /**
     * Keys are paths relative to Docker/WebAPI. `attribute` must appear in the
     * element (from its opening `<span` to its `</span>`) of the hand-built badge.
     *
     * @var array<string, array{attribute: string, reason: string}>
     */
    private const EXCEPTIONS = [
        'portal/deploy_log.php' => [
            'attribute' => 'data-deploy-status',
            'reason' => 'The live job status badge. portal/assets/deploy_log.js rewrites its text and class in place on every poll, so the data-deploy-status hook has to stay on the element.',
        ],
        'lib/users_accounts_panels.php' => [
            'attribute' => 'title=',
            'reason' => 'The locked-until badge carries a title hint (users.locked_until_hint). portal_badge() renders only the variant and the label, so the attribute cannot be passed through.',
        ],
    ];

    private function webapiRoot(): string
    {
        return str_replace('\\', '/', dirname(__DIR__, 2));
    }

    /**
     * Every file under lib/ and portal/, keyed by its path relative to Docker/WebAPI.
     *
     * @return array<string, string>
     */
    private function sources(): array
    {
        $root = $this->webapiRoot();
        $out = [];
        foreach (['lib', 'portal'] as $dir) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $path = str_replace('\\', '/', $file->getPathname());
                $out[substr($path, strlen($root) + 1)] = (string) file_get_contents($path);
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * The element around a hit: from the nearest `<` before it to the next `</span>`.
     */
    private function elementAt(string $contents, int $offset): string
    {
        $start = strrpos(substr($contents, 0, $offset), '<');
        $end = strpos($contents, '</span>', $offset);
        if ($start === false || $end === false) {
            return substr($contents, $offset, 200);
        }

        return substr($contents, $start, $end - $start);
    }

    public function testHandBuiltBadgeMarkupIsRenderedByPortalBadgeOnly(): void
    {
        $sources = $this->sources();
        self::assertNotEmpty($sources, 'no lib/ or portal/ files were scanned; the paths moved');

        $definers = [];
        foreach ($sources as $relative => $contents) {
            if (preg_match('/\bfunction\s+portal_badge\s*\(/', $contents) === 1) {
                $definers[] = $relative;
            }
        }
        self::assertCount(1, $definers, 'exactly one file under lib/ or portal/ may define portal_badge()');
        $definer = $definers[0];

        $offenders = [];
        $usedExceptions = [];
        foreach ($sources as $relative => $contents) {
            if ($relative === $definer) {
                continue;
            }
            // Both quote styles, so a single-quoted attribute is not a way around the guard.
            preg_match_all('/class=(["\'])badge/', $contents, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as [, $offset]) {
                $element = $this->elementAt($contents, $offset);
                $exception = self::EXCEPTIONS[$relative] ?? null;
                if ($exception !== null && str_contains($element, $exception['attribute'])) {
                    $usedExceptions[$relative] = true;
                    continue;
                }
                $line = substr_count(substr($contents, 0, $offset), "\n") + 1;
                $offenders[] = $relative . ':' . $line;
            }
        }

        self::assertSame(
            [],
            $offenders,
            'Hand-built badge markup; render it with portal_badge($variant, $label) (lib/layout_presenters.php). '
            . 'Only the exceptions in EXCEPTIONS may stay, and they need an attribute portal_badge() cannot render.',
        );

        foreach (self::EXCEPTIONS as $relative => $exception) {
            self::assertArrayHasKey(
                $relative,
                $usedExceptions,
                sprintf('%s is listed as a badge exception (%s) but no hand-built badge carries that attribute any more; remove the entry.', $relative, $exception['attribute']),
            );
            self::assertNotSame('', trim($exception['reason']), sprintf('%s needs a reason.', $relative));
        }
    }
}
