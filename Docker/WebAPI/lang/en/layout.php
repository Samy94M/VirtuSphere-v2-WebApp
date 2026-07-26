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
    'nav_system_status' => 'System status',
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
    'skip_to_content' => 'Skip to content',
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
    // Deleting a mission or one of its VMs while a job of that mission is queued
    // or running. One sentence for both on purpose: it is the same reason, and
    // the refusal is hard (no implicit cancel), so nobody's running deploy is
    // ended as a side effect.
    'err_mission_active_job' => 'A deploy job for this mission is queued or running. Cancel it in the deploy list first; while it is open, nothing here can be deleted.',
    // The retry button in the job list re-runs the same enqueue gate, but it has
    // no form and therefore no field message. Without this entry the portal
    // renders the raw English exception there. The sentence names both causes,
    // because the retry path does not know which of the two applies.
    'err_datacenter_unresolved' => 'The datacenter cannot be determined: the mission has none and the selected ESXi credential does not report exactly one (no inventory yet, or several). Set the datacenter on the mission, or refresh the inventory under System status.',
    'err_db_generic' => 'The database could not save the action. Please check your input.',
    'err_action_failed' => 'The action could not be completed.',
];
