<?php

declare(strict_types=1);

/**
 * Portal shell: navigation, topbar, roles, session UI, error mapping (ADR-0014).
 */
return [
    // Navigation
    'nav_dashboard' => 'Dashboard',
    'nav_missions' => 'Missions',
    'nav_templates' => 'Templates',
    'nav_deploy' => 'Deploy',
    'nav_integrations' => 'Integrations',
    'nav_help' => 'Help',
    'help_page_link' => 'Help',
    'help_page_title' => 'Open help for this page',
    'nav_os' => 'Operating Systems',
    'nav_vlans' => 'VLANs',
    'nav_packages' => 'Packages',
    'nav_credentials' => 'Credentials',
    'nav_settings' => 'Settings',
    'nav_users' => 'Users',
    'nav_logs' => 'Logs',
    'nav_primary_label' => 'Primary navigation',
    'nav_toggle' => 'Toggle menu',
    'nav_group_operations' => 'Operations',
    'nav_group_catalog' => 'Catalog',
    'nav_group_admin' => 'Administration',

    // Roles
    'role_admin' => 'Administrator',
    'role_user' => 'User',

    // Topbar
    'account' => 'Account',
    'theme' => 'Theme',
    'theme_title' => 'Toggle appearance',
    'logout' => 'Log out',

    // Session UI
    'session_title' => 'Session',
    'session_extend' => 'Extend session',
    'session_expiring_title' => 'Session is expiring soon',
    'session_countdown_html' => 'Your session expires in {n} seconds.',
    'logout_now' => 'Log out now',

    // Error mapping (portal_error_message)
    'err_user_name_taken' => 'This username is already taken.',
    // Only fires when two requests insert the same name at once and the unique
    // index wins. Deliberately neutral: at this point it is no longer known
    // whether a mission or a template was being saved.
    'err_mission_name_taken' => 'This name is already taken.',
    'err_entry_exists' => 'This entry already exists.',
    'err_db_generic' => 'The database could not save the action. Please check your input.',
    'err_action_failed' => 'The action could not be completed.',
];
