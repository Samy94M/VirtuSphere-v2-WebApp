<?php

declare(strict_types=1);

require_once __DIR__ . '/ansible_command_shell.php';
require_once __DIR__ . '/credentials.php';
require_once __DIR__ . '/deploy_create_constants.php';
require_once __DIR__ . '/errors.php';

/**
 * The remote commands of one create unit (Etappe 14B, Teiletappe E).
 *
 * A create unit is driven by four short control calls - prepare, launch,
 * status, cleanup - and this module is the only place that builds them. They
 * differ from the sequence steps in ansible_command_modes.php in one property
 * that is the whole point of this stage: they must NOT carry a cleanup trap.
 * A sequence step owns nothing beyond itself, so removing the work directory
 * when its shell is killed is right. A create control call leaves an async job
 * behind that keeps running on the host, and its state file lives inside that
 * directory: a trap here would delete the only evidence about a VM whose
 * outcome nobody has established yet, which is exactly the blindness the
 * 13.08.2026 incident consisted of.
 *
 * Paths are derived, never stored and never supplied by the portal. The job's
 * remote directory is already deterministic from job id and mission name, so
 * the unit directory below it can be recomputed after a worker restart without
 * a second copy of it in the database.
 */

/**
 * The per-unit directory below the job's remote work directory.
 *
 * Named by the same step key the generic remote handle uses for this unit
 * (`create.vm.<position>`), so a later stage that binds a handle finds the
 * shape it expects rather than a second naming scheme.
 */
function ansible_create_unit_dir(string $remoteDir, int $position): string
{
    ansible_create_assert_remote_dir($remoteDir);

    return rtrim($remoteDir, '/') . '/' . deploy_create_step_key($position);
}

/** The async state directory of one unit; the fixed subfolder of its unit dir. */
function ansible_create_async_dir(string $remoteDir, int $position): string
{
    return ansible_create_unit_dir($remoteDir, $position) . '/' . VIRTUSPHERE_CREATE_ASYNC_DIR_NAME;
}

/**
 * Where one control call writes its result document. Per stage, because a
 * stale document from the previous call must never be read as this call's
 * answer; the command removes it before the playbook runs as well.
 */
function ansible_create_result_file(string $remoteDir, int $position, string $stage): string
{
    if (preg_match('/^[a-z]{1,16}$/', $stage) !== 1) {
        throw new InvalidArgumentException('Create control stage name is malformed.');
    }

    return ansible_create_unit_dir($remoteDir, $position) . '/' . $stage . '.json';
}

/**
 * A remote work directory this module is willing to build paths under.
 *
 * Checked here rather than at the call sites: everything below it ends up in a
 * shell command and in an `ansible_async_dir` variable, and an empty or
 * relative value would put the async state of a running create somewhere
 * nobody can find it again.
 */
function ansible_create_assert_remote_dir(string $remoteDir): void
{
    if ($remoteDir === '' || $remoteDir[0] !== '/' || str_contains($remoteDir, '..')) {
        throw new InvalidArgumentException('Create control needs an absolute remote directory without traversal.');
    }
}

/**
 * The one remote command of one control call.
 *
 * Shape, in order, and every part of it load-bearing:
 *  - `umask 077` plus an explicit mode, so the unit directory and everything
 *    the playbook writes into it stay private to the deploy user;
 *  - a refusal to follow a symlink where the unit or async directory should
 *    be, because those two names are the only part of the path this worker
 *    invents rather than reads back;
 *  - removal of this stage's result document, so a call that dies before
 *    writing one cannot be answered with the previous call's document;
 *  - the playbook itself with `2>&1`, so the merged output is what the worker
 *    stores and scans;
 *  - the emitter, which turns the result document into the single marker line
 *    the protocol defines.
 *
 * The extra-vars go in as ONE JSON document rather than as `-e key=value`
 * pairs: `key=value` reaches Ansible as a string, and the target id, the
 * timeout and the expected `existed_before` are an int, an int and a boolean.
 * A playbook that has to guess the type of a value the worker knows exactly is
 * a playbook that will guess wrong once.
 *
 * @param array<string, mixed> $extraVars
 */
function ansible_create_control_command(
    string $remoteDir,
    string $playbook,
    int $position,
    array $extraVars,
    bool $verbose = false
): string {
    if (!in_array($playbook, VIRTUSPHERE_CREATE_ARTIFACTS, true) || !str_ends_with($playbook, '.yml')) {
        throw new InvalidArgumentException('Unknown create control playbook: ' . $playbook);
    }
    ansible_create_assert_extra_vars($playbook, $extraVars);
    $unitDir = ansible_create_unit_dir($remoteDir, $position);
    $asyncDir = ansible_create_async_dir($remoteDir, $position);

    $commands = [
        'umask 077',
        'cd ' . ansible_sh_quote($remoteDir),
        'chmod 600 accounts.yml',
        'if [ -f ' . VIRTUSPHERE_ESXI_TRUST_FILE . ' ]; then chmod 600 ' . VIRTUSPHERE_ESXI_TRUST_FILE . '; fi',
        'export VS_CORRELATION_ID=' . ansible_sh_quote(virtusphere_correlation_id()),
        // Etappe 14B: Python block-buffers stdout as soon as it is not a
        // terminal, and the worker reads through an SSH pipe. Without this the
        // marker line of a short control call can sit in the buffer.
        'export PYTHONUNBUFFERED=1',
        'test ! -L ' . ansible_sh_quote($unitDir),
        'mkdir -p -m 0700 ' . ansible_sh_quote($unitDir),
        'test ! -L ' . ansible_sh_quote($asyncDir),
        'mkdir -p -m 0700 ' . ansible_sh_quote($asyncDir),
    ];
    if (isset($extraVars['vs_result_file'])) {
        $commands[] = 'rm -f -- ' . ansible_sh_quote((string) $extraVars['vs_result_file']);
    }
    $commands[] = 'ansible-playbook ' . ansible_sh_quote($playbook)
        . ' -e ' . ansible_sh_quote(ansible_create_extra_vars_json($extraVars))
        . ($verbose ? ' -vvv' : '') . ' 2>&1';
    if (isset($extraVars['vs_result_file'])) {
        $commands[] = 'python3 ' . ansible_sh_quote(VIRTUSPHERE_CREATE_RESULT_EMITTER)
            . ' ' . ansible_sh_quote((string) $extraVars['vs_result_file']);
    }

    return implode(' && ', $commands);
}

/**
 * The extra-vars a control call may pass, against the declaration in
 * lib/deploy_create_constants.php, in both directions.
 *
 * Nothing here may ever be filled from a portal form. Checking the NAMES
 * against the SSoT is what keeps that true as the code moves: a value that
 * arrives under a name the playbook does not read is silently ignored by
 * Ansible, and a missing one is an undefined variable on the host.
 *
 * @param array<string, mixed> $extraVars
 */
function ansible_create_assert_extra_vars(string $playbook, array $extraVars): void
{
    $declared = VIRTUSPHERE_CREATE_EXTRA_VARS[$playbook] ?? [];
    $given = array_keys($extraVars);
    sort($declared);
    sort($given);
    if ($declared !== $given) {
        throw new InvalidArgumentException(
            'Create control call for ' . $playbook . ' passes ' . implode(', ', $given)
            . ' instead of ' . implode(', ', $declared) . '.'
        );
    }
}

/** @param array<string, mixed> $extraVars */
function ansible_create_extra_vars_json(array $extraVars): string
{
    $json = json_encode($extraVars, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    // A control character in an extra-var would survive JSON as an escape and
    // reach the host as a real one. Every value here is either generated by
    // this worker or was validated against its character class before it was
    // stored, so this is the assertion that both of those stayed true.
    if (preg_match('/[\x00-\x1F]/', $json) === 1) {
        throw new InvalidArgumentException('Create extra-vars carry a control character.');
    }

    return $json;
}
