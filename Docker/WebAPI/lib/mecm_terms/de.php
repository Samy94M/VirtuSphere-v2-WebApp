<?php

declare(strict_types=1);

/**
 * MECM-Fachwörter einer deutschen MECM-Konsole (PR-R3M, i18n R15).
 *
 * Ein Begriffssatz je Konsolensprache; die Einstellung `mecm_console_language`
 * (lib/mecm_terms.php) wählt ihn je Umgebung, unabhängig von der Portalsprache.
 * Katalogwerte nennen die Wörter nur als Platzhalter `{mecm:<name>}`; die
 * Schreibweise mit großem Anfangsbuchstaben `{mecm:<Name>}` setzt den ersten
 * Buchstaben groß (Satzanfang im englischen Portal).
 *
 * Je Name zwei Formen: wie das Wort in einem deutschen Portaltext steht (`de`)
 * und wie in einem englischen (`en`). Alle Begriffssätze tragen dieselben Namen
 * (lang-audit, `[lang-audit.mecm-terms-parity]`). Die Wörter folgen der deutschen
 * Konsole von Configuration Manager, wo zwei unabhängige Quellen sie belegen;
 * sonst steht der englische Konsolentext. Quellen in den Registern
 * docs/register/2026-11-25-PR-R3M.md und docs/register/2026-11-27-PR-R3MP.md.
 *
 * Namen mit Artikel (`the_site`) gibt es nur, wo ein Satz den Artikel nicht
 * vermeiden kann: Standort (der) und Site (die) haben verschiedenes Geschlecht.
 */
return [
    'task_sequence' => ['de' => 'Tasksequenz', 'en' => 'Tasksequenz'],
    'task_sequences' => ['de' => 'Tasksequenzen', 'en' => 'Tasksequenzen'],
    'distribution_point' => ['de' => 'Verteilungspunkt', 'en' => 'Verteilungspunkt'],
    'distribution_points' => ['de' => 'Verteilungspunkte', 'en' => 'Verteilungspunkte'],
    'site' => ['de' => 'Standort', 'en' => 'Standort'],
    'sites' => ['de' => 'Standorte', 'en' => 'Standorte'],
    'the_site' => ['de' => 'der Standort', 'en' => 'the Standort'],
    'site_code' => ['de' => 'Standortcode', 'en' => 'Standortcode'],
    'site_server' => ['de' => 'Standortserver', 'en' => 'Standortserver'],
    // Ohne Beleg in der deutschen Konsole (PR-R3MP, Z2): englische Konsolenform wie en.php.
    'site_database_server' => ['de' => 'Site-Datenbankserver', 'en' => 'site database server'],
    'site_status' => ['de' => 'Standortstatus', 'en' => 'Standortstatus'],
    'sms_provider' => ['de' => 'SMS-Anbieter', 'en' => 'SMS-Anbieter'],
    'sms_providers' => ['de' => 'SMS-Anbieter', 'en' => 'SMS-Anbieter'],
    'collection' => ['de' => 'Sammlung', 'en' => 'Sammlung'],
    'collections' => ['de' => 'Sammlungen', 'en' => 'Sammlungen'],
    // Nutzerentscheidung 25.11.2026: „MECM-Bereitstellung“, weil „Bereitstellung“
    // im Portal schon die Bereitstellung der VMs ist (R15, Block B).
    'deployment' => ['de' => 'MECM-Bereitstellung', 'en' => 'MECM-Bereitstellung'],
    'deployments' => ['de' => 'MECM-Bereitstellungen', 'en' => 'MECM-Bereitstellungen'],
    // PR-R3MP (Nutzerentscheidung 27.11.2026): Objektwörter und Konsolenpfade.
    'application' => ['de' => 'Anwendung', 'en' => 'Anwendung'],
    'applications' => ['de' => 'Anwendungen', 'en' => 'Anwendungen'],
    'deployment_type' => ['de' => 'Bereitstellungstyp', 'en' => 'Bereitstellungstyp'],
    'deployment_types' => ['de' => 'Bereitstellungstypen', 'en' => 'Bereitstellungstypen'],
    // Ein Name je Pfad, als Ganzes: deutsch nur, wo jeder Knoten zwei unabhängige
    // Belege hat, sonst der englische Pfad wie in en.php (kein Mischpfad).
    'path_system_status' => ['de' => 'Überwachung → Systemstatus', 'en' => 'Überwachung → Systemstatus'],
    'path_component_status' => ['de' => 'Überwachung → Systemstatus → Komponentenstatus', 'en' => 'Überwachung → Systemstatus → Komponentenstatus'],
    'path_content_status' => ['de' => 'Monitoring → Distribution Status → Content Status', 'en' => 'Monitoring → Distribution Status → Content Status'],
    'path_devices' => ['de' => 'Assets and Compliance → Devices', 'en' => 'Assets and Compliance → Devices'],
    'path_device_collections' => ['de' => 'Assets and Compliance → Device Collections', 'en' => 'Assets and Compliance → Device Collections'],
    'path_applications' => ['de' => 'Softwarebibliothek → Anwendungsverwaltung → Anwendungen', 'en' => 'Softwarebibliothek → Anwendungsverwaltung → Anwendungen'],
];
