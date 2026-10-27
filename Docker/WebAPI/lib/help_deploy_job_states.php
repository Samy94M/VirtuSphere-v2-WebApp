<?php
// Help-page helper (R17), required by the deploy help panel lib/help/deploy.php.
// It lives beside lib/help_page.php for the reason given in lib/help_figure.php.
// Not directly reachable (nginx denies /lib/).
//
// Draws "Zustände eines Auftrags": every job status of the active and terminal
// sets, worded by deploy_job_status_label() and toned by
// deploy_job_status_badge_class(), the cancellable ones marked, and the
// transitions between them. The subject source is the Mermaid chart
// „Auftragszustände“ in docs/operations/deploy-flows.md; this figure is its
// portal twin, and DeployJobStatesSvgRenderTest compares both edge sets.
declare(strict_types=1);

require_once __DIR__ . '/help_figure.php';
require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/deploy_display.php';
require_once __DIR__ . '/layout_presenters.php';

/**
 * The transitions a job can take. Who writes each one is described in
 * docs/operations/deploy-flows.md; the test keeps both lists equal. Named
 * fields, not a [from, to] pair: a pair of the queued and running constants
 * reads like a hand-written active-status list (DeployConvergenceContractTest).
 *
 * @return list<array{from:string, to:string}>
 */
function help_deploy_job_state_transitions(): array
{
    return [
        ['from' => VIRTUSPHERE_DEPLOY_STATUS_QUEUED, 'to' => VIRTUSPHERE_DEPLOY_STATUS_RUNNING],
        ['from' => VIRTUSPHERE_DEPLOY_STATUS_QUEUED, 'to' => VIRTUSPHERE_DEPLOY_STATUS_CANCELLED],
        ['from' => VIRTUSPHERE_DEPLOY_STATUS_RUNNING, 'to' => VIRTUSPHERE_DEPLOY_STATUS_CANCELLING],
        ['from' => VIRTUSPHERE_DEPLOY_STATUS_RUNNING, 'to' => VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED],
        ['from' => VIRTUSPHERE_DEPLOY_STATUS_RUNNING, 'to' => VIRTUSPHERE_DEPLOY_STATUS_PARTIAL],
        ['from' => VIRTUSPHERE_DEPLOY_STATUS_RUNNING, 'to' => VIRTUSPHERE_DEPLOY_STATUS_FAILED],
        ['from' => VIRTUSPHERE_DEPLOY_STATUS_RUNNING, 'to' => VIRTUSPHERE_DEPLOY_STATUS_OUTCOME_UNKNOWN],
        ['from' => VIRTUSPHERE_DEPLOY_STATUS_CANCELLING, 'to' => VIRTUSPHERE_DEPLOY_STATUS_CANCELLED],
    ];
}

/**
 * Where each status is drawn: [column, top] in SVG user units. Active states on
 * the left, terminal ones on the right; the four results of a run face the
 * running state and the cancelled state faces the cancel request. A status
 * without a place throws, so a new status cannot silently go undrawn.
 *
 * @return array{0:int, 1:int}
 */
function help_deploy_job_state_place(string $status): array
{
    return match ($status) {
        VIRTUSPHERE_DEPLOY_STATUS_QUEUED => [0, 30],
        VIRTUSPHERE_DEPLOY_STATUS_RUNNING => [0, 120],
        VIRTUSPHERE_DEPLOY_STATUS_CANCELLING => [0, 270],
        VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED => [1, 30],
        VIRTUSPHERE_DEPLOY_STATUS_PARTIAL => [1, 90],
        VIRTUSPHERE_DEPLOY_STATUS_FAILED => [1, 150],
        VIRTUSPHERE_DEPLOY_STATUS_OUTCOME_UNKNOWN => [1, 210],
        VIRTUSPHERE_DEPLOY_STATUS_CANCELLED => [1, 270],
        default => throw new LogicException('Job status ' . $status . ' has no place in the help figure.'),
    };
}

/** The figure's text alternative: both groups, then every transition. */
function help_deploy_job_states_description(): string
{
    $transitions = array_map(
        static fn (array $edge): string => __t('help_deploy_figures.job_states_transition', [
            'from' => deploy_job_status_label($edge['from']),
            'to' => deploy_job_status_label($edge['to']),
        ]),
        help_deploy_job_state_transitions()
    );

    return __t('help_deploy_figures.job_states_desc', [
        'active' => implode(', ', array_map('deploy_job_status_label', VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES)),
        'cancellable' => implode(', ', array_map('deploy_job_status_label', VIRTUSPHERE_DEPLOY_JOB_CANCELLABLE_STATUSES)),
        'terminal' => implode(', ', array_map('deploy_job_status_label', VIRTUSPHERE_DEPLOY_JOB_TERMINAL_STATUSES)),
        'transitions' => implode('; ', $transitions),
    ]);
}

/**
 * The figure as an HTML string (built, not echoed). Every text goes through h().
 */
function help_deploy_job_states_figure(): string
{
    // Geometry in SVG user units: two columns of boxes, a gutter on the left for
    // the one edge that runs from the queue straight to the cancelled state.
    $width = 400;
    $columnX = [14, 226];
    $boxWidth = 164;
    $boxHeight = 44;
    $headingBaseline = 18;
    $textInset = 10;
    $gutterX = 6;
    $gutterY = 334;
    $height = 344;

    $box = static function (string $status) use ($columnX, $boxWidth, $boxHeight): array {
        [$column, $top] = help_deploy_job_state_place($status);
        $left = $columnX[$column];

        return ['column' => $column, 'left' => $left, 'top' => $top, 'right' => $left + $boxWidth, 'bottom' => $top + $boxHeight,
            'midX' => $left + intdiv($boxWidth, 2), 'midY' => $top + intdiv($boxHeight, 2)];
    };

    $hint = __t('help_deploy_figures.state_cancellable');

    ob_start();
    ?>
<text class="help-fig-heading" x="<?php echo $columnX[0]; ?>" y="<?php echo $headingBaseline; ?>"><?php echo h(__t('help_deploy_figures.group_active')); ?></text>
<text class="help-fig-heading" x="<?php echo $columnX[1]; ?>" y="<?php echo $headingBaseline; ?>"><?php echo h(__t('help_deploy_figures.group_terminal')); ?></text>
<?php foreach (help_deploy_job_state_transitions() as ['from' => $from, 'to' => $to]) {
        $a = $box($from);
        $b = $box($to);
        if ($from === VIRTUSPHERE_DEPLOY_STATUS_QUEUED && $to === VIRTUSPHERE_DEPLOY_STATUS_CANCELLED) {
            // Around the other active states, so it crosses no box and no edge.
            $path = sprintf('M%d %dH%dV%dH%dV%d', $a['left'], $a['midY'], $gutterX, $gutterY, $b['midX'], $b['bottom'] + 1);
        } elseif ($a['column'] === $b['column']) {
            $path = sprintf('M%d %dV%d', $a['midX'], $a['bottom'], $b['top'] - 1);
        } else {
            $path = sprintf('M%d %dL%d %d', $a['right'], $a['midY'], $b['left'] - 1, $b['midY']);
        }
        ?>
<path class="help-fig-edge" d="<?php echo $path; ?>" <?php echo help_figure_marker_end('job-states'); ?>></path>
<?php } ?>
<?php foreach ([['active', VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES], ['terminal', VIRTUSPHERE_DEPLOY_JOB_TERMINAL_STATUSES]] as [$group, $statuses]) {
        foreach ($statuses as $status) {
            $node = $box($status);
            $cancellable = in_array($status, VIRTUSPHERE_DEPLOY_JOB_CANCELLABLE_STATUSES, true);
            ?>
<g class="help-fig-node help-fig-node-<?php echo $group; ?> help-fig-tone-<?php echo h(deploy_job_status_badge_class($status)); ?>">
<rect class="help-fig-box" x="<?php echo $node['left']; ?>" y="<?php echo $node['top']; ?>" width="<?php echo $boxWidth; ?>" height="<?php echo $boxHeight; ?>" rx="8"></rect>
<text class="help-fig-label" x="<?php echo $node['left'] + $textInset; ?>" y="<?php echo $node['top'] + ($cancellable ? 19 : 27); ?>"><?php echo h(deploy_job_status_label($status)); ?></text>
<?php if ($cancellable) { ?>
<text class="help-fig-caption" x="<?php echo $node['left'] + $textInset; ?>" y="<?php echo $node['top'] + 36; ?>"><?php echo h($hint); ?></text>
<?php } ?>
</g>
<?php
        }
    }
    $body = (string) ob_get_clean();

    return help_figure(
        'help-fig',
        'job-states',
        $width,
        $height,
        __t('help_deploy_figures.job_states_heading'),
        help_deploy_job_states_description(),
        $body,
        __t('help_deploy_figures.job_states_note')
    );
}
