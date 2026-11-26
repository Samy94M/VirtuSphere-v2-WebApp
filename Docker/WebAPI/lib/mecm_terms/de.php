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
 * Konsole von Configuration Manager; Quellen im Register
 * docs/register/2026-11-25-PR-R3M.md.
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
    'site_database_server' => ['de' => 'Standortdatenbankserver', 'en' => 'Standortdatenbankserver'],
    'site_status' => ['de' => 'Standortstatus', 'en' => 'Standortstatus'],
    'sms_provider' => ['de' => 'SMS-Anbieter', 'en' => 'SMS-Anbieter'],
    'sms_providers' => ['de' => 'SMS-Anbieter', 'en' => 'SMS-Anbieter'],
    'collection' => ['de' => 'Sammlung', 'en' => 'Sammlung'],
    'collections' => ['de' => 'Sammlungen', 'en' => 'Sammlungen'],
    // Nutzerentscheidung 25.11.2026: „MECM-Bereitstellung“, weil „Bereitstellung“
    // im Portal schon die Bereitstellung der VMs ist (R15, Block B).
    'deployment' => ['de' => 'MECM-Bereitstellung', 'en' => 'MECM-Bereitstellung'],
    'deployments' => ['de' => 'MECM-Bereitstellungen', 'en' => 'MECM-Bereitstellungen'],
];
