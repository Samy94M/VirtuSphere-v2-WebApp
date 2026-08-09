<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Etappe 10 makes the repository usable as an operator handbook. These are
 * structural contracts, not prose snapshots: they pin discoverability, local
 * link integrity, bounded language catalogs and the evidence destination that
 * otherwise drift independently without any runtime failure.
 */
final class OnboardingDocumentationContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 4);
        if (!is_file($this->root . '/README.md')) {
            self::markTestSkipped('Repository root is not visible in this container mount.');
        }
    }

    public function testOperatorHandbooksExistAndLeadTheReadmeOrder(): void
    {
        $operatorDocs = [
            'docs/operations/go-live.md',
            'docs/operations/troubleshooting.md',
            'docs/operations/deploy-chain.md',
            'docs/GLOSSARY.md',
        ];
        foreach ($operatorDocs as $relative) {
            self::assertFileExists($this->root . '/' . $relative, $relative . ' is part of the operator onboarding contract.');
        }

        $readme = (string) file_get_contents($this->root . '/README.md');
        $positions = [];
        foreach (array_merge($operatorDocs, ['AGENTS.md']) as $relative) {
            $position = strpos($readme, $relative);
            self::assertNotFalse($position, 'README.md does not point to ' . $relative);
            $positions[$relative] = $position;
        }
        self::assertLessThan($positions['docs/operations/troubleshooting.md'], $positions['docs/operations/go-live.md']);
        self::assertLessThan($positions['docs/operations/deploy-chain.md'], $positions['docs/operations/troubleshooting.md']);
        self::assertLessThan($positions['docs/GLOSSARY.md'], $positions['docs/operations/deploy-chain.md']);
        self::assertLessThan($positions['AGENTS.md'], $positions['docs/GLOSSARY.md'], 'Operator reading comes before contributor internals.');
    }

    public function testHelpCatalogsFollowTheirRendererBoundaries(): void
    {
        $modules = ['overview', 'missions', 'packages', 'deploy', 'stack', 'users', 'credentials', 'settings', 'system_status'];
        foreach (['de', 'en'] as $locale) {
            foreach ($modules as $module) {
                self::assertFileExists(
                    $this->root . '/Docker/WebAPI/lang/' . $locale . '/help_' . $module . '.php',
                    'The ' . $module . ' renderer needs its own ' . $locale . ' catalog.'
                );
            }

            /** @var array<string, string> $shell */
            $shell = require $this->root . '/Docker/WebAPI/lang/' . $locale . '/help.php';
            foreach (array_keys($shell) as $key) {
                self::assertMatchesRegularExpression('/^(title|tabs_label|tab_)/', $key, 'help.php is only the help-page shell catalog: ' . $key);
            }
        }

        foreach ($modules as $module) {
            $renderer = (string) file_get_contents($this->root . '/Docker/WebAPI/lib/help/' . $module . '.php');
            self::assertStringNotContainsString("__t('help.", $renderer, $module . '.php still reaches into the former monolith.');
        }
    }

    public function testQaEvidenceIsDirectedIntoItsDedicatedDirectory(): void
    {
        $checklist = (string) file_get_contents($this->root . '/PRE-SHIP-CHECKLIST.md');
        foreach (['fast', 'integration', 'release'] as $lane) {
            self::assertStringContainsString('qa-artifacts/qa-' . $lane . '.json', $checklist);
        }

        $ignore = (string) file_get_contents($this->root . '/.gitignore');
        self::assertStringContainsString('qa-artifacts/', $ignore);
        self::assertStringNotContainsString('/qa-*.json', $ignore, 'Root-level QA JSON is no longer an accepted evidence destination.');

        $runner = (string) file_get_contents($this->root . '/scripts/check.ps1');
        self::assertStringContainsString("'qa-artifacts'", $runner, 'Bare qa-*.json output must be routed below qa-artifacts/.');
    }

    public function testInstallationAndGoLiveNoLongerCarryDeferredOnboardingDebt(): void
    {
        $installation = (string) file_get_contents($this->root . '/docs/INSTALLATION-ANLEITUNG.md');
        self::assertMatchesRegularExpression('/\brequests\b/i', $installation, 'The Ansible host prerequisite must name requests.');
        self::assertStringNotContainsString('Offen bleibt das rein visuelle Design', $installation);

        $goLive = (string) file_get_contents($this->root . '/docs/operations/go-live.md');
        $step0 = strpos($goLive, '## Schritt 0:');
        $step1 = strpos($goLive, '## Schritt 1:');
        self::assertNotFalse($step0);
        self::assertNotFalse($step1);
        self::assertLessThan($step1, $step0, 'Step 0 must appear before step 1.');
    }

    public function testPortalHelpNamesOperationsDocsAsServerProjectFiles(): void
    {
        foreach (['de' => 'Projektordner', 'en' => 'project directory'] as $locale => $marker) {
            $files = glob($this->root . '/Docker/WebAPI/lang/' . $locale . '/help*.php') ?: [];
            self::assertNotSame([], $files);
            foreach ($files as $file) {
                /** @var array<string, string> $catalog */
                $catalog = require $file;
                foreach ($catalog as $key => $text) {
                    if (str_contains($text, 'docs/operations/')) {
                        self::assertStringContainsString($marker, $text, $locale . '/' . basename($file) . ':' . $key . ' makes a repository path look like a portal link.');
                    }
                }
            }
        }
    }

    public function testEveryLocalMarkdownLinkResolves(): void
    {
        $documents = array_merge([$this->root . '/README.md'], $this->markdownFiles($this->root . '/docs'));
        $checked = 0;
        foreach ($documents as $document) {
            $text = (string) file_get_contents($document);
            preg_match_all('/\[[^\]]+\]\(([^)]+)\)/', $text, $matches);
            foreach ($matches[1] as $target) {
                $target = trim((string) $target, "<> \t\n\r\0\x0B");
                if ($target === '' || str_starts_with($target, '#') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $target) === 1) {
                    continue;
                }
                $path = rawurldecode(explode('#', $target, 2)[0]);
                self::assertFileExists(dirname($document) . '/' . $path, basename($document) . ' has a dead local link: ' . $target);
                $checked++;
            }
        }
        self::assertGreaterThan(0, $checked, 'Link check had zero local Markdown links to prove.');
    }

    /** @return list<string> */
    private function markdownFiles(string $directory): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        foreach ($iterator as $entry) {
            if ($entry->isFile() && strtolower($entry->getExtension()) === 'md') {
                $files[] = $entry->getPathname();
            }
        }
        return $files;
    }
}
