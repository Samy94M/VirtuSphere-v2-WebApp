<?php

declare(strict_types=1);

// Package-wrapper result reporting (ADR-0044) is an additive machine contract,
// separate from both legacy client phases and MECM server run reports. Keeping
// its closed V1 vocabulary here avoids growing the already-ratcheted global
// constants registry and gives PowerShell one small mirror to pin.
const VIRTUSPHERE_PACKAGE_REPORT_SCHEMA_VERSION = 1;
const VIRTUSPHERE_PACKAGE_REPORT_MAX_BODY_BYTES = 65536;
const VIRTUSPHERE_PACKAGE_REPORT_NORMAL_DETAIL_LIMIT = 256;
const VIRTUSPHERE_PACKAGE_REPORT_RETENTION_DAYS = 90;
const VIRTUSPHERE_PACKAGE_REPORT_TEXT_MAX_CHARS = 255;
const VIRTUSPHERE_PACKAGE_REPORT_PATH_MAX_CHARS = 1024;

const VIRTUSPHERE_PACKAGE_REPORT_EVENT_STARTED = 'started';
const VIRTUSPHERE_PACKAGE_REPORT_EVENT_STEP = 'step_result';
const VIRTUSPHERE_PACKAGE_REPORT_EVENT_COMPLETED = 'completed';
const VIRTUSPHERE_PACKAGE_REPORT_EVENTS = [
    VIRTUSPHERE_PACKAGE_REPORT_EVENT_STARTED,
    VIRTUSPHERE_PACKAGE_REPORT_EVENT_STEP,
    VIRTUSPHERE_PACKAGE_REPORT_EVENT_COMPLETED,
];

const VIRTUSPHERE_PACKAGE_REPORT_STEP_RESULTS = ['ok', 'skip', 'fail'];
const VIRTUSPHERE_PACKAGE_REPORT_CONTEXTS = ['system', 'user'];
const VIRTUSPHERE_PACKAGE_REPORT_WRAPPER_RESULTS = [
    'ok',
    'failed',
    'reboot_required',
    'reboot_initiated',
];
const VIRTUSPHERE_PACKAGE_REPORT_DETECTION_RESULTS = [
    'written',
    'failed',
    'not_attempted',
];
