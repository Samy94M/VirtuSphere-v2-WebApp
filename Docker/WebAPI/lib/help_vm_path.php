<?php
// Help-page helper (R17), required by the missions help panel
// lib/help/missions.php. It lives beside lib/help_page.php and not in lib/help/,
// because that directory holds exactly the registered panel partials
// (HelpAnchorContractTest, PortalClosureSource). Not directly reachable (nginx
// denies /lib/).
//
// Draws "Der Weg einer VM": the derived VM phases of lib/vm_phase.php in rank
// order, one numbered step each, coloured by the phase badge of
// virtusphere_vm_phase_meta() and worded by portal_vm_phase_label() and the
// help_vm_path catalog. The frame (figure, accessible svg, arrow marker) comes
// from lib/help_figure.php. The Mermaid twin of the same path lives in
// docs/operations/vm-lifecycle.md.
declare(strict_types=1);

require_once __DIR__ . '/help_figure.php';
require_once __DIR__ . '/vm_phase.php';
require_once __DIR__ . '/portal_status_display.php';

/**
 * The figure as an HTML string (built, not echoed). Every text goes through h();
 * the step colour is a badge token of virtusphere_vm_phase_meta().
 */
function help_vm_path_figure(): string
{
    $phases = VIRTUSPHERE_VM_PHASES;
    $count = count($phases);

    // Geometry in SVG user units. Each step is a box of $boxHeight; the next box
    // starts $pitch below it, and the connector bridges the gap between them.
    $width = 360;
    $inset = 8;
    $boxWidth = $width - 2 * $inset;
    $boxHeight = 56;
    $pitch = 76;
    $height = $inset + $count * $pitch - ($pitch - $boxHeight) + $inset;
    $centreX = intdiv($width, 2);
    $numberX = 34;
    $numberRadius = 14;
    $textX = 60;
    $labelBaseline = 24;
    $captionBaseline = 44;
    $connectorEnd = $pitch - 2;

    $description = __t('help_vm_path.desc', [
        'phases' => implode(', ', array_map('portal_vm_phase_label', $phases)),
    ]);

    ob_start();
    foreach ($phases as $index => $phase) {
        $y = $inset + $index * $pitch;
        $midY = $y + intdiv($boxHeight, 2);
        $badge = virtusphere_vm_phase_meta($phase)['badge'];
        ?>
<g class="vm-path-step vm-path-step-<?php echo h($badge); ?>">
<rect class="vm-path-box" x="<?php echo $inset; ?>" y="<?php echo $y; ?>" width="<?php echo $boxWidth; ?>" height="<?php echo $boxHeight; ?>" rx="8"></rect>
<circle class="vm-path-num-ring" cx="<?php echo $numberX; ?>" cy="<?php echo $midY; ?>" r="<?php echo $numberRadius; ?>"></circle>
<text class="vm-path-num" x="<?php echo $numberX; ?>" y="<?php echo $midY; ?>"><?php echo $index + 1; ?></text>
<text class="vm-path-label" x="<?php echo $textX; ?>" y="<?php echo $y + $labelBaseline; ?>"><?php echo h(portal_vm_phase_label($phase)); ?></text>
<text class="vm-path-caption" x="<?php echo $textX; ?>" y="<?php echo $y + $captionBaseline; ?>"><?php echo h(__t('help_vm_path.caption_' . $phase)); ?></text>
</g>
<?php if ($index < $count - 1) { ?>
<line class="vm-path-connector" x1="<?php echo $centreX; ?>" y1="<?php echo $y + $boxHeight; ?>" x2="<?php echo $centreX; ?>" y2="<?php echo $y + $connectorEnd; ?>" <?php echo help_figure_marker_end('vm-path'); ?>></line>
<?php } ?>
<?php
    }
    $body = (string) ob_get_clean();

    return help_figure('vm-path', 'vm-path', $width, $height, __t('help_vm_path.heading'), $description, $body, __t('help_vm_path.note'));
}
