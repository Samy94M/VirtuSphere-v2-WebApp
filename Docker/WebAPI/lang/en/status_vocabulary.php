<?php

declare(strict_types=1);

// The one status vocabulary (lib/status_vocabulary.php): one fixed word per
// tone, colour and icon come from the same table. Domain state, reporter state
// and activity are kept apart; legend_* explains every tone for the legend.
return [
    'ok' => 'OK',
    'warning' => 'Warning',
    'limited' => 'Limited',
    'critical' => 'Critical',
    'unknown' => 'Unknown',
    'current' => 'Current',
    'stale' => 'Stale',
    'no_data' => 'No data',
    'unreachable' => 'Unreachable',
    'disabled' => 'Disabled',
    'running' => 'Running',
    'evidence_incomplete' => 'No findings, evidence incomplete',
    'legend_heading' => 'Status words',
    'legend_intro' => 'Every status word has the same colour and the same symbol on every page. The domain state says whether an area is in order; the reporter state says whether its evidence still arrives. The headline above the overview names the areas that need attention, the most severe first.',
    'axis_level' => 'Domain state',
    'axis_reporter' => 'Reporter state',
    'axis_activity' => 'Activity',
    'legend_ok' => 'The latest evidence is current and has no finding.',
    'legend_warning' => 'Something needs your attention soon, such as an overdue run, open points or a certificate about to expire.',
    'legend_limited' => 'It works, but not completely: a part is missing or disturbed.',
    'legend_critical' => 'It is disturbed and needs an intervention before it works again.',
    'legend_unknown' => 'There is data, but not enough for a statement, for example incomplete evidence.',
    'legend_current' => 'The service reports at the expected interval.',
    'legend_stale' => 'The latest evidence is too old to say anything about today. No fault is known.',
    'legend_no_data' => 'No report, pull or test has ever arrived.',
    'legend_unreachable' => 'The service no longer reports.',
    'legend_disabled' => 'The function is switched off and is not checked.',
    'legend_running' => 'A run or job is under way right now.',
    'legend_evidence_incomplete' => 'The comparison found nothing, but did not cover every object kind with current data.',
];
