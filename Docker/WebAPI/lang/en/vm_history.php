<?php

declare(strict_types=1);

return [
    'title' => 'History',
    'open_full' => 'Full history',
    'back' => 'Back to the VM',
    'intro' => 'The stored events of this VM from the selected sources, newest first. What a source removed after its retention period is missing here; a gap is not an event.',
    'filter_heading' => 'Selection',
    'filter_sources' => 'Sources',
    'filter_sources_hint' => 'Without a selection, every source is shown.',
    'filter_from' => 'From',
    'filter_to' => 'To',
    'filter_date_hint' => 'Days are read in the time zone :tz.',
    'filter_apply' => 'Apply',
    'filter_reset' => 'Reset',
    'error_date' => 'Enter a date as YYYY-MM-DD.',
    'error_range' => 'The start date is after the end date. The range was not applied.',
    'source_audit' => 'Change log',
    'source_failed' => 'The source :source could not be read. Its entries are missing from this view; the other sources are shown.',
    'cursor_invalid' => 'The paging position was invalid. The newest entries are shown.',
    'snapshot_hint' => 'This page continues the view from when it was first opened. "Show newest" shows what has been added since.',
    'older' => 'Older entries',
    'newest' => 'Show newest',
    'empty' => 'There are no entries for this selection.',
    'retention_heading' => 'Retention',
    'retention_days' => ':source: entries are removed after :days days.',
    'retention_none' => ':source: no age limit while the mission exists.',
];
