<?php

declare(strict_types=1);

/** The step markers each per-playbook remote command prints around its playbook on stdout (AP6). */

// Step markers (AP6): every per-playbook remote command is bracketed by a begin
// and an end line on stdout. The worker controls and names the step from its
// descriptor; the persisted markers are the technical display evidence. The
// short chain inside one command still omits the end marker when its playbook
// fails, so the later presenter can identify an incomplete phase honestly.
const VIRTUSPHERE_ANSIBLE_STEP_MARKER_PREFIX = '::virtusphere-step::';

const VIRTUSPHERE_ANSIBLE_STEP_BEGIN = 'begin';
const VIRTUSPHERE_ANSIBLE_STEP_END = 'end';

function ansible_step_marker_line(string $event, string $playbook): string
{
    return VIRTUSPHERE_ANSIBLE_STEP_MARKER_PREFIX . ' ' . $event . ' ' . $playbook;
}

/**
 * Reads one step marker back out of the stored output.
 *
 * Deliberately kept although the worker no longer calls it: since Etappe 8 the
 * worker knows which step it is in from the descriptor it just started, which
 * is strictly better evidence than a marker that may not have arrived yet. The
 * markers stay the technical SSoT for the DISPLAY - phase headings and "current
 * phase" are derived from them rather than from a second hand-kept playbook
 * order. That reader belongs to Etappe 13, together with the 10A cursor/drain
 * contract and the 10B terminal presenter. Removing this as dead code would
 * force that stage to write the parser again, differently.
 *
 * @return array{event: string, playbook: string}|null Null for any line that
 *         is not a well-formed step marker.
 */
function ansible_step_marker_parse(string $line): ?array
{
    $line = trim($line);
    if (!str_starts_with($line, VIRTUSPHERE_ANSIBLE_STEP_MARKER_PREFIX . ' ')) {
        return null;
    }

    $parts = explode(' ', substr($line, strlen(VIRTUSPHERE_ANSIBLE_STEP_MARKER_PREFIX) + 1), 2);
    $event = $parts[0];
    $playbook = trim($parts[1] ?? '');
    if (!in_array($event, [VIRTUSPHERE_ANSIBLE_STEP_BEGIN, VIRTUSPHERE_ANSIBLE_STEP_END], true) || $playbook === '') {
        return null;
    }

    return ['event' => $event, 'playbook' => $playbook];
}

/**
 * Suffix naming the failed playbook step for a job error message. Empty when
 * no step was open (the failure happened outside the playbook sequence, e.g.
 * during cd/chmod or before the first marker arrived).
 */
function ansible_step_failure_suffix(?string $playbook): string
{
    return $playbook === null || $playbook === '' ? '' : ' (playbook step: ' . $playbook . ')';
}
