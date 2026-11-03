<?php
// Help-page helper (R17): the shared frame of the help figures (lib/help_vm_path.php,
// lib/help_deploy_modes.php, lib/help_deploy_job_states.php). It lives beside
// lib/help_page.php and not in lib/help/, because that directory holds exactly
// the registered panel partials (HelpAnchorContractTest, PortalClosureSource).
// Not directly reachable (nginx denies /lib/).
//
// A help figure is static inline SVG without script: the portal is air-gapped
// and CSP-bound, so no Mermaid runs here, and no inline style or handler is
// written. The frame owns what every figure needs to be read by a screen reader
// (role, title, desc and their IDs) and the arrow marker; each figure draws only
// its own body.
declare(strict_types=1);

/**
 * One help figure as an HTML string (built, not echoed).
 *
 * $block names the CSS block: the figure carries it, the svg `<block>-svg` and
 * the arrowhead `<block>-arrowhead`. $id prefixes the element IDs
 * (`<id>-title`, `<id>-desc`, `<id>-arrow`), so two figures on one panel never
 * share an ID. $body is trusted SVG markup the caller built with h(); every
 * other text is escaped here.
 */
function help_figure(string $block, string $id, int $width, int $height, string $title, string $description, string $body, string $note): string
{
    $titleId = $id . '-title';
    $descId = $id . '-desc';

    return '<figure class="' . h($block) . '">' . "\n"
        . '<svg class="' . h($block) . '-svg" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-labelledby="'
        . h($titleId) . ' ' . h($descId) . '" focusable="false">' . "\n"
        . '<title id="' . h($titleId) . '">' . h($title) . '</title>' . "\n"
        . '<desc id="' . h($descId) . '">' . h($description) . '</desc>' . "\n"
        . '<defs><marker id="' . h(help_figure_arrow_id($id)) . '" viewBox="0 0 10 10" refX="10" refY="5" markerWidth="6" markerHeight="6" orient="auto">'
        . '<path class="' . h($block) . '-arrowhead" d="M0 0L10 5L0 10z"></path></marker></defs>' . "\n"
        . $body
        . '</svg>' . "\n"
        . '<figcaption class="muted">' . h($note) . '</figcaption>' . "\n"
        . '</figure>' . "\n";
}

/** The ID of a figure's arrow marker, for `marker-end="url(#...)"` in its body. */
function help_figure_arrow_id(string $id): string
{
    return $id . '-arrow';
}

/** A `marker-end` attribute pointing at the figure's arrow marker. */
function help_figure_marker_end(string $id): string
{
    return 'marker-end="url(#' . h(help_figure_arrow_id($id)) . ')"';
}
