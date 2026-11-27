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
    'nav_system_status' => 'Systemstatus',
    'nav_help' => 'Hilfe',
    'help_page_link' => 'Hilfe',
    'help_page_title' => 'Hilfe zu dieser Seite öffnen',
    'nav_os' => 'Betriebssysteme',
    'nav_vlans' => 'Portgruppen',
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
    'theme_title' => 'Design umschalten',
    'logout' => 'Abmelden',

    // Session-UI
    'session_title' => 'Sitzung',
    'session_extend' => 'Sitzung verlängern',
    'session_expiring_title' => 'Sitzung läuft bald ab',
    'session_countdown_html' => 'Ihre Sitzung läuft in {n} Sekunden ab.',
    'logout_now' => 'Jetzt abmelden',

    // Fehlerabbildung (portal_error_message)
    'err_user_name_taken' => 'Dieser Benutzername ist bereits vergeben.',
    // Greift erst, wenn zwei Anfragen gleichzeitig denselben Namen einfügen und
    // der Unique-Index gewinnt. Bewusst neutral: hier ist nicht mehr bekannt, ob
    // es um eine Mission oder eine Vorlage ging.
    'err_mission_name_taken' => 'Dieser Name ist bereits vergeben.',
    'err_entry_exists' => 'Dieser Eintrag existiert bereits.',
    // Eine Mission oder eine ihrer VMs löschen, während ein Auftrag dieser
    // Mission läuft oder wartet. Bewusst für beide Fälle ein Satz: es ist
    // derselbe Grund, und die Ablehnung ist hart (kein stiller Abbruch des
    // Auftrags), damit niemandes laufender Deploy nebenbei beendet wird.
    'err_mission_active_job' => 'Für diese Mission läuft oder wartet ein Bereitstellungsauftrag. Den Auftrag zuerst abwarten oder abbrechen.',
    'err_esxi_write_license' => 'Der gewählte ESXi-Host meldet eine kostenlose Lizenz: Seine API ist nur lesend, deshalb lässt sich dieser Modus nicht ausführen. Nur der MAC-Export und der Inventarabruf sind möglich. Nach einem Lizenzwechsel aktualisieren Sie das ESXi-Inventar.',
    // Create-Urteil aus der gemeldeten ESXi-Version (ADR-0023 Nachtrag 7).
    'err_esxi_create_host' => 'Auf dem gewählten ESXi-Host lassen sich keine VMs anlegen: Er meldet ESXi :release, VirtuSphere legt VMs ab ESXi :floor an. Nach einem Host-Update aktualisieren Sie das ESXi-Inventar.',
    'err_esxi_create_guest' => 'VM :name: Das Guest OS :os braucht mindestens ESXi :floor, der gewählte Host meldet ESXi :release. Wählen Sie in der VM ein anderes Guest OS oder verwenden Sie einen neueren Host.',
    // Unerwarteter Fehler einer Portal-Aktion (R18). :ref ist die Korrelations-ID
    // der Anfrage, dieselbe wie in der Serverprotokoll-Zeile (C18).
    'err_unexpected' => 'Die Aktion konnte nicht abgeschlossen werden. Nennen Sie dem Administrator die Korrelations-ID :ref, damit der Fehler im Serverprotokoll gefunden wird.',
    'err_action_failed' => 'Die Aktion konnte nicht abgeschlossen werden.',
];
