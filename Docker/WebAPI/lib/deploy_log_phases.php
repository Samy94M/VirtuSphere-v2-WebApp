<?php

declare(strict_types=1);

// The phase view of one job log, derived from the step markers the worker
// already writes (Etappe 8).
//
// There is exactly one parser for this, in lib/ansible_command_modes.php, and
// this file is its only production consumer. The timeline below is likewise the
// only thing that turns a stream of markers into phases: a second, hand-kept
// playbook order would drift from the sequence the worker actually ran the
// moment a mode gains a step, and AnsibleCommandModuleContractTest fails the
// build on a second consumer for that reason.
//
// Phases are rendered as their own block ABOVE the output, not as heading rows
// inside it. The output table is a bounded window that trims from both ends and
// prepends older pages, so a heading row would regularly outlive the lines it
// introduces and, worse, would still be standing after its own lines were
// trimmed away. A phase whose lines are no longer in the window is a fact worth
// showing; a heading pretending to introduce them is not.
require_once __DIR__ . '/ansible_command_modes.php';

/**
 * The ordered phases of a job, plus the one that is still open.
 *
 * A `begin` opens a phase and the matching `end` closes it. A second `begin`
 * before an `end` closes the previous phase as INCOMPLETE rather than nesting:
 * the remote sequence is one command per step by contract (ADR-0033), so two
 * open steps cannot both be true, and the honest reading of a missing end
 * marker is that the step did not finish.
 *
 * @param list<array<string,mixed>> $logs rows carrying at least `seq` and `line`
 * @return array{
 *     phases: list<array{playbook:string,begin_seq:int,end_seq:?int,complete:bool}>,
 *     current: ?string
 * }
 */
function deploy_log_phase_timeline(array $logs): array
{
    $phases = [];
    $openIndex = null;

    foreach ($logs as $row) {
        $marker = ansible_step_marker_parse((string) ($row['line'] ?? ''));
        if ($marker === null) {
            continue;
        }
        $seq = (int) ($row['seq'] ?? 0);
        if ($marker['event'] === VIRTUSPHERE_ANSIBLE_STEP_BEGIN) {
            $phases[] = [
                'playbook' => $marker['playbook'],
                'begin_seq' => $seq,
                'end_seq' => null,
                'complete' => false,
            ];
            $openIndex = count($phases) - 1;
            continue;
        }
        // An `end` closes the open phase only when it names the same playbook.
        // An end for something else is evidence of a sequence nobody modelled,
        // and guessing which phase it belongs to would invent a fact.
        if ($openIndex !== null && $phases[$openIndex]['playbook'] === $marker['playbook']) {
            $phases[$openIndex]['end_seq'] = $seq;
            $phases[$openIndex]['complete'] = true;
            $openIndex = null;
        }
    }

    return [
        'phases' => $phases,
        'current' => $openIndex === null ? null : $phases[$openIndex]['playbook'],
    ];
}

/**
 * The seq range of one phase, for the phase filter.
 *
 * The filter needs no column of its own: a phase IS a seq interval, and the
 * markers already bound it. An open phase has no upper bound, which is exactly
 * right while it is still producing lines.
 *
 * @param array{phases: list<array{playbook:string,begin_seq:int,end_seq:?int,complete:bool}>, current: ?string} $timeline
 * @return array{0: int, 1: ?int}|null null when the job never entered that phase
 */
function deploy_log_phase_range(array $timeline, string $playbook): ?array
{
    foreach ($timeline['phases'] as $phase) {
        if ($phase['playbook'] === $playbook) {
            return [$phase['begin_seq'], $phase['end_seq']];
        }
    }

    return null;
}

/**
 * The playbook names a reader may filter by, in the order they ran and without
 * duplicates. A mode that runs the same playbook twice keeps one entry: the
 * filter answers "show me this phase", and two identical options would be two
 * ways to ask the same question with different answers.
 *
 * @param array{phases: list<array{playbook:string,begin_seq:int,end_seq:?int,complete:bool}>, current: ?string} $timeline
 * @return list<string>
 */
function deploy_log_phase_names(array $timeline): array
{
    $names = [];
    foreach ($timeline['phases'] as $phase) {
        if (!in_array($phase['playbook'], $names, true)) {
            $names[] = $phase['playbook'];
        }
    }

    return $names;
}
