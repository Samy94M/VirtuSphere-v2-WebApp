<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/deploy_create_history_review.php';

$options = getopt('', ['job-id:', 'before:']);
$jobId = isset($options['job-id']) ? filter_var($options['job-id'], FILTER_VALIDATE_INT) : null;
if (isset($options['job-id']) && ($jobId === false || $jobId <= 0)) {
    fwrite(STDERR, "--job-id must be a positive integer.\n");
    exit(2);
}

$beforeUtc = null;
if (isset($options['before'])) {
    $before = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', (string) $options['before'], new DateTimeZone('UTC'));
    $errors = DateTimeImmutable::getLastErrors();
    if ($before === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        fwrite(STDERR, "--before must use UTC format YYYY-MM-DDTHH:MM:SSZ.\n");
        exit(2);
    }
    $beforeUtc = $before->format('Y-m-d H:i:s');
}

$rows = repo_deploy_create_history_review(db(), $jobId, $beforeUtc);
$document = [
    'read_only' => true,
    'assessment' => 'suspects_only',
    'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
    'filters' => [
        'job_id' => $jobId,
        'before_utc' => $beforeUtc,
    ],
    'candidate_count' => count($rows),
    'candidates' => $rows,
    'limits' => [
        'current_inventory_can_show_existence_and_identity_but_not_the_original_module_result',
        'current_inventory_does_not_prove_complete_hardware_convergence',
        'this_report_never_changes_status_retries_or_deletes_a_vm',
    ],
];

echo json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
