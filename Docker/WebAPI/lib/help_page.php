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
    // The deploy service is explained ONCE, in the deploy help, although its
    // card lives on System status and its expectation sentence on the deploy
    // page. Two texts about one state machine drift; a link does not.
    'help-deploy-service' => 'deploy',
    // The queue blocker for an oversized job scope links here. Its own sentence
    // says what to do (split the selection) and carries the link that does it;
    // what it cannot carry is WHY there is a ceiling at all, and that answer
    // belongs in one place rather than in every blocker message.
    'help-network-contract' => 'deploy',
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
