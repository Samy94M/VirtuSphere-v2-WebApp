<?php

declare(strict_types=1);

require_once __DIR__ . '/inventory_error_constants.php';

/**
 * Playbook-failure categorization of the inventory pull (K4b, 30.10.2026).
 * Split out of lib/ansible_inventory_parse.php when the failure-line reader
 * joined it: what a failed run says about its cause is a different question
 * from how a successful run is decoded. Public path stays lib/ansible_inventory.php.
 */

/**
 * The failure lines of an inventory run: the only text a category is read from
 * (K4b, 30.10.2026).
 *
 * The whole output said too much. Every run ends in a PLAY RECAP with
 * `unreachable=0`, so the `unreachable` needle matched every failure that no
 * earlier needle caught, and a host that answered 503 was reported as "not
 * reachable, check port and firewall". The `Origin:` block of ansible-core 2.19
 * quotes playbook source lines, including those of an earlier task, and a
 * failure under `ignore_errors` (closed by `...ignoring`) is no cause of the
 * run's end either.
 *
 * Kept: `[ERROR]:` lines (2.19) and `ERROR!` lines (before 2.19) with their
 * continuation lines up to `Origin:`, a blank line or the next bracketed line,
 * and the `fatal:`/`failed:` result lines. Measured in the QA image against
 * ansible-core 2.19.11 (qa-artifacts/k4b-2026-10-30/probe).
 *
 * @return list<string>
 */
function ansible_inventory_failure_lines(string $output): array
{
    $kept = [];
    $task = [];
    $inError = false;
    $inOrigin = false;
    foreach (preg_split('/\r\n|\n|\r/', $output) ?: [] as $raw) {
        $line = trim($raw);
        if (str_starts_with($line, 'TASK [') || str_starts_with($line, 'PLAY [') || str_starts_with($line, 'PLAY RECAP')) {
            array_push($kept, ...$task);
            $task = [];
            $inError = false;
            $inOrigin = false;
            continue;
        }
        if ($line === '...ignoring') {
            $task = [];
            $inError = false;
            $inOrigin = false;
            continue;
        }
        if ($inOrigin) {
            $inOrigin = $line !== '';
            continue;
        }
        if (str_starts_with($line, 'Origin: ')) {
            $inError = false;
            $inOrigin = true;
            continue;
        }
        if (str_starts_with($line, '[ERROR]:') || str_starts_with($line, 'ERROR!')) {
            $task[] = $line;
            $inError = true;
            continue;
        }
        if (preg_match('/^(fatal|failed): \[/', $line) === 1) {
            $task[] = $line;
            $inError = false;
            continue;
        }
        if ($inError) {
            if ($line === '' || str_starts_with($line, '[')) {
                $inError = false;
            } else {
                $task[count($task) - 1] .= ' ' . $line;
            }
        }
    }
    array_push($kept, ...$task);

    return $kept;
}

/**
 * An HTTP 5xx answer of the host itself, as the vSphere SDK reports it.
 *
 * Two measured shapes (pyVmomi 9.1, community.vmware 6.2, ansible-core 2.19):
 * a 5xx on the SOAP call carries the status line ("503 Service Unavailable"),
 * and a 5xx while pyVmomi looks for the API description ends in
 * "<host>:<port> is down or is not a VIM server". The second needs a TCP and
 * TLS connection that answered, so it is a host reply, not an unreachable
 * host; a refused port reads "[Errno 111] Connection refused" instead.
 */
const VIRTUSPHERE_INVENTORY_HOST_ERROR_PATTERN = '/\b50[0-5] (internal server error|not implemented|bad gateway|service unavailable|gateway time-?out|http version not supported)\b|is down or is not a vim server/';

/**
 * Classifies an inventory fetch failure from the playbook output + exit code
 * into an error category so the portal can show a specific hint. Only the
 * failure lines are read (ansible_inventory_failure_lines()); a run without
 * one is `parse`, the honest "unexpected".
 */
function ansible_categorize_inventory_error(string $output, int $exitCode): string
{
    $lower = strtolower(implode("\n", ansible_inventory_failure_lines($output)));
    if ($lower === '') {
        return VIRTUSPHERE_INVENTORY_ERROR_PARSE;
    }
    // Our own deployment, not the host's answer. A missing playbook or a
    // missing collection means the run never reached ESXi, so it must not be
    // reported with a sentence about the host: an inventory pull once died on
    // "the playbook: inventoryESXi_playbook.yml could not be found" and told
    // the operator the host had answered unexpectedly, sending them to check a
    // network path that was never used.
    // Every needle here is ansible-playbook's OWN diagnostic wording for "I
    // could not load or run what you asked me to". The category names Ansible
    // rather than the portal (Etappe 8): the operator fixes this on the Ansible
    // host, by installing a collection or a Python library, not in VirtuSphere.
    //
    // Deliberately narrow, and narrower than before: the bare `could not be
    // found` also matched "The object or item referred to could not be found",
    // which is vCenter's own answer for a missing datastore, so a genuine ESXi
    // reply was filed as our misconfiguration. The playbook form carries
    // Ansible's `ERROR! the playbook:` prefix and cannot be confused with it.
    // ansible-core 2.19 prints `[ERROR]: the playbook: ...`, older ones
    // `ERROR! the playbook: ...`; both reach this text only as a failure line.
    if (str_contains($lower, 'the playbook:')
        || str_contains($lower, "couldn't resolve module/action")
        // The controller is missing a Python library the module needs (pyVmomi
        // is the one this playbook always needs). Ansible names the interpreter
        // it looked in, so this is evidence about the host, not a guess.
        || str_contains($lower, 'failed to import the required python library')
        // Before the general `timed out` needle below, which would otherwise
        // call a become prompt that never came a network problem. Nothing was
        // unreachable: the sudo configuration on the Ansible host is wrong.
        || str_contains($lower, 'waiting for privilege escalation')
    ) {
        return VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_CONFIG;
    }
    if (str_contains($lower, 'certificate verify failed')
        || str_contains($lower, 'unable to get local issuer')
        || str_contains($lower, 'self-signed certificate')
        || str_contains($lower, 'self signed certificate')
    ) {
        return VIRTUSPHERE_INVENTORY_ERROR_CERTIFICATE;
    }
    if (str_contains($lower, 'permission') || str_contains($lower, 'not authorized') || str_contains($lower, 'privilege') || str_contains($lower, 'restricted')) {
        return VIRTUSPHERE_INVENTORY_ERROR_AUTHZ;
    }
    // The bare `login` was too wide for a category that pauses the automation:
    // it also matched a method name such as `makeUserLoginMethod` in a verbose
    // traceback. These are the vSphere and community.vmware wordings of a
    // rejected sign-in ("Cannot complete login due to an incorrect user name
    // or password.", "Unable to log on to vCenter or ESXi API ...").
    if (str_contains($lower, 'incorrect user name or password')
        || str_contains($lower, 'cannot complete login')
        || str_contains($lower, 'unable to log on')
        || str_contains($lower, 'invalidlogin')
        || str_contains($lower, 'login failed')
        || str_contains($lower, 'authentication')
        || str_contains($lower, 'invalid credentials')
    ) {
        return VIRTUSPHERE_INVENTORY_ERROR_AUTH;
    }
    // After the sign-in checks: a 5xx says the host answered and its
    // management service was not ready. Never paused, retried once soon
    // (esxi_inventory_attempt_due()).
    if (preg_match(VIRTUSPHERE_INVENTORY_HOST_ERROR_PATTERN, $lower) === 1) {
        return VIRTUSPHERE_INVENTORY_ERROR_HOST_ERROR;
    }
    if (str_contains($lower, 'unreachable') || str_contains($lower, 'timed out') || str_contains($lower, 'unable to connect') || str_contains($lower, 'no route to host') || str_contains($lower, 'connection refused')) {
        return VIRTUSPHERE_INVENTORY_ERROR_UNREACHABLE;
    }

    return VIRTUSPHERE_INVENTORY_ERROR_PARSE;
}
