<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_constants.php';

const VIRTUSPHERE_DEPLOY_RESULT_VERSION = 1;
const VIRTUSPHERE_DEPLOY_RESULT_KIND = 'deploy_job';

/** Preserve richer callback results; fill only a missing terminal summary. */
function deploy_job_terminal_result_json(?string $existing, string $status): ?string
{
    if ($existing !== null && trim($existing) !== '') {
        return $existing;
    }
    if (!in_array($status, [VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED, VIRTUSPHERE_DEPLOY_STATUS_PARTIAL], true)) {
        return null;
    }

    return json_encode([
        'version' => VIRTUSPHERE_DEPLOY_RESULT_VERSION,
        'kind' => VIRTUSPHERE_DEPLOY_RESULT_KIND,
        'outcome' => $status,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

/** @return array{outcome:string}|null */
function deploy_job_decode_terminal_result(?string $json): ?array
{
    if ($json === null || trim($json) === '') {
        return null;
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)
        || count($decoded) !== 3
        || ($decoded['version'] ?? null) !== VIRTUSPHERE_DEPLOY_RESULT_VERSION
        || ($decoded['kind'] ?? null) !== VIRTUSPHERE_DEPLOY_RESULT_KIND
    ) {
        return null;
    }
    $outcome = $decoded['outcome'] ?? null;
    if (!in_array($outcome, [VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED, VIRTUSPHERE_DEPLOY_STATUS_PARTIAL], true)) {
        return null;
    }

    return ['outcome' => $outcome];
}
