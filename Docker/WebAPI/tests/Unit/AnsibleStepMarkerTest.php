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

    public function testRemoteCommandBracketsEveryPlaybookInOrder(): void
    {
        $command = ansible_remote_command('/tmp/deploy', ['mode' => 'powercycle', 'verbose' => false]);
        $playbooks = ansible_playbooks_for_mode('powercycle');
        self::assertCount(2, $playbooks);

        $cursor = 0;
        foreach ($playbooks as $playbook) {
            $begin = strpos($command, ansible_step_marker_line(VIRTUSPHERE_ANSIBLE_STEP_BEGIN, $playbook), $cursor);
            self::assertNotFalse($begin, 'begin marker missing for ' . $playbook);
            $run = strpos($command, 'ansible-playbook ' . ansible_sh_quote($playbook), $begin);
            self::assertNotFalse($run, 'playbook missing after its begin marker: ' . $playbook);
            $end = strpos($command, ansible_step_marker_line(VIRTUSPHERE_ANSIBLE_STEP_END, $playbook), $run);
            self::assertNotFalse($end, 'end marker missing for ' . $playbook);
            $cursor = $end;
        }

        // The markers ride the same && chain: a failing playbook stops the
        // chain before its end marker, which is exactly the phase signal.
        self::assertStringContainsString(' && echo ', $command);
    }

    public function testFailureSuffixNamesTheStepOnlyWhenOneIsOpen(): void
    {
        self::assertSame('', ansible_step_failure_suffix(null));
        self::assertSame('', ansible_step_failure_suffix(''));
        self::assertSame(' (playbook step: exportVMs-Informations-ESXi_playbook.yml)', ansible_step_failure_suffix('exportVMs-Informations-ESXi_playbook.yml'));
    }
}
