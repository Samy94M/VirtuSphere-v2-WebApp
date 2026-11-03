<?php

declare(strict_types=1);

// Validation for the reportRun channel (ADR-0018): the three MECM sync tasks and
// the site-health reporter announce the start and the actual outcome of each run
// through mecm_report.php?action=reportRun. This module is a pure validator with
// no I/O so it can be exercised without the machine surface; it returns either a
// normalized report ready for repo_record_run_report() or an error envelope with
// the same 'error' key and HTTP status the rest of the endpoint uses.

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/repo/client_events.php';

/**
 * @param array<mixed> $data
 * @return array{error: string, status: int}|array{report: array<string,mixed>}
 */
function run_report_validate(array $data): array
{
    $source = is_string($data['source'] ?? null) ? (string) $data['source'] : '';
    if (!in_array($source, VIRTUSPHERE_INTEGRATION_RUN_SOURCES, true)) {
        return ['error' => 'Invalid source', 'status' => 400];
    }

    $event = is_string($data['event'] ?? null) ? (string) $data['event'] : '';
    if (!in_array($event, VIRTUSPHERE_RUN_EVENTS, true)) {
        return ['error' => 'Invalid event', 'status' => 400];
    }
    // The site-health reporter never announces a start; it only ever completes.
    if ($source === VIRTUSPHERE_INTEGRATION_SOURCE_SITE_HEALTH && $event !== VIRTUSPHERE_RUN_EVENT_COMPLETED) {
        return ['error' => 'Invalid event', 'status' => 400];
    }

    $runId = is_string($data['run_id'] ?? null) ? (string) $data['run_id'] : '';
    if (preg_match(VIRTUSPHERE_RUN_ID_PATTERN, $runId) !== 1) {
        return ['error' => 'Invalid run_id', 'status' => 400];
    }

    $interval = is_int($data['interval_seconds'] ?? null) || is_string($data['interval_seconds'] ?? null)
        ? (int) $data['interval_seconds'] : 0;
    if ($interval < VIRTUSPHERE_HEARTBEAT_INTERVAL_MIN_SECONDS || $interval > VIRTUSPHERE_HEARTBEAT_INTERVAL_MAX_SECONDS) {
        return ['error' => 'Invalid interval_seconds', 'status' => 400];
    }

    $scriptVersion = null;
    if (array_key_exists('script_version', $data) && $data['script_version'] !== null) {
        if (!is_string($data['script_version'])) {
            return ['error' => 'Invalid script_version', 'status' => 400];
        }
        $clean = run_report_sanitize_text($data['script_version'], VIRTUSPHERE_RUN_SCRIPT_VERSION_MAX_CHARS);
        $scriptVersion = $clean === '' ? null : $clean;
    }

    $report = [
        'source' => $source,
        'event' => $event,
        'run_id' => $runId,
        'interval_seconds' => $interval,
        'script_version' => $scriptVersion,
    ];

    if ($event === VIRTUSPHERE_RUN_EVENT_STARTED) {
        return ['report' => $report];
    }

    // event === completed
    $outcome = is_string($data['outcome'] ?? null) ? (string) $data['outcome'] : '';
    if (!in_array($outcome, VIRTUSPHERE_RUN_OUTCOMES, true)) {
        return ['error' => 'Invalid outcome', 'status' => 400];
    }

    $errorCategory = null;
    if ($outcome !== VIRTUSPHERE_RUN_OUTCOME_OK) {
        $errorCategory = is_string($data['error_category'] ?? null) ? (string) $data['error_category'] : '';
        $categoryCheck = run_report_validate_error_category($source, $outcome, $errorCategory);
        if ($categoryCheck !== null) {
            return $categoryCheck;
        }
    }

    $durationMs = null;
    if (array_key_exists('duration_ms', $data) && $data['duration_ms'] !== null) {
        if (!is_int($data['duration_ms']) || $data['duration_ms'] < 0 || $data['duration_ms'] > VIRTUSPHERE_RUN_DURATION_MS_MAX) {
            return ['error' => 'Invalid duration_ms', 'status' => 400];
        }
        $durationMs = (int) $data['duration_ms'];
    }

    $detail = null;
    if (array_key_exists('detail', $data) && $data['detail'] !== null) {
        if (!is_string($data['detail'])) {
            return ['error' => 'Invalid detail', 'status' => 400];
        }
        $detail = repo_client_event_sanitize_detail($data['detail']);
    }

    $summary = null;
    if (array_key_exists('summary', $data) && $data['summary'] !== null) {
        $summaryCheck = run_report_validate_summary($source, $data['summary']);
        if (isset($summaryCheck['error'])) {
            return $summaryCheck;
        }
        $summary = $summaryCheck['summary'];
    }

    $report['outcome'] = $outcome;
    $report['error_category'] = $errorCategory;
    $report['duration_ms'] = $durationMs;
    $report['detail'] = $detail;
    $report['summary'] = $summary;

    return ['report' => $report];
}

/**
 * Site-health categories are bound to a fixed outcome (site_warning<->warning,
 * site_critical<->fail, provider/query errors <->unknown) so a provider fault is
 * grey, never "MECM critical". Sync sources use the generic category set.
 *
 * @return array{error: string, status: int}|null null when the category is valid
 */
function run_report_validate_error_category(string $source, string $outcome, string $category): ?array
{
    if ($source === VIRTUSPHERE_INTEGRATION_SOURCE_SITE_HEALTH) {
        if (!array_key_exists($category, VIRTUSPHERE_RUN_SITE_ERROR_OUTCOME)
            || VIRTUSPHERE_RUN_SITE_ERROR_OUTCOME[$category] !== $outcome
        ) {
            return ['error' => 'Invalid error_category', 'status' => 400];
        }

        return null;
    }

    if (!in_array($category, VIRTUSPHERE_RUN_SYNC_ERROR_CATEGORIES, true)) {
        return ['error' => 'Invalid error_category', 'status' => 400];
    }

    return null;
}

/**
 * @param mixed $summary
 * @return array{error: string, status: int}|array{summary: array<string,int|string>}
 */
function run_report_validate_summary(string $source, $summary): array
{
    if (!is_array($summary) || array_is_list($summary)) {
        return ['error' => 'Invalid summary', 'status' => 400];
    }

    $allowed = VIRTUSPHERE_RUN_SUMMARY_FIELDS[$source] ?? [];
    $clean = [];
    foreach ($summary as $key => $value) {
        if (!is_string($key) || !in_array($key, $allowed, true)) {
            return ['error' => 'Invalid summary', 'status' => 400];
        }
        if (in_array($key, VIRTUSPHERE_RUN_SUMMARY_STRING_FIELDS, true)) {
            if (!is_string($value)) {
                return ['error' => 'Invalid summary', 'status' => 400];
            }
            $clean[$key] = run_report_sanitize_text($value, VIRTUSPHERE_RUN_SUMMARY_STRING_MAX_CHARS);
            continue;
        }
        if (!is_int($value) || $value < 0 || $value > VIRTUSPHERE_RUN_SUMMARY_VALUE_MAX) {
            return ['error' => 'Invalid summary', 'status' => 400];
        }
        $clean[$key] = $value;
    }

    return ['summary' => $clean];
}

// Strips control characters, collapses to a single trimmed line and caps the
// length. Report token, credentials and raw responses never reach here.
function run_report_sanitize_text(string $text, int $maxChars): string
{
    $text = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text));

    return mb_substr($text, 0, $maxChars);
}
