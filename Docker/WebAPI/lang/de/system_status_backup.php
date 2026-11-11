<?php

declare(strict_types=1);

// Abschnitt Host und Backup im Systemstatus
// (lib/system_status_backup_panels.php). Zahlen kommen als Platzhalter aus den
// Konstanten in lib/backup_evidence.php und lib/constants.php.
return [
    'heading' => 'Host und Backup',
    'hint' => 'Der Docker-Host meldet diese Werte über den Backup-Statuskanal. Fehlt eine Meldung, steht dort „Unbekannt“. Die Karte im Überblick zeigt die schlechteste Zeile; „Unbekannt“ zählt dort nie als OK.',
    'row_backup' => 'Backup',
    'row_drill' => 'Restore-Test',
    'row_scrub' => 'Prüfsummen',
    'row_https' => 'HTTPS-Zertifikat',
    'fact_last_run' => 'Letzter Lauf',
    'fact_drill_last' => 'Letzter Erfolg',
    'fact_drill_backup' => 'Geprüfter Stand',
    'fact_scrub_last' => 'Letzte Prüfung',
    'fact_scrub_result' => 'Ergebnis',
    'fact_https_valid_to' => 'Gültig bis',
    'age_today' => 'heute',
    'age_days_one' => 'vor :days Tag',
    'age_days' => 'vor :days Tagen',
    'state_ok' => 'OK',
    'state_unknown' => 'Unbekannt',
    'state_due' => 'Fällig',
    'state_mismatch' => 'Abweichung',
    'state_expiring' => 'Läuft bald ab',
    'state_expired' => 'Abgelaufen',
    'drill_unknown_hint' => 'Kein Restore-Test nachgewiesen. Ein Drill auf einem anderen Rechner erscheint hier nicht. Drill mit Nachweis:',
    'drill_due_hint' => 'Der letzte nachgewiesene Restore-Test liegt :days Tage oder länger zurück. Drill mit Nachweis:',
    'scrub_unknown_hint' => 'Noch keine Prüfsummen-Prüfung gemeldet. Prüfung starten:',
    'scrub_failed_hint' => 'Diese Backupstände weichen von ihrem Manifest ab und taugen nicht für einen Restore: :sets',
    'scrub_result' => 'Geprüfte Sätze: :checked, abweichend: :failed',
    'https_expired_hint' => 'Das HTTPS-Zertifikat ist abgelaufen; Browser lehnen die Verbindung ab.',
];
