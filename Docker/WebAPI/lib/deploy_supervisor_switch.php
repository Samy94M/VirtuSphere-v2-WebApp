<?php

declare(strict_types=1);

require_once __DIR__ . '/errors.php';

virtusphere_install_error_handlers();

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * The maintenance-window switch of the deploy process contract (Etappe 14C).
 *
 * A CLI and deliberately NOT a portal button. Every other service action on the
 * System status page is reversible with one click and changes only a row: pause,
 * resume, review, retry, document. This one changes which process shape the
 * container has to be started into afterwards, so a button next to those five
 * would be a button that looks like the others and is not. The plan's own list
 * of portal actions (section 21.3) does not contain it either.
 *
 * It refuses rather than persuades: `deploy_supervisor_switch_blockers()`
 * returns the COMPLETE list of unmet conditions, and this prints all of them,
 * because an operator preparing a window needs to know everything that is in
 * the way, not the first thing.
 *
 * Usage:
 *   php lib/deploy_supervisor_switch.php --to=supervisor_v1 [--actor=<user id>]
 *   php lib/deploy_supervisor_switch.php --to=worker_v1     [--actor=<user id>]
 *   php lib/deploy_supervisor_switch.php --check            (report only)
 */

require_once __DIR__ . '/audit_event_definitions.php';
require_once __DIR__ . '/audit_events.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/remote_execution_constants.php';
require_once __DIR__ . '/repo/deploy_supervisor_contract.php';
require_once __DIR__ . '/repo/helpers.php';
require_once __DIR__ . '/repo/deploy_runtime_identity.php';
require_once __DIR__ . '/repo/log.php';

/** @param list<string> $argv */
function deploy_supervisor_switch_main(array $argv): int
{
    $target = null;
    $actor = null;
    $checkOnly = in_array('--check', $argv, true);
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--to=')) {
            $target = substr($arg, 5);
        }
        if (str_starts_with($arg, '--actor=')) {
            $actor = (int) substr($arg, 8);
        }
    }

    $db = db();
    $current = repo_deploy_runtime_identity($db)['supervisor_contract'];

    if ($checkOnly || $target === null) {
        $probe = $target ?? ($current === VIRTUSPHERE_SUPERVISOR_CONTRACT_WORKER
            ? VIRTUSPHERE_SUPERVISOR_CONTRACT_SUPERVISOR
            : VIRTUSPHERE_SUPERVISOR_CONTRACT_WORKER);
        deploy_supervisor_switch_report($current, $probe, deploy_supervisor_switch_blockers($db, $probe));

        return 0;
    }

    if (!in_array($target, VIRTUSPHERE_SUPERVISOR_CONTRACTS, true)) {
        fwrite(STDERR, 'Unknown contract: ' . $target . "\n");

        return 2;
    }

    // Switch and audit row in ONE transaction, and that ordering is not a
    // style choice: the first version committed the CAS and wrote the audit
    // afterwards, so a rejected context field left the process contract
    // switched with nothing in the trail saying who did it or when. A change
    // this consequential either lands with its record or does not land.
    // repo_transaction() is re-entrant, so the CAS keeps its own wrapper.
    $result = repo_transaction($db, static function () use ($db, $target, $actor, $current): array {
        $switch = repo_deploy_switch_supervisor_contract($db, $target, $actor);
        // One audit row per attempt, and the refusal is the interesting one: it
        // names the condition a maintenance window has to satisfy next.
        audit_event(
            $db,
            VIRTUSPHERE_AUDIT_EVENT_DEPLOY_SUPERVISOR_CONTRACT,
            'system',
            null,
            $switch['changed'] ? VIRTUSPHERE_AUDIT_RESULT_SUCCESS : VIRTUSPHERE_AUDIT_RESULT_FAILURE,
            array_filter([
                'target_contract' => $target,
                'previous_contract' => $switch['changed'] ? $current : null,
                'blocker' => $switch['blockers'][0] ?? null,
            ], static fn (mixed $value): bool => $value !== null),
            $actor
        );

        return $switch;
    });

    deploy_supervisor_switch_report($current, $target, $result['blockers']);
    if (!$result['changed']) {
        return 1;
    }

    fwrite(STDOUT, "Now restart the deploy container into the matching process shape.\n");
    fwrite(STDOUT, $target === VIRTUSPHERE_SUPERVISOR_CONTRACT_SUPERVISOR
        ? "  command: php /var/www/html/lib/deploy_supervisor.php\n"
        : "  command: php /var/www/html/lib/deploy_worker.php --loop --sleep=5\n");
    fwrite(STDOUT, "Until it matches, the service reports itself as degraded on purpose.\n");

    return 0;
}

/** @param list<string> $blockers */
function deploy_supervisor_switch_report(string $current, string $target, array $blockers): void
{
    fwrite(STDOUT, 'current: ' . $current . "\n");
    fwrite(STDOUT, 'target:  ' . $target . "\n");
    if ($blockers === []) {
        fwrite(STDOUT, "blockers: none\n");

        return;
    }
    fwrite(STDOUT, "blockers:\n");
    foreach ($blockers as $blocker) {
        fwrite(STDOUT, '  - ' . $blocker . "\n");
    }
}

exit(deploy_supervisor_switch_main($argv));
