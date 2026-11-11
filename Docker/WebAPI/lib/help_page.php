<?php

declare(strict_types=1);

const VIRTUSPHERE_HELP_PANELS = [
    'overview' => ['label' => 'help.tab_overview', 'permission' => '', 'partial' => 'overview.php'],
    'missions' => ['label' => 'help.tab_missions', 'permission' => '', 'partial' => 'missions.php'],
    'packages' => ['label' => 'help.tab_packages', 'permission' => '', 'partial' => 'packages.php'],
    'deploy' => ['label' => 'help.tab_deploy', 'permission' => 'deploy.run', 'partial' => 'deploy.php'],
    'system-status' => ['label' => 'help.tab_system_status', 'permission' => '', 'partial' => 'system_status.php'],
    'users' => ['label' => 'help.tab_users', 'permission' => '', 'partial' => 'users.php'],
    'credentials' => ['label' => 'help.tab_credentials', 'permission' => 'credentials.manage', 'partial' => 'credentials.php'],
    'settings' => ['label' => 'help.tab_settings', 'permission' => 'system.config', 'partial' => 'settings.php'],
    'stack' => ['label' => 'help.tab_stack', 'permission' => '', 'partial' => 'stack.php'],
];

const VIRTUSPHERE_HELP_SECTIONS = [
    'help-backup' => 'stack',
    'help-unsaved-changes' => 'overview',
    'help-copying-values' => 'overview',
    // The dashboard numbers and the overview they open: what is counted, when,
    // and why a list can differ from the tile it was opened from.
    'help-dashboard-counts' => 'overview',
    'help-effective-values' => 'missions',
    'help-work-context' => 'missions',
    // The path of a VM as a drawing: the derived phases in rank order. The
    // status section below it explains each phase and finding in words.
    'help-vm-path' => 'missions',
    'help-status-overview' => 'system-status',
    // The status vocabulary (SY2): every tone with its word, colour and icon.
    'help-status-vocabulary' => 'system-status',
    'help-status-deploy' => 'system-status',
    'help-status-directory' => 'system-status',
    'help-status-host' => 'system-status',
    'help-status-mecm' => 'system-status',
    // The per-cause steps of the device sync. A punctual run with open points
    // links here from its card instead of repeating the cause list there.
    'help-status-device-causes' => 'system-status',
    'help-status-esxi' => 'system-status',
    // The deploy service is explained ONCE, in the deploy help, although its
    // card lives on System status and its expectation sentence on the deploy
    // page. Two texts about one state machine drift; a link does not.
    'help-deploy-service' => 'deploy',
    // The queue blocker for an oversized job scope links here. Its own sentence
    // says what to do (split the selection) and carries the link that does it;
    // what it cannot carry is WHY there is a ceiling at all, and that answer
    // belongs in one place rather than in every blocker message.
    'help-network-contract' => 'deploy',
    // The per-VM create progress, and above all what an unresolved unit means.
    // The card on the job log states the fact and links here; it must not also
    // explain, because the explanation is four paragraphs long and would then
    // sit inside a card that a reader opens to count VMs.
    'help-create-progress' => 'deploy',
    'help-action-outcomes' => 'deploy',
    // Two drawings in the deploy help: the steps each mode runs (from
    // ansible_playbooks_for_mode()) and the states of a job (from the status
    // sets; the Mermaid twin is in docs/operations/deploy-flows.md).
    'help-deploy-modes' => 'deploy',
    'help-deploy-job-states' => 'deploy',
    // The APP_KEY error texts of the directory page link here (R11). The
    // rotation steps themselves live in the operations document.
    'help-app-key' => 'stack',
    // How to read the audit log text column: the stored sentences are English
    // with technical codes, and nothing rewrites them.
    'help-log-texts' => 'system-status',
    // The VLAN catalog and why it is read-only. Its section sits in the System
    // status panel because the catalog reads what the inventory reads.
    'help-vlans' => 'system-status',
];

function help_panel_visible(string $panel, array $user): bool
{
    if (!array_key_exists($panel, VIRTUSPHERE_HELP_PANELS)) {
        return false;
    }
    $permission = VIRTUSPHERE_HELP_PANELS[$panel]['permission'];

    return $permission === '' || can($permission, $user);
}

/** @return array<string,string> */
function help_tabs_for_user(array $user): array
{
    $tabs = [];
    foreach (VIRTUSPHERE_HELP_PANELS as $panel => $definition) {
        if (help_panel_visible($panel, $user)) {
            $tabs[$panel] = __t($definition['label']);
        }
    }

    return $tabs;
}

function help_url(string $panel, ?string $section = null): string
{
    if (!array_key_exists($panel, VIRTUSPHERE_HELP_PANELS)) {
        throw new InvalidArgumentException('Unknown help panel.');
    }
    if ($section === null) {
        return 'help.php#panel-' . $panel;
    }
    if ((VIRTUSPHERE_HELP_SECTIONS[$section] ?? null) !== $panel) {
        throw new InvalidArgumentException('Unknown help section for panel.');
    }

    return 'help.php#' . $section;
}

/**
 * Stack FAQ questions whose answer list is a numbered sequence (<ol>). The kind
 * of list is the one thing the catalog keys cannot say; everything else comes
 * from the catalog through help_stack_faq().
 */
const VIRTUSPHERE_HELP_STACK_FAQ_ORDERED_LISTS = [4];

/**
 * The stack FAQ as catalog keys, read from the catalog instead of a written
 * table of counts (W3D, N1D): a written count leaves a new key unrendered and
 * nothing notices. Question n exists while help_stack.stack_q<n> does. Its answer
 * is every paragraph stack_a<n>_p1..m and, when stack_a<n>_li1 exists, one list
 * stack_a<n>_li1..k after the first paragraph; the paragraph numbers run on
 * after the list. HelpStackFaqTest proves that this shape renders every
 * stack_q/stack_a key of both catalogs and nothing else.
 *
 * @param callable(string): int $countNumbered Lang::countNumbered() for one locale
 * @return list<array{question: string, before: list<string>, list_tag: 'ol'|'ul'|null, items: list<string>, after: list<string>}>
 */
function help_stack_faq(callable $countNumbered): array
{
    $numbered = static function (string $prefix) use ($countNumbered): array {
        $keys = [];
        $count = $countNumbered($prefix);
        for ($i = 1; $i <= $count; $i++) {
            $keys[] = $prefix . $i;
        }

        return $keys;
    };

    $faq = [];
    foreach ($numbered('help_stack.stack_q') as $index => $question) {
        $answer = 'help_stack.stack_a' . ($index + 1);
        $paragraphs = $numbered($answer . '_p');
        $items = $numbered($answer . '_li');
        $split = $items === [] ? count($paragraphs) : min(1, count($paragraphs));
        $listTag = null;
        if ($items !== []) {
            $listTag = in_array($index + 1, VIRTUSPHERE_HELP_STACK_FAQ_ORDERED_LISTS, true) ? 'ol' : 'ul';
        }
        $faq[] = [
            'question' => $question,
            'before' => array_slice($paragraphs, 0, $split),
            'list_tag' => $listTag,
            'items' => $items,
            'after' => array_slice($paragraphs, $split),
        ];
    }

    return $faq;
}
