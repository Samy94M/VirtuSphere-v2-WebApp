<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/system_status.php';
require_once __DIR__ . '/../Support/CssRules.php';

/**
 * system_status.php is one long page of sections, and every link into it is a
 * fragment. A fragment naming a section the page does not render is not an
 * error: the browser stays at the top, so the reader lands on a page that does
 * not show the thing the message just named. That is the same silent-wrong-view
 * defect SettingsDeepLinkContractTest pins for settings.php.
 *
 * Etappe 9 made one of these links load-bearing: an ansible_* failure on the
 * ESXi card carries "Ansible-Status oeffnen" into the Ansible host section,
 * because that card can only offer the ESXi credential and the fault is on
 * another machine. If that anchor ever stops being rendered, the link keeps
 * working and stops leading anywhere.
 *
 * Scope is deliberate. The named VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_* constants
 * are the fixed deep links and are walked here. Only `credential-<id>` is
 * generated per row and therefore validated as a closed positive-id pattern.
 */
final class SystemStatusDeepLinkContractTest extends TestCase
{
    public function testDynamicCredentialTargetsHaveOneSemanticHighlight(): void
    {
        $targets = 0;
        foreach (glob($this->root() . '/' . self::RENDERERS) ?: [] as $path) {
            preg_match_all('/<article[^\n]*id="credential-[^\n]*/', (string) file_get_contents($path), $matches);
            foreach ($matches[0] as $tag) {
                $targets++;
                self::assertStringContainsString('data-deep-link-target', $tag, $path);
            }
        }
        self::assertGreaterThan(0, $targets);
        $owners = [];
        foreach (CssRules::stylesheets($this->root()) as $path => $css) {
            foreach (CssRules::rules($css) as $rule) {
                self::assertStringNotContainsString('tr:target', $rule['selector']);
                if ($rule['selector'] === '[data-deep-link-target]:target') {
                    $owners[] = $path;
                    self::assertStringContainsString('var(--surface-muted)', $rule['body']);
                    self::assertStringContainsString('inset', $rule['body']);
                }
            }
        }
        self::assertCount(1, $owners);
    }

    /** The one file allowed to spell the link out, because it is the builder. */
    private const BUILDER = 'lib/system_status_urls.php';

    /** Modules that render the page's sections; globbed, never a single file. */
    private const RENDERERS = 'lib/system_status*.php';

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
            glob($root . '/lib/help/*.php') ?: [],
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

    /** @return array<string, string> constant name => anchor value */
    private function anchorConstants(): array
    {
        $anchors = [];
        foreach (get_defined_constants() as $name => $value) {
            if (str_starts_with($name, 'VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_') && is_string($value)) {
                $anchors[$name] = $value;
            }
        }
        self::assertNotSame([], $anchors, 'no anchor constants found; the prefix or lib/constants.php changed');

        return $anchors;
    }

    /** @return list<string> section ids the page's renderers actually emit */
    private function renderedSectionIds(): array
    {
        $paths = glob($this->root() . '/' . self::RENDERERS) ?: [];
        self::assertNotSame([], $paths, 'the renderer glob matched nothing; the modules were renamed');

        $ids = [];
        foreach ($paths as $path) {
            $source = (string) file_get_contents($path);
            // Both spellings occur: a literal id and one echoed from the very
            // constant this test walks.
            preg_match_all('/id="([a-z][a-z0-9-]*)"/', $source, $literal);
            preg_match_all('/id="<\?php echo h\((VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_[A-Z_]+)\)/', $source, $viaConstant);
            foreach ($literal[1] as $id) {
                $ids[] = $id;
            }
            foreach ($viaConstant[1] as $name) {
                self::assertTrue(defined($name), $name . ' is echoed as an id but not defined');
                $ids[] = (string) constant($name);
            }
        }
        self::assertNotSame([], $ids, 'no section ids found; the markup or the regex changed');

        return array_values(array_unique($ids));
    }

    public function testEveryAnchorConstantIsRenderedAsASectionId(): void
    {
        $rendered = $this->renderedSectionIds();
        $missing = [];
        foreach ($this->anchorConstants() as $name => $anchor) {
            if (!in_array($anchor, $rendered, true)) {
                $missing[$name] = $anchor;
            }
        }

        self::assertSame(
            [],
            $missing,
            'these anchors are link targets that the page does not render; a link using them '
            . 'lands on the top of the page instead, with no error'
        );
    }

    public function testEveryAnchorConstantIsAcceptedByTheBuilder(): void
    {
        foreach ($this->anchorConstants() as $anchor) {
            self::assertSame('system_status.php#' . $anchor, system_status_url($anchor));
        }
    }

    public function testNoPageHandWritesASystemStatusDeepLink(): void
    {
        $offenders = [];
        foreach ($this->sources() as $relative => $source) {
            if (preg_match_all('/system_status\.php#/i', $source, $matches) > 0) {
                $offenders[$relative] = count($matches[0]) . ' occurrence(s)';
            }
        }

        self::assertSame(
            [],
            $offenders,
            'build System status deep links with system_status_url() (' . self::BUILDER . '); a hand-written'
            . ' fragment is not validated against the anchors the page renders'
        );
    }

    public function testTheBuilderIsUsedAndRejectsAnUnrenderableAnchor(): void
    {
        $callers = [];
        foreach ($this->sources() as $relative => $source) {
            if (str_contains($source, 'system_status_url(')) {
                $callers[] = $relative;
            }
        }
        self::assertNotSame(
            [],
            $callers,
            'nothing calls system_status_url() any more; either a page went back to a raw href or the links were dropped'
        );

        // The builder is the place a malformed anchor has to fail, because a
        // browser answers one with silence.
        $this->expectException(InvalidArgumentException::class);
        system_status_url('Ansible Status');
    }

    public function testTheBuilderRejectsAValidLookingUnknownAnchor(): void
    {
        $this->expectException(InvalidArgumentException::class);
        system_status_url('valid-but-not-rendered');
    }

    public function testTheBuilderRejectsAnInvalidDynamicCredentialAnchor(): void
    {
        $this->expectException(InvalidArgumentException::class);
        system_status_url('credential-0');
    }
}
