<?php

declare(strict_types=1);

require_once __DIR__ . '/repo/settings.php';

const VIRTUSPHERE_SETTING_ANSIBLE_TEST_INTERVAL_HOURS = 'ansible_test_interval_hours';
const VIRTUSPHERE_ANSIBLE_TEST_INTERVAL_HOURS_DEFAULT = 24;
const VIRTUSPHERE_ANSIBLE_TEST_INTERVAL_HOURS_MIN = 0;
const VIRTUSPHERE_ANSIBLE_TEST_INTERVAL_HOURS_MAX = 168;
const VIRTUSPHERE_ANSIBLE_TEST_SCHEDULE_CHECK_SECONDS = 60;
const VIRTUSPHERE_ANSIBLE_TEST_TOTAL_TIMEOUT_SECONDS = 600;

function ansible_test_interval_hours(mysqli $db): int
{
    $value = repo_setting_value($db, VIRTUSPHERE_SETTING_ANSIBLE_TEST_INTERVAL_HOURS,
        (string) VIRTUSPHERE_ANSIBLE_TEST_INTERVAL_HOURS_DEFAULT);
    // A corrupt stored value must not enable outbound work unexpectedly.
    return ansible_test_parse_interval($value) ?? 0;
}

function ansible_test_parse_interval(string $value): ?int
{
    if (preg_match('/^[0-9]+$/D', $value) !== 1
        || strlen($value) > strlen((string) VIRTUSPHERE_ANSIBLE_TEST_INTERVAL_HOURS_MAX)) {
        return null;
    }
    $hours = (int) $value;
    return $hours >= VIRTUSPHERE_ANSIBLE_TEST_INTERVAL_HOURS_MIN
        && $hours <= VIRTUSPHERE_ANSIBLE_TEST_INTERVAL_HOURS_MAX ? $hours : null;
}
