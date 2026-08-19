<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/ansible_command.php';

/**
 * Step markers (AP6): every playbook of a remote sequence is bracketed by
 * begin/end lines, and the worker derives the failed phase from the last
 * begin without its end. These tests pin the marker grammar and its
 * placement in the && chain.
 */
final class AnsibleStepMarkerTest extends TestCase
{
    public function testMarkerLineRoundTrips(): void
    {
        foreach ([VIRTUSPHERE_ANSIBLE_STEP_BEGIN, VIRTUSPHERE_ANSIBLE_STEP_END] as $event) {
            $line = ansible_step_marker_line($event, 'createVM_playbook.yml');
            $parsed = ansible_step_marker_parse($line);
            self::assertNotNull($parsed);
            self::assertSame($event, $parsed['event']);
            self::assertSame('createVM_playbook.yml', $parsed['playbook']);
        }
    }

    public function testParseToleratesSurroundingWhitespace(): void
    {
        $line = '  ' . ansible_step_marker_line(VIRTUSPHERE_ANSIBLE_STEP_BEGIN, 'x.yml') . "\r";
        $parsed = ansible_step_marker_parse($line);
        self::assertNotNull($parsed);
        self::assertSame('x.yml', $parsed['playbook']);
    }

    public function testParseRejectsNonMarkerAndMalformedLines(): void
    {
        self::assertNull(ansible_step_marker_parse('TASK [Gathering Facts] *****'));
        self::assertNull(ansible_step_marker_parse(''));
        self::assertNull(ansible_step_marker_parse(VIRTUSPHERE_ANSIBLE_STEP_MARKER_PREFIX));
        self::assertNull(ansible_step_marker_parse(VIRTUSPHERE_ANSIBLE_STEP_MARKER_PREFIX . ' begin'));
        self::assertNull(ansible_step_marker_parse(VIRTUSPHERE_ANSIBLE_STEP_MARKER_PREFIX . ' resume x.yml'));
    }

    public function testEveryPlaybookIsItsOwnBracketedStepInOrder(): void
    {
        $steps = ansible_remote_steps('/tmp/deploy', ['mode' => 'powercycle', 'verbose' => false]);
        $playbooks = ansible_playbooks_for_mode('powercycle');
        self::assertCount(2, $playbooks);
        // One remote command per playbook (Etappe 8): the sequence's order is
        // the descriptor list's order, and the worker decides between them.
        self::assertSame($playbooks, array_column($steps, 'playbook'));

        foreach ($steps as $index => $step) {
            $command = $step['command'];
            $begin = strpos($command, ansible_step_marker_line(VIRTUSPHERE_ANSIBLE_STEP_BEGIN, $playbooks[$index]));
            self::assertNotFalse($begin, 'begin marker missing for ' . $playbooks[$index]);
            $run = strpos($command, 'ansible-playbook ' . ansible_sh_quote($playbooks[$index]), $begin);
            self::assertNotFalse($run, 'playbook missing after its begin marker: ' . $playbooks[$index]);
            $end = strpos($command, ansible_step_marker_line(VIRTUSPHERE_ANSIBLE_STEP_END, $playbooks[$index]), $run);
            self::assertNotFalse($end, 'end marker missing for ' . $playbooks[$index]);

            // The markers ride the same && chain WITHIN a step: a failing
            // playbook stops before its end marker, which is the phase signal.
            self::assertStringContainsString(' && echo ', $command);

            // No other playbook of the sequence may appear in this step, or the
            // boundary between them would exist only on paper.
            foreach ($playbooks as $otherIndex => $other) {
                if ($otherIndex !== $index) {
                    self::assertStringNotContainsString('ansible-playbook ' . ansible_sh_quote($other), $command);
                }
            }
        }
    }

    /**
     * The cleanup contract of a per-step sequence. With one chained command an
     * EXIT trap removed the directory once, at the end; per step that trap
     * would delete accounts.yml after the first playbook. So the steps trap the
     * terminating signals only, and the normal end has its own command.
     */
    public function testStepsCleanUpOnTerminationButNotOnANormalStepEnd(): void
    {
        $steps = ansible_remote_steps('/tmp/deploy job', ['mode' => 'full', 'verbose' => false], true);
        self::assertNotSame([], $steps);

        foreach ($steps as $step) {
            self::assertStringContainsString('trap ', $step['command']);
            self::assertStringContainsString(' HUP INT TERM', $step['command']);
            self::assertStringNotContainsString(' EXIT', $step['command']);
            // The directory name is quoted inside the trap too: a path with a
            // space would otherwise turn the cleanup into an rm of "/tmp".
            self::assertStringContainsString(ansible_sh_quote('/tmp/deploy job'), $step['command']);
            // Every step is its own shell, so the preamble is not optional.
            self::assertStringContainsString('chmod 600 accounts.yml', $step['command']);
        }

        self::assertSame(
            'rm -rf -- ' . ansible_sh_quote('/tmp/deploy job'),
            ansible_remote_cleanup_command('/tmp/deploy job')
        );
    }

    public function testFailureSuffixNamesTheStepOnlyWhenOneIsOpen(): void
    {
        self::assertSame('', ansible_step_failure_suffix(null));
        self::assertSame('', ansible_step_failure_suffix(''));
        self::assertSame(' (playbook step: exportVMs-Informations-ESXi_playbook.yml)', ansible_step_failure_suffix('exportVMs-Informations-ESXi_playbook.yml'));
    }
}
