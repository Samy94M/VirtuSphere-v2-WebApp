<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * docs/operations/mecm-scheduled-tasks.md draws one Mermaid flowchart per
 * scheduled MECM server task. The set of tasks lives in the installer's
 * `$tasks` list; the document mirrors it. A fifth task added to the installer
 * without a diagram would leave the flow page silently incomplete, so the task
 * list is derived from the installer, never listed here.
 *
 * Every task needs a table row (task name and script), a `## ` section named
 * after the task without the "VirtuSphere MECM " prefix, and a Mermaid
 * flowchart inside that section. The shared frame is the one extra section.
 *
 * Runs on the host and in the QA lane's full-repo mount; inside the PHP
 * container only Docker/WebAPI exists (DocIndexContractTest's pattern).
 */
final class MecmScheduledTasksDocContractTest extends TestCase
{
    private const TASK_PREFIX = 'VirtuSphere MECM ';

    public function testEveryInstallerTaskHasItsFlowchart(): void
    {
        $root = dirname(__DIR__, 4);
        $installer = $root . '/Powershell-MECM/install-VirtuSphere-MECM.ps1';
        $doc = $root . '/docs/operations/mecm-scheduled-tasks.md';
        if (!is_file($installer)) {
            self::markTestSkipped('Repo root not visible; the installer only exists outside the container mount.');
        }
        self::assertFileExists($doc);

        $tasks = self::installerTasks((string) file_get_contents($installer));
        self::assertNotSame([], $tasks, 'No $tasks entries found in the installer (zero-match must not pass).');

        $text = str_replace("\r\n", "\n", (string) file_get_contents($doc));
        $sections = self::sections($text);

        foreach ($tasks as $name => $script) {
            self::assertStringStartsWith(self::TASK_PREFIX, $name, 'Task names carry the shared prefix; the section title is derived from it.');
            self::assertMatchesRegularExpression(
                '/^\| ' . preg_quote($name, '/') . ' \| `' . preg_quote($script, '/') . '` \|/m',
                $text,
                sprintf('The task table in mecm-scheduled-tasks.md has no row for "%s" with `%s`.', $name, $script)
            );

            $title = substr($name, strlen(self::TASK_PREFIX));
            $body = null;
            foreach ($sections as $heading => $content) {
                if ($heading === $title || str_starts_with($heading, $title . ' (')) {
                    $body = $content;
                    break;
                }
            }
            self::assertNotNull($body, sprintf('mecm-scheduled-tasks.md has no "## %s" section for %s.', $title, $script));
            self::assertMatchesRegularExpression(
                '/```mermaid\nflowchart /',
                $body,
                sprintf('Section "%s" has no Mermaid flowchart.', $title)
            );
        }

        self::assertCount(
            count($tasks) + 1,
            $sections,
            'mecm-scheduled-tasks.md has one section per installer task plus the shared frame; a section without a task is a diagram of nothing.'
        );
    }

    /**
     * @return array<string, string> task name => script file
     */
    private static function installerTasks(string $installer): array
    {
        if (preg_match('/^\$tasks = @\((.*?)^\)/ms', $installer, $block) !== 1) {
            return [];
        }
        preg_match_all("/@\\{\\s*Name\\s*=\\s*'([^']+)';\\s*Script\\s*=\\s*'([^']+)'\\s*\\}/", $block[1], $matches, PREG_SET_ORDER);
        $tasks = [];
        foreach ($matches as $match) {
            $tasks[$match[1]] = $match[2];
        }

        return $tasks;
    }

    /**
     * @return array<string, string> "## " heading => section body
     */
    private static function sections(string $text): array
    {
        $parts = preg_split('/^## /m', $text) ?: [];
        array_shift($parts);
        $sections = [];
        foreach ($parts as $part) {
            $newline = strpos($part, "\n");
            $heading = trim($newline === false ? $part : substr($part, 0, $newline));
            $sections[$heading] = $newline === false ? '' : substr($part, $newline + 1);
        }

        return $sections;
    }
}
