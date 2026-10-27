<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/defaults.php';
require_once dirname(__DIR__, 2) . '/lib/ansible_command_modes.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_create_constants.php';

/**
 * docs/operations/deploy-flows.md draws the deploy modes and every Ansible
 * playbook. Both lists live in code, so both are derived here and never listed:
 *
 *  - modes: virtusphere_deploy_modes() (user modes plus the system mode
 *    `inventory`); the playbooks of a mode come from
 *    ansible_playbooks_for_mode(), the system mode from
 *    VIRTUSPHERE_SYSTEM_PLAYBOOKS;
 *  - playbooks: the Ansible/*.yml files except requirements.yml;
 *  - the name in brackets after each mode: its German portal label
 *    (status.mode_<mode> in lang/de/status.php).
 *
 * Each direction is checked: a mode or file without its row/section leaves the
 * page incomplete, a row/section without a code counterpart is a diagram of
 * nothing. A zero match never passes. The checks are pure functions over
 * strings so the negative cases below can prove each guard turns red.
 *
 * The Ansible directory and docs/ only exist outside the PHP container; the
 * QA lane mounts the full repo (same pattern as MecmScheduledTasksDocContractTest).
 */
final class DeployFlowsDocContractTest extends TestCase
{
    private const MODES_SECTION = 'Modi auf einen Blick';
    private const PLAYBOOKS_SECTION = 'Playbooks';
    private const CREATE_TOKEN = 'Create je VM';

    public function testEveryModeHasItsRowWithTheCodePlaybooksInOrder(): void
    {
        $doc = self::loadDoc();
        $modes = virtusphere_deploy_modes();
        $createFile = VIRTUSPHERE_PLAYBOOKS['create'];
        $de = require dirname(__DIR__, 2) . '/lang/de/status.php';
        $labels = [];
        foreach ($modes as $mode) {
            if (isset($de['mode_' . $mode])) {
                $labels[$mode] = (string) $de['mode_' . $mode];
            }
        }

        foreach ([true, false] as $autostart) {
            $sequences = [];
            foreach ($modes as $mode) {
                $sequences[$mode] = self::sequenceFor($mode, $autostart);
            }
            self::assertSame([], self::modeTableProblems($modes, $sequences, $labels, $createFile, $doc));
        }
        self::assertContains(VIRTUSPHERE_DEPLOY_MODE_INVENTORY, $modes, 'The system mode must be part of the derived mode list.');
    }

    public function testEveryAnsibleFileHasAFlowchartSectionAndNoSectionIsOrphaned(): void
    {
        $doc = self::loadDoc();
        $stems = self::ansibleStems(dirname(__DIR__, 4) . '/Ansible');

        self::assertSame([], self::playbookSectionProblems($stems, $doc));
    }

    public function testModeProseSeparatesPlaybookDerivedFactsFromFixedModeLists(): void
    {
        $doc = self::loadDoc();
        self::assertStringContainsString('MAC-Erwartung und Create-Zeilen werden aus den Playbooks abgeleitet', $doc);
        self::assertStringContainsString('VIRTUSPHERE_DEPLOY_STAGGER_MODES', $doc);
        self::assertStringContainsString('VIRTUSPHERE_DEPLOY_INVENTORY_REFRESH_MODES', $doc);
        // The worker finds the create unit through the playbooks too; the doc
        // used to name a mode literal the worker no longer compares.
        $worker = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/deploy_worker_mission.php');
        self::assertStringContainsString('ansible_mode_creates_vms(', $worker);
        self::assertStringContainsString('`ansible_mode_creates_vms()`', $doc);
        self::assertStringNotContainsString('Modusliteral `create`', $doc);
        self::assertStringNotContainsString('Staffelung, Wartezeitsperren im Formular, MAC-Erwartung und Create-Zeilen werden daraus abgeleitet', $doc);
    }

    public function testClosedFlowchartVocabularyIsCoveredOrHasAReasonedException(): void
    {
        $root = dirname(__DIR__, 4);
        $deploy = str_replace("\r\n", "\n", (string) file_get_contents($root . '/docs/operations/deploy-flows.md'));
        $system = str_replace("\r\n", "\n", (string) file_get_contents($root . '/docs/operations/system-status-checks.md'));
        self::assertNotSame('', $deploy);
        self::assertNotSame('', $system);

        // The supervision and retry sections draw what used to be an exception:
        // the reaper's Create errors and terminal reasons, outcome_unknown and
        // the operator release (FC4/FC3, Welle B1). Sections are read by name,
        // so the job-state chart, which names every status by construction,
        // never satisfies the worker vocabulary on its own.
        $supervision = self::sectionBody($deploy, 'Aufsicht: verwaiste Aufträge');
        $retry = self::sectionBody($deploy, 'Wiederholen und Freigeben');
        self::assertNotSame('', $supervision, 'deploy-flows.md has no supervision section.');
        self::assertNotSame('', $retry, 'deploy-flows.md has no retry section.');
        $createChunk = self::sectionBody($deploy, self::CREATE_TOKEN)
            . self::between($deploy, '## Playbooks', '### powercycleVMs')
            . $supervision
            . $retry;
        $workerChunk = self::sectionBody($deploy, 'Worker: ein Auftrag') . $supervision;
        $exceptions = self::documentedVocabularyExceptions($deploy);
        self::assertSame([], self::closedVocabularyProblems(
            VIRTUSPHERE_CREATE_ERROR_CODES,
            self::mermaidBlocks($createChunk),
            $exceptions['Create-Fehler'] ?? [],
            'Create-Fehler'
        ));
        self::assertSame([], self::closedVocabularyProblems(
            array_keys(VIRTUSPHERE_DEPLOY_TERMINAL_REASON_STATUSES),
            self::mermaidBlocks($workerChunk),
            $exceptions['Terminalgrund'] ?? [],
            'Terminalgrund'
        ));
        // Every terminal job status appears in the worker or supervision chart;
        // outcome_unknown is written only by the reaper (WK-04/WK-07 A). A status
        // drawn in neither needs a reasoned exception.
        self::assertSame([], self::closedVocabularyProblems(
            VIRTUSPHERE_DEPLOY_JOB_TERMINAL_STATUSES,
            self::mermaidBlocks($workerChunk),
            $exceptions['Auftragsstatus'] ?? [],
            'Auftragsstatus'
        ));

        $systemExceptions = self::documentedVocabularyExceptions($system);
        self::assertSame([], self::closedVocabularyProblems(
            VIRTUSPHERE_ESXI_AMPEL_STATES,
            self::mermaidBlocks(self::sectionBody($system, 'ESXi-Inventar')),
            $systemExceptions['Ampelzustand'] ?? [],
            'ESXi-Ampelzustand'
        ));
        self::assertSame([], self::closedVocabularyProblems(
            VIRTUSPHERE_ANSIBLE_AMPEL_STATES,
            self::mermaidBlocks(self::sectionBody($system, 'Ansible-Test')),
            $systemExceptions['Ampelzustand'] ?? [],
            'Ansible-Ampelzustand'
        ));
        $heartbeatCharts = self::mermaidBlocks(
            self::sectionBody($system, 'MECM') . self::sectionBody($system, 'Interne Dienste')
        );
        self::assertSame([], self::closedVocabularyProblems(
            VIRTUSPHERE_HEARTBEAT_STATES,
            $heartbeatCharts,
            $systemExceptions['Ampelzustand'] ?? [],
            'Heartbeat-Ampelzustand'
        ));
    }

    public function testClosedVocabularyGuardHasARealNegativeAndRejectsStaleExceptions(): void
    {
        self::assertSame(
            ['Create-Fehler `brand_new_code` is missing from its flowchart and exception list.'],
            self::closedVocabularyProblems(['brand_new_code'], "flowchart TD\n A --> B[known_code]", [], 'Create-Fehler')
        );
        self::assertSame(
            ['Create-Fehler exception `removed_code` is not in the derived closed list.'],
            // One real derived value, so only the stale exception is reported
            // and not also the zero-match guard.
            self::closedVocabularyProblems(['known_code'], "flowchart TD\n A --> B[known_code]", ['removed_code' => 'reason'], 'Create-Fehler')
        );
        self::assertNotSame([], self::closedVocabularyProblems([], '', [], 'Create-Fehler'), 'zero-match must fail');
    }

    public function testGuardRejectsMissingModeRow(): void
    {
        $doc = self::fixtureModes(['`full` (A) | Create je VM, dann `powercycleVMs`']);
        $problems = self::modeTableProblems(['full', 'export'], ['full' => ['c-create.yml', 'powercycleVMs-ESXi_playbook.yml'], 'export' => ['exportVMs-Informations-ESXi_playbook.yml']], ['full' => 'A', 'export' => 'A'], 'c-create.yml', $doc);

        self::assertSame(['Mode `export` has no table row.'], $problems);
    }

    public function testGuardRejectsDuplicateModeRow(): void
    {
        $doc = self::fixtureModes(['`export` (A) | `exportVMs`', '`export` (B) | `exportVMs`']);
        $problems = self::modeTableProblems(['export'], ['export' => ['exportVMs-Informations-ESXi_playbook.yml']], ['export' => 'A'], 'c.yml', $doc);

        self::assertSame(['Mode `export` has 2 table rows, expected exactly one.'], $problems);
    }

    public function testGuardRejectsRowWithoutModeInCode(): void
    {
        $doc = self::fixtureModes(['`export` (A) | `exportVMs`', '`ghost` (B) | `exportVMs`']);
        $problems = self::modeTableProblems(['export'], ['export' => ['exportVMs-Informations-ESXi_playbook.yml']], ['export' => 'A'], 'c.yml', $doc);

        self::assertSame(['Table row for `ghost` has no mode in code (diagram of nothing).'], $problems);
    }

    public function testGuardRejectsWrongPlaybookOrder(): void
    {
        $doc = self::fixtureModes(['`powercycle` (A) | `exportVMs`, dann `powercycleVMs`']);
        $problems = self::modeTableProblems(
            ['powercycle'],
            ['powercycle' => ['powercycleVMs-ESXi_playbook.yml', 'exportVMs-Informations-ESXi_playbook.yml']],
            ['powercycle' => 'A'],
            'c.yml',
            $doc
        );

        self::assertCount(1, $problems);
        self::assertStringContainsString('wrong order', $problems[0]);
    }

    public function testGuardRejectsUnnamedPlaybook(): void
    {
        $doc = self::fixtureModes(['`powercycle` (A) | `powercycleVMs`']);
        $problems = self::modeTableProblems(
            ['powercycle'],
            ['powercycle' => ['powercycleVMs-ESXi_playbook.yml', 'exportVMs-Informations-ESXi_playbook.yml']],
            ['powercycle' => 'A'],
            'c.yml',
            $doc
        );

        self::assertCount(1, $problems);
        self::assertStringContainsString('does not name', $problems[0]);
    }

    public function testGuardRejectsLabelThatDiffersFromThePortal(): void
    {
        $doc = self::fixtureModes(['`inventory` (Systemmodus, ohne Mission) | `inventoryESXi`']);
        $problems = self::modeTableProblems(['inventory'], ['inventory' => ['inventoryESXi_playbook.yml']], ['inventory' => 'Inventar abrufen'], 'c.yml', $doc);

        self::assertSame(['Row `inventory` says "(Systemmodus, ohne Mission)", the portal label is "Inventar abrufen".'], $problems);
    }

    public function testGuardRejectsModeWithoutPortalLabel(): void
    {
        $doc = self::fixtureModes(['`export` (A) | `exportVMs`']);
        $problems = self::modeTableProblems(['export'], ['export' => ['exportVMs-Informations-ESXi_playbook.yml']], [], 'c.yml', $doc);

        self::assertSame(['Mode `export` has no German portal label status.mode_export.'], $problems);
    }

    public function testGuardRejectsZeroMatches(): void
    {
        self::assertNotSame([], self::modeTableProblems([], [], [], 'c.yml', self::fixtureModes(['`x` (A) | y'])));
        self::assertNotSame([], self::modeTableProblems(['x'], ['x' => ['a.yml']], ['x' => 'A'], 'c.yml', "# Doc\n\n## Anderes\n"));
        self::assertNotSame([], self::playbookSectionProblems([], "## Playbooks\n\n### a\n```mermaid\nflowchart TD\n```\n"));
        self::assertNotSame([], self::playbookSectionProblems(['a'], "## Playbooks\n\nNo subsections.\n"));
    }

    public function testGuardRejectsMissingPlaybookSection(): void
    {
        $doc = "## Playbooks\n\n### startVMs\n```mermaid\nflowchart TD\n```\n";

        self::assertSame(['Ansible/stopVMs has no "### " section under "## Playbooks".'], self::playbookSectionProblems(['startVMs', 'stopVMs'], $doc));
    }

    public function testGuardRejectsExtraPlaybookSection(): void
    {
        $doc = "## Playbooks\n\n### startVMs\n```mermaid\nflowchart TD\n```\n### ghost\n```mermaid\nflowchart TD\n```\n";

        self::assertSame(['Section "ghost" names no Ansible file (diagram of nothing).'], self::playbookSectionProblems(['startVMs'], $doc));
    }

    public function testGuardRejectsSectionWithoutFlowchart(): void
    {
        $doc = "## Playbooks\n\n### startVMs\nText only.\n";

        self::assertSame(['Section "startVMs" has no Mermaid flowchart.'], self::playbookSectionProblems(['startVMs'], $doc));
    }

    public function testCombinedHeadingCoversBothFiles(): void
    {
        $doc = "## Playbooks\n\n### a und b\n```mermaid\nflowchart TD\n```\n";

        self::assertSame([], self::playbookSectionProblems(['a', 'b'], $doc));
    }

    /**
     * @param string[] $modes
     * @param array<string, string[]> $sequences mode => playbook file names in execution order
     * @param array<string, string> $labels mode => German portal label
     * @return list<string>
     */
    public static function modeTableProblems(array $modes, array $sequences, array $labels, string $createFile, string $doc): array
    {
        $problems = [];
        if ($modes === []) {
            $problems[] = 'No deploy modes derived from code (zero-match must not pass).';
        }
        $rows = self::modeRows($doc);
        if ($rows === []) {
            $problems[] = 'No mode rows found in the table of "## ' . self::MODES_SECTION . '" (zero-match must not pass).';
        }

        $byMode = [];
        foreach ($rows as $row) {
            $byMode[$row['mode']][] = $row;
        }

        foreach ($modes as $mode) {
            $count = count($byMode[$mode] ?? []);
            if ($count === 0) {
                $problems[] = sprintf('Mode `%s` has no table row.', $mode);
                continue;
            }
            if ($count > 1) {
                $problems[] = sprintf('Mode `%s` has %d table rows, expected exactly one.', $mode, $count);
                continue;
            }
            $label = $byMode[$mode][0]['label'];
            if (!isset($labels[$mode])) {
                $problems[] = sprintf('Mode `%s` has no German portal label status.mode_%s.', $mode, $mode);
            } elseif ($label !== $labels[$mode]) {
                $problems[] = sprintf('Row `%s` says "(%s)", the portal label is "%s".', $mode, $label, $labels[$mode]);
            }
            $cell = $byMode[$mode][0]['cell'];
            $position = -1;
            foreach ($sequences[$mode] ?? [] as $file) {
                $token = self::rowToken($file, $createFile);
                $found = strpos($cell, $token, $position + 1);
                if ($found === false) {
                    $problems[] = strpos($cell, $token) === false
                        ? sprintf('Row `%s` does not name %s.', $mode, $token)
                        : sprintf('Row `%s` names %s in the wrong order.', $mode, $token);
                    continue;
                }
                $position = $found;
            }
        }

        foreach (array_keys($byMode) as $rowMode) {
            if (!in_array($rowMode, $modes, true)) {
                $problems[] = sprintf('Table row for `%s` has no mode in code (diagram of nothing).', $rowMode);
            }
        }

        return $problems;
    }

    /**
     * @param string[] $stems
     * @return list<string>
     */
    public static function playbookSectionProblems(array $stems, string $doc): array
    {
        $problems = [];
        if ($stems === []) {
            $problems[] = 'No Ansible files derived (zero-match must not pass).';
        }
        $playbooks = self::sections($doc)[self::PLAYBOOKS_SECTION] ?? null;
        if ($playbooks === null) {
            return array_merge($problems, ['The document has no "## ' . self::PLAYBOOKS_SECTION . '" section.']);
        }
        $subs = self::subsections($playbooks);
        if ($subs === []) {
            $problems[] = 'No "### " sections under "## ' . self::PLAYBOOKS_SECTION . '" (zero-match must not pass).';
        }

        $covered = [];
        foreach ($subs as $heading => $body) {
            $parts = array_map('trim', explode(' und ', $heading));
            foreach ($parts as $part) {
                if (!in_array($part, $stems, true)) {
                    $problems[] = sprintf('Section "%s" names no Ansible file (diagram of nothing).', $part === $heading ? $heading : $heading . '" / part "' . $part);
                } else {
                    $covered[$part] = true;
                }
            }
            if (preg_match('/```mermaid\nflowchart /', $body) !== 1) {
                $problems[] = sprintf('Section "%s" has no Mermaid flowchart.', $heading);
            }
        }
        foreach ($stems as $stem) {
            if (!isset($covered[$stem])) {
                $problems[] = sprintf('Ansible/%s has no "### " section under "## %s".', $stem, self::PLAYBOOKS_SECTION);
            }
        }

        return $problems;
    }

    /** @param list<string> $values @param array<string,string> $exceptions @return list<string> */
    public static function closedVocabularyProblems(array $values, string $diagram, array $exceptions, string $label): array
    {
        $problems = [];
        if ($values === []) {
            $problems[] = $label . ' has no values derived from code (zero-match must not pass).';
        }
        if ($diagram === '') {
            $problems[] = $label . ' has no flowchart source (zero-match must not pass).';
        }
        foreach ($exceptions as $value => $reason) {
            if (!in_array((string) $value, $values, true)) {
                $problems[] = sprintf('%s exception `%s` is not in the derived closed list.', $label, $value);
            }
            if (trim($reason) === '') {
                $problems[] = sprintf('%s exception `%s` has no reason.', $label, $value);
            }
        }
        foreach ($values as $value) {
            $token = preg_quote($value, '/');
            if (preg_match('/(?<![A-Za-z0-9_])' . $token . '(?![A-Za-z0-9_])/', $diagram) !== 1
                && !isset($exceptions[$value])) {
                $problems[] = sprintf('%s `%s` is missing from its flowchart and exception list.', $label, $value);
            }
        }

        return $problems;
    }

    /** @return array<string,array<string,string>> vocabulary => value => reason */
    private static function documentedVocabularyExceptions(string $doc): array
    {
        $section = self::between($doc, '## Diagrammausnahmen für geschlossene Werte', '## Playbooks');
        $exceptions = [];
        preg_match_all('/^\| (Create-Fehler|Terminalgrund|Auftragsstatus|Ampelzustand) \| `([^`]+)` \| ([^|]+) \|$/m', $section, $rows, PREG_SET_ORDER);
        foreach ($rows as $row) {
            $exceptions[$row[1]][$row[2]] = trim($row[3]);
        }

        return $exceptions;
    }

    private static function sectionBody(string $doc, string $heading): string
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

    private static function mermaidBlocks(string $text): string
    {
        preg_match_all('/```mermaid\s*\R(.*?)```/s', $text, $matches);

        return implode("\n", $matches[1]);
    }

    private static function between(string $text, string $start, string $end): string
    {
        $startAt = strpos($text, $start);
        if ($startAt === false) {
            return '';
        }
        $startAt += strlen($start);
        $endAt = strpos($text, $end, $startAt);

        return $endAt === false ? substr($text, $startAt) : substr($text, $startAt, $endAt - $startAt);
    }

    /**
     * @return list<array{mode: string, label: string, cell: string}>
     */
    public static function modeRows(string $doc): array
    {
        $body = self::sections($doc)[self::MODES_SECTION] ?? '';
        $rows = [];
        foreach (explode("\n", $body) as $line) {
            if (preg_match('/^\| `([a-z_]+)` \(([^)]*)\) \|/', $line, $match) !== 1) {
                continue;
            }
            $columns = explode('|', $line);
            $rows[] = ['mode' => $match[1], 'label' => $match[2], 'cell' => trim($columns[2] ?? '')];
        }

        return $rows;
    }

    /**
     * File name -> heading stem: the doc names a playbook after its file
     * without "-ESXi_playbook.yml" / "_playbook.yml" / ".yml".
     */
    public static function fileStem(string $file): string
    {
        $base = basename($file);

        return (string) preg_replace('/(?:-ESXi)?_playbook\.yml$|\.yml$/', '', $base);
    }

    /**
     * Text a mode row uses for one playbook: the stem up to the first hyphen in
     * backticks; the create playbook is summarised, because the table shows the
     * per-VM create chain, not the single launch file.
     */
    public static function rowToken(string $file, string $createFile): string
    {
        if ($file === $createFile) {
            return self::CREATE_TOKEN;
        }

        return '`' . explode('-', self::fileStem($file))[0] . '`';
    }

    /**
     * @return string[]
     */
    private static function ansibleStems(string $dir): array
    {
        $stems = [];
        foreach (glob($dir . '/*.yml') ?: [] as $file) {
            if (basename($file) === 'requirements.yml') {
                continue;
            }
            $stems[] = self::fileStem($file);
        }
        sort($stems);

        return $stems;
    }

    /**
     * @return string[]
     */
    private static function sequenceFor(string $mode, bool $autostart): array
    {
        if (isset(VIRTUSPHERE_SYSTEM_PLAYBOOKS[$mode])) {
            return [VIRTUSPHERE_SYSTEM_PLAYBOOKS[$mode]];
        }

        return ansible_playbooks_for_mode($mode, $autostart);
    }

    private static function loadDoc(): string
    {
        $root = dirname(__DIR__, 4);
        if (!is_dir($root . '/Ansible')) {
            self::markTestSkipped('Repo root not visible; Ansible/ only exists outside the container mount.');
        }
        $doc = $root . '/docs/operations/deploy-flows.md';
        self::assertFileExists($doc);

        return str_replace("\r\n", "\n", (string) file_get_contents($doc));
    }

    /**
     * @param string[] $rows "`mode` (label) | playbook cell" without the outer pipes
     */
    private static function fixtureModes(array $rows): string
    {
        $lines = array_map(static fn (string $row): string => '| ' . $row . ' | nein |', $rows);

        return "# Doc\n\n## " . self::MODES_SECTION . "\n\n| Modus | Playbooks | X |\n|---|---|---|\n" . implode("\n", $lines) . "\n\n## Danach\n";
    }

    /**
     * @return array<string, string> "## " heading => body
     */
    private static function sections(string $text): array
    {
        return self::split($text, '/^## /m');
    }

    /**
     * @return array<string, string> "### " heading => body
     */
    private static function subsections(string $text): array
    {
        return self::split($text, '/^### /m');
    }

    /**
     * @return array<string, string>
     */
    private static function split(string $text, string $pattern): array
    {
        $parts = preg_split($pattern, $text) ?: [];
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
