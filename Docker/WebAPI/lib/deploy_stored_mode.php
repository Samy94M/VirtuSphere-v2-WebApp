<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_constants.php';

/**
 * The mode a stored payload names: the normalized value when $payload is an
 * array whose 'mode' is a string contained in virtusphere_deploy_modes(), else
 * null. It never defaults and never throws, so a caller can refuse a job whose
 * mode is unreadable (AN-04). This is the one predicate for "does this stored
 * payload name a mode".
 *
 * A file of its own on purpose: the claim, the worker and the Ansible command
 * module all need it, and the deploy input module reaches the command module
 * through the network contract, so living there would make the command module
 * depend on its callers' include order.
 */
function deploy_job_stored_mode(mixed $payload): ?string
{
    if (!is_array($payload) || !isset($payload['mode']) || !is_string($payload['mode'])) {
        return null;
    }

    $mode = strtolower(trim($payload['mode']));

    return in_array($mode, virtusphere_deploy_modes(), true) ? $mode : null;
}
