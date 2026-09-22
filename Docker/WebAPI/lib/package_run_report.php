<?php

declare(strict_types=1);

/**
 * Pure V1 package-wrapper report validation (ADR-0044).
 *
 * The endpoint and repository are deliberately not hidden in this module. It
 * validates one self-describing event and returns the exact normalized values
 * the transactional writer will consume. Cross-event immutability, semantic
 * fingerprints, retention and detail reservation are repository decisions.
 */

require_once __DIR__ . '/package_run_report_constants.php';

/** @return array{error:string,status:int}|array{report:array<string,mixed>} */
function package_run_report_validate(array $data): array
{
    $common = package_run_report_validate_common($data);
    if (isset($common['error'])) {
        return $common;
    }

    $report = $common['report'];
    $event = (string) $report['event'];
    $allowed = package_run_report_common_fields();
    if ($event === VIRTUSPHERE_PACKAGE_REPORT_EVENT_STEP) {
        $allowed = array_merge($allowed, package_run_report_step_fields());
    } elseif ($event === VIRTUSPHERE_PACKAGE_REPORT_EVENT_COMPLETED) {
        $allowed = array_merge($allowed, package_run_report_completed_fields());
    }
    if (array_diff(array_keys($data), $allowed) !== []) {
        return package_run_report_error('unknown_field');
    }

    if ($event === VIRTUSPHERE_PACKAGE_REPORT_EVENT_STARTED) {
        return ['report' => $report];
    }
    if ($event === VIRTUSPHERE_PACKAGE_REPORT_EVENT_STEP) {
        return package_run_report_validate_step($data, $report);
    }

    return package_run_report_validate_completed($data, $report);
}

/** @return array{error:string,status:int}|array{report:array<string,mixed>} */
function package_run_report_validate_common(array $data): array
{
    if (array_diff(package_run_report_common_fields(), array_keys($data)) !== []) {
        return package_run_report_error('invalid_metadata');
    }
    if (($data['schema_version'] ?? null) !== VIRTUSPHERE_PACKAGE_REPORT_SCHEMA_VERSION) {
        return package_run_report_error('unsupported_schema');
    }
    $runId = package_run_report_uuid($data['run_id'] ?? null);
    $deviceGeneration = package_run_report_uuid($data['device_generation'] ?? null);
    $acceptanceGeneration = package_run_report_uuid($data['acceptance_generation'] ?? null);
    if ($runId === null || $deviceGeneration === null || $acceptanceGeneration === null) {
        return package_run_report_error('invalid_identity');
    }
    $event = $data['event'] ?? null;
    if (!is_string($event) || !in_array($event, VIRTUSPHERE_PACKAGE_REPORT_EVENTS, true)) {
        return package_run_report_error('invalid_event');
    }
    $eventSeq = package_run_report_int($data['event_seq'] ?? null, 1, PHP_INT_MAX);
    $revision = package_run_report_int($data['rollout_revision'] ?? null, 1, PHP_INT_MAX);
    if ($eventSeq === null || $revision === null) {
        return package_run_report_error('invalid_sequence');
    }
    $macs = package_run_report_macs($data['mac_candidates'] ?? null);
    if ($macs === null) {
        return package_run_report_error('invalid_mac_candidates');
    }
    $project = package_run_report_string($data['project_name'] ?? null, VIRTUSPHERE_PACKAGE_REPORT_TEXT_MAX_CHARS);
    $version = package_run_report_string($data['package_version'] ?? null, VIRTUSPHERE_PACKAGE_REPORT_TEXT_MAX_CHARS);
    $startedAt = package_run_report_timestamp($data['client_started_at'] ?? null);
    $eventAt = package_run_report_timestamp($data['event_at'] ?? null);
    $context = $data['context'] ?? null;
    if ($project === null || $version === null || $startedAt === null || $eventAt === null
        || !is_string($context) || !in_array($context, VIRTUSPHERE_PACKAGE_REPORT_CONTEXTS, true)
    ) {
        return package_run_report_error('invalid_metadata');
    }
    $total = $data['total'] ?? null;
    if ($total !== null) {
        $total = package_run_report_int($total, 0, PHP_INT_MAX);
        if ($total === null) {
            return package_run_report_error('invalid_total');
        }
    }

    return ['report' => [
        'schema_version' => VIRTUSPHERE_PACKAGE_REPORT_SCHEMA_VERSION,
        'run_id' => $runId,
        'event' => $event,
        'event_seq' => $eventSeq,
        'mac_candidates' => $macs,
        'rollout_revision' => $revision,
        'device_generation' => $deviceGeneration,
        'acceptance_generation' => $acceptanceGeneration,
        'project_name' => $project,
        'package_version' => $version,
        'client_started_at' => $startedAt,
        'event_at' => $eventAt,
        'context' => $context,
        'total' => $total,
    ]];
}

/** @return array{error:string,status:int}|array{report:array<string,mixed>} */
function package_run_report_validate_step(array $data, array $report): array
{
    $index = package_run_report_int($data['step_index'] ?? null, 1, PHP_INT_MAX);
    $script = package_run_report_string($data['script_name'] ?? null, VIRTUSPHERE_PACKAGE_REPORT_TEXT_MAX_CHARS);
    $result = $data['result'] ?? null;
    $first = $data['is_first_failure'] ?? null;
    if ($index === null || $script === null || !is_string($result)
        || !in_array($result, VIRTUSPHERE_PACKAGE_REPORT_STEP_RESULTS, true) || !is_bool($first)
    ) {
        return package_run_report_error('invalid_step');
    }
    if (($report['total'] !== null && $index > $report['total']) || ($first && $result !== 'fail')) {
        return package_run_report_error('inconsistent_step');
    }
    $category = package_run_report_optional_string($data, 'error_category', VIRTUSPHERE_PACKAGE_REPORT_TEXT_MAX_CHARS);
    $path = package_run_report_optional_string($data, 'detail_path', VIRTUSPHERE_PACKAGE_REPORT_PATH_MAX_CHARS);
    $exit = package_run_report_optional_int($data, 'child_exit_code', -2147483648, 2147483647);
    $duration = package_run_report_optional_int($data, 'duration_ms', 0, PHP_INT_MAX);
    if ($category === false || $path === false || $exit === false || $duration === false) {
        return package_run_report_error('invalid_step_detail');
    }
    $report += [
        'step_index' => $index,
        'script_name' => $script,
        'result' => $result,
        'is_first_failure' => $first,
        'error_category' => $category,
        'child_exit_code' => $exit,
        'duration_ms' => $duration,
        'detail_path' => $path,
    ];

    return ['report' => $report];
}

/** @return array{error:string,status:int}|array{report:array<string,mixed>} */
function package_run_report_validate_completed(array $data, array $report): array
{
    $wrapper = $data['wrapper_result'] ?? null;
    $detection = $data['detection_result'] ?? null;
    if (!is_string($wrapper) || !in_array($wrapper, VIRTUSPHERE_PACKAGE_REPORT_WRAPPER_RESULTS, true)
        || !is_string($detection) || !in_array($detection, VIRTUSPHERE_PACKAGE_REPORT_DETECTION_RESULTS, true)
    ) {
        return package_run_report_error('invalid_completion');
    }
    $counts = [];
    foreach (['processed_count', 'ok_count', 'skip_count', 'fail_count', 'payload_omitted_count'] as $field) {
        $counts[$field] = package_run_report_int($data[$field] ?? null, 0, PHP_INT_MAX);
        if ($counts[$field] === null) {
            return package_run_report_error('invalid_completion_count');
        }
    }
    if ($counts['processed_count'] !== $counts['ok_count'] + $counts['skip_count'] + $counts['fail_count']
        || ($report['total'] !== null && $counts['processed_count'] > $report['total'])
    ) {
        return package_run_report_error('inconsistent_completion');
    }
    $last = package_run_report_optional_int($data, 'last_processed_index', 1, PHP_INT_MAX);
    $exit = package_run_report_optional_int($data, 'wrapper_exit_code', -2147483648, 2147483647);
    $wrapperPath = package_run_report_optional_string($data, 'wrapper_log_path', VIRTUSPHERE_PACKAGE_REPORT_PATH_MAX_CHARS);
    $reportingPath = package_run_report_optional_string($data, 'reporting_log_path', VIRTUSPHERE_PACKAGE_REPORT_PATH_MAX_CHARS);
    if ($last === false || $exit === false || $wrapperPath === false || $reportingPath === false
        || (($counts['processed_count'] === 0) !== ($last === null))
        || ($last !== null && $report['total'] !== null && $last > $report['total'])
    ) {
        return package_run_report_error('inconsistent_completion');
    }
    $firstFailure = $data['first_failure'] ?? null;
    if ($firstFailure !== null) {
        $firstFailure = package_run_report_first_failure($firstFailure, $report['total']);
        if ($firstFailure === null) {
            return package_run_report_error('invalid_first_failure');
        }
    }
    if (($counts['fail_count'] === 0) !== ($firstFailure === null)) {
        return package_run_report_error('inconsistent_first_failure');
    }
    $report += $counts + [
        'wrapper_result' => $wrapper,
        'wrapper_exit_code' => $exit,
        'detection_result' => $detection,
        'last_processed_index' => $last,
        'first_failure' => $firstFailure,
        'wrapper_log_path' => $wrapperPath,
        'reporting_log_path' => $reportingPath,
    ];

    return ['report' => $report];
}

function package_run_report_first_failure(mixed $value, ?int $total): ?array
{
    if (!is_array($value) || array_is_list($value)) {
        return null;
    }
    $allowed = ['step_index', 'script_name', 'error_category', 'child_exit_code', 'detail_path'];
    if (array_diff(array_keys($value), $allowed) !== []) {
        return null;
    }
    $index = package_run_report_int($value['step_index'] ?? null, 1, PHP_INT_MAX);
    $script = package_run_report_string($value['script_name'] ?? null, VIRTUSPHERE_PACKAGE_REPORT_TEXT_MAX_CHARS);
    $category = package_run_report_optional_string($value, 'error_category', VIRTUSPHERE_PACKAGE_REPORT_TEXT_MAX_CHARS);
    $exit = package_run_report_optional_int($value, 'child_exit_code', -2147483648, 2147483647);
    $path = package_run_report_optional_string($value, 'detail_path', VIRTUSPHERE_PACKAGE_REPORT_PATH_MAX_CHARS);
    if ($index === null || $script === null || $category === false || $exit === false || $path === false
        || ($total !== null && $index > $total)
    ) {
        return null;
    }

    return ['step_index' => $index, 'script_name' => $script, 'error_category' => $category,
        'child_exit_code' => $exit, 'detail_path' => $path];
}

function package_run_report_uuid(mixed $value): ?string
{
    return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) === 1
        ? $value : null;
}

function package_run_report_timestamp(mixed $value): ?string
{
    if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,7})?Z$/D', $value) !== 1) {
        return null;
    }
    try {
        new DateTimeImmutable($value);
    } catch (Throwable) {
        return null;
    }
    $parseErrors = DateTimeImmutable::getLastErrors();
    if (is_array($parseErrors) && ($parseErrors['warning_count'] > 0 || $parseErrors['error_count'] > 0)) {
        return null;
    }

    return $value;
}

function package_run_report_string(mixed $value, int $maxChars): ?string
{
    return is_string($value) && $value !== '' && $value === trim($value)
        && mb_strlen($value) <= $maxChars && preg_match('/[\x00-\x1F\x7F]/u', $value) !== 1 ? $value : null;
}

function package_run_report_optional_string(array $data, string $key, int $maxChars): string|null|false
{
    if (!array_key_exists($key, $data) || $data[$key] === null) {
        return null;
    }

    return package_run_report_string($data[$key], $maxChars) ?? false;
}

function package_run_report_int(mixed $value, int $min, int $max): ?int
{
    return is_int($value) && $value >= $min && $value <= $max ? $value : null;
}

function package_run_report_optional_int(array $data, string $key, int $min, int $max): int|null|false
{
    if (!array_key_exists($key, $data) || $data[$key] === null) {
        return null;
    }

    return package_run_report_int($data[$key], $min, $max) ?? false;
}

/** @return list<string>|null */
function package_run_report_macs(mixed $value): ?array
{
    if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > 16) {
        return null;
    }
    $unique = [];
    foreach ($value as $mac) {
        if (!is_string($mac) || preg_match('/^[0-9A-F]{2}(?::[0-9A-F]{2}){5}$/D', $mac) !== 1 || isset($unique[$mac])) {
            return null;
        }
        $unique[$mac] = true;
    }

    $macs = array_keys($unique);
    sort($macs, SORT_STRING);

    return $macs;
}

/** @return array{error:string,status:int} */
function package_run_report_error(string $code, int $status = 400): array
{
    return ['error' => $code, 'status' => $status];
}

/** @return list<string> */
function package_run_report_common_fields(): array
{
    return ['schema_version', 'run_id', 'event', 'event_seq', 'mac_candidates', 'rollout_revision',
        'device_generation', 'acceptance_generation', 'project_name', 'package_version',
        'client_started_at', 'event_at', 'context', 'total'];
}

/** @return list<string> */
function package_run_report_step_fields(): array
{
    return ['step_index', 'script_name', 'result', 'is_first_failure', 'error_category',
        'child_exit_code', 'duration_ms', 'detail_path'];
}

/** @return list<string> */
function package_run_report_completed_fields(): array
{
    return ['wrapper_result', 'wrapper_exit_code', 'detection_result', 'processed_count', 'ok_count',
        'skip_count', 'fail_count', 'last_processed_index', 'first_failure', 'payload_omitted_count',
        'wrapper_log_path', 'reporting_log_path'];
}
