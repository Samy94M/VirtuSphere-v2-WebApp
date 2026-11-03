<?php
// Help-page helper (R17), required by the deploy help panel lib/help/deploy.php.
// It lives beside lib/help_page.php for the reason given in lib/help_figure.php.
// Not directly reachable (nginx denies /lib/).
//
// Draws "Was jeder Bereitstellungsmodus ausführt": one row per mode an operator
// can queue (virtusphere_user_deploy_modes()), one numbered column per step, and
// in each row exactly the steps ansible_playbooks_for_mode() runs for that mode.
// A step only the mission's autostart adds is drawn dashed. The figure names
// steps, never playbook files; docs/operations/deploy-flows.md names those.
declare(strict_types=1);

require_once __DIR__ . '/help_figure.php';
require_once __DIR__ . '/defaults.php';
require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/deploy_mode_labels.php';
require_once __DIR__ . '/ansible_command_modes.php';

const HELP_DEPLOY_MODES_STEP_ON = 'on';
const HELP_DEPLOY_MODES_STEP_CONDITIONAL = 'cond';
const HELP_DEPLOY_MODES_STEP_OFF = 'off';

/**
 * The playbook keys a mode runs, in run order. A sequence entry that is not a
 * VIRTUSPHERE_PLAYBOOKS file would be a step without a name, so it throws.
 *
 * @return list<string>
 */
function help_deploy_modes_steps(string $mode, bool $autostartEnabled): array
{
    $keys = [];
    foreach (ansible_playbooks_for_mode($mode, $autostartEnabled) as $file) {
        $key = array_search($file, VIRTUSPHERE_PLAYBOOKS, true);
        if (!is_string($key)) {
            throw new LogicException('Deploy mode ' . $mode . ' runs a playbook without a step name.');
        }
        $keys[] = $key;
    }

    return $keys;
}

/**
 * The step columns: every playbook key a user mode runs, in VIRTUSPHERE_PLAYBOOKS
 * order, which is also the order of the full pipeline.
 *
 * @return list<string>
 */
function help_deploy_modes_columns(): array
{
    $used = [];
    foreach (virtusphere_user_deploy_modes() as $mode) {
        $used = array_merge($used, help_deploy_modes_steps($mode, true));
    }

    return array_values(array_filter(
        array_keys(VIRTUSPHERE_PLAYBOOKS),
        static fn (string $key): bool => in_array($key, $used, true)
    ));
}

/**
 * Per user mode the state of every column: run always, run only with the
 * mission's autostart, or not run.
 *
 * @return list<array{mode:string, steps:list<string>}>
 */
function help_deploy_modes_rows(): array
{
    $columns = help_deploy_modes_columns();
    $rows = [];
    foreach (virtusphere_user_deploy_modes() as $mode) {
        $always = help_deploy_modes_steps($mode, false);
        $withAutostart = help_deploy_modes_steps($mode, true);
        $steps = [];
        foreach ($columns as $key) {
            $steps[] = match (true) {
                in_array($key, $always, true) => HELP_DEPLOY_MODES_STEP_ON,
                in_array($key, $withAutostart, true) => HELP_DEPLOY_MODES_STEP_CONDITIONAL,
                default => HELP_DEPLOY_MODES_STEP_OFF,
            };
        }
        $rows[] = ['mode' => $mode, 'steps' => $steps];
    }

    return $rows;
}

/** The figure's text alternative: the steps, then each mode with what it runs. */
function help_deploy_modes_description(array $columns, array $rows): string
{
    $modes = [];
    foreach ($rows as $row) {
        $names = [];
        foreach ($row['steps'] as $column => $state) {
            $name = __t('help_deploy_figures.step_' . $columns[$column]);
            if ($state === HELP_DEPLOY_MODES_STEP_ON) {
                $names[] = $name;
            } elseif ($state === HELP_DEPLOY_MODES_STEP_CONDITIONAL) {
                $names[] = __t('help_deploy_figures.modes_desc_conditional', ['step' => $name]);
            }
        }
        $modes[] = __t('help_deploy_figures.modes_desc_mode', [
            'mode' => deploy_mode_label($row['mode']),
            'steps' => implode(', ', $names),
        ]);
    }

    return __t('help_deploy_figures.modes_desc', [
        'steps' => implode(', ', array_map(static fn (string $key): string => __t('help_deploy_figures.step_' . $key), $columns)),
        'modes' => implode(' ', $modes),
    ]);
}

/**
 * The figure as an HTML string (built, not echoed). Every text goes through h().
 */
function help_deploy_modes_figure(): string
{
    $columns = help_deploy_modes_columns();
    $rows = help_deploy_modes_rows();
    $columnCount = count($columns);

    // Geometry in SVG user units: a numbered legend on top, then one box per
    // mode with its label and one slot per step column.
    $width = 380;
    $inset = 8;
    $legendHeadingBaseline = 22;
    $legendTop = 36;
    $legendPitch = 24;
    $legendNumberX = 24;
    $legendTextX = 44;
    $rowsTop = $legendTop + $columnCount * $legendPitch + $inset;
    $rowHeight = 62;
    $rowPitch = 70;
    $rowLabelX = 20;
    $rowLabelBaseline = 20;
    $slotBaseline = 44;
    $slotMargin = 40;
    $slotPitch = $columnCount > 1 ? intdiv($width - 2 * $slotMargin, $columnCount - 1) : 0;
    $ringRadius = 12;
    $dotRadius = 4;
    $height = $rowsTop + count($rows) * $rowPitch - ($rowPitch - $rowHeight) + $inset;

    ob_start();
    ?>
<g class="help-fig-legend">
<text class="help-fig-heading" x="<?php echo $inset; ?>" y="<?php echo $legendHeadingBaseline; ?>"><?php echo h(__t('help_deploy_figures.modes_legend')); ?></text>
<?php foreach ($columns as $column => $key) {
        $y = $legendTop + $column * $legendPitch;
        ?>
<circle class="help-fig-ring" cx="<?php echo $legendNumberX; ?>" cy="<?php echo $y; ?>" r="10"></circle>
<text class="help-fig-num" x="<?php echo $legendNumberX; ?>" y="<?php echo $y; ?>"><?php echo $column + 1; ?></text>
<text class="help-fig-legend-text" x="<?php echo $legendTextX; ?>" y="<?php echo $y; ?>"><?php echo h(__t('help_deploy_figures.step_' . $key)); ?></text>
<?php } ?>
</g><!-- legend -->
<?php foreach ($rows as $index => $row) {
        $top = $rowsTop + $index * $rowPitch;
        $slotY = $top + $slotBaseline;
        ?>
<g class="help-fig-row">
<rect class="help-fig-box" x="<?php echo $inset; ?>" y="<?php echo $top; ?>" width="<?php echo $width - 2 * $inset; ?>" height="<?php echo $rowHeight; ?>" rx="8"></rect>
<text class="help-fig-label" x="<?php echo $rowLabelX; ?>" y="<?php echo $top + $rowLabelBaseline; ?>"><?php echo h(deploy_mode_label($row['mode'])); ?></text>
<?php
        $previous = null;
        foreach ($row['steps'] as $column => $state) {
            $x = $slotMargin + $column * $slotPitch;
            if ($state !== HELP_DEPLOY_MODES_STEP_OFF && $previous !== null) {
                $connectorClass = $state === HELP_DEPLOY_MODES_STEP_CONDITIONAL ? 'help-fig-connector help-fig-connector-cond' : 'help-fig-connector';
                ?>
<line class="<?php echo $connectorClass; ?>" x1="<?php echo $slotMargin + $previous * $slotPitch + $ringRadius; ?>" y1="<?php echo $slotY; ?>" x2="<?php echo $x - $ringRadius - 1; ?>" y2="<?php echo $slotY; ?>" <?php echo help_figure_marker_end('deploy-modes'); ?>></line>
<?php
            }
            ?>
<g class="help-fig-step help-fig-step-<?php echo $state; ?>">
<?php if ($state === HELP_DEPLOY_MODES_STEP_OFF) { ?>
<circle class="help-fig-dot" cx="<?php echo $x; ?>" cy="<?php echo $slotY; ?>" r="<?php echo $dotRadius; ?>"></circle>
<?php } else { ?>
<circle class="help-fig-ring" cx="<?php echo $x; ?>" cy="<?php echo $slotY; ?>" r="<?php echo $ringRadius; ?>"></circle>
<text class="help-fig-num" x="<?php echo $x; ?>" y="<?php echo $slotY; ?>"><?php echo $column + 1; ?></text>
<?php } ?>
</g>
<?php
            if ($state !== HELP_DEPLOY_MODES_STEP_OFF) {
                $previous = $column;
            }
        }
        ?>
</g><!-- row -->
<?php } ?>
<?php
    $body = (string) ob_get_clean();

    return help_figure(
        'help-fig',
        'deploy-modes',
        $width,
        $height,
        __t('help_deploy_figures.modes_heading'),
        help_deploy_modes_description($columns, $rows),
        $body,
        __t('help_deploy_figures.modes_note')
    );
}
