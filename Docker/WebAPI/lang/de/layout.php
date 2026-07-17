<?php

declare(strict_types=1);

/**
 * Portal-Grundgerüst: Navigation, Topbar, Rollen, Session-UI, Fehlerabbildung (ADR-0014).
 */
return [
    // Navigation
    'nav_dashboard' => 'Dashboard',
    'nav_missions' => 'Missionen',
    'nav_templates' => 'Vorlagen',
    'nav_deploy' => 'Bereitstellung',
    'nav_integrations' => 'Integrationen',
    'nav_help' => 'Hilfe',
    'help_page_link' => 'Hilfe',
    'help_page_title' => 'Hilfe zu dieser Seite öffnen',
    'nav_os' => 'Betriebssysteme',
    'nav_vlans' => 'VLANs',
    'nav_packages' => 'Pakete',
    'nav_credentials' => 'Zugangsdaten',
    'nav_settings' => 'Einstellungen',
    'nav_users' => 'Benutzer',
    'nav_logs' => 'Protokolle',
    'nav_primary_label' => 'Hauptnavigation',
    'nav_toggle' => 'Menü umschalten',
    'skip_to_content' => 'Zum Inhalt springen',
    'nav_group_operations' => 'Betrieb',
    'nav_group_catalog' => 'Katalog',
    'nav_group_admin' => 'Verwaltung',

    // Rollen
    'role_admin' => 'Administrator',
    'role_user' => 'Benutzer',

    // Topbar
    'account' => 'Konto',
    'theme' => 'Design',
    'theme_title' => 'Darstellung umschalten',
    'logout' => 'Abmelden',

    // Session-UI
    'session_title' => 'Sitzung',
    'session_extend' => 'Session verlängern',
    'session_expiring_title' => 'Sitzung läuft bald ab',
    'session_countdown_html' => 'Deine Session läuft in {n} Sekunden ab.',
    'logout_now' => 'Jetzt abmelden',

    // Fehlerabbildung (portal_error_message)
    'err_user_name_taken' => 'Dieser Benutzername ist bereits vergeben.',
    // Greift erst, wenn zwei Anfragen gleichzeitig denselben Namen einfügen und
    // der Unique-Index gewinnt. Bewusst neutral: hier ist nicht mehr bekannt, ob
    // es um eine Mission oder eine Vorlage ging.
    'err_mission_name_taken' => 'Dieser Name ist bereits vergeben.',
    'err_entry_exists' => 'Dieser Eintrag existiert bereits.',
    'err_db_generic' => 'Die Datenbank konnte die Aktion nicht speichern. Bitte prüfe die Eingaben.',
    'err_action_failed' => 'Die Aktion konnte nicht abgeschlossen werden.',
];
