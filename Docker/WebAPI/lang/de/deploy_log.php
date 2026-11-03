<?php
declare(strict_types=1);
// Anzeige des Bereitstellungsprotokolls (E1/E2, 29.10.2026): Zähler, Ergebnis je VM,
// technische Details, Fehlerzeilen. Ältere Schlüssel der Seite stehen in deploy.php.
return [
    'position_running' => ':processed von :total bearbeitet',
    'position_created' => ':created von :total erstellt',
    'position_part_failed' => ':count fehlgeschlagen',
    'position_part_uncertain' => ':count ungeklärt',
    'position_part_not_started' => ':count nicht begonnen',
    'current_attempt' => 'Versuch :attempt von :max.',
    'finding_attempts' => ':name (Position :position, Versuch :attempt von :max): :reason',
    'reason_vm_not_powered_off' => 'Auf dem Host lief bereits eine eingeschaltete VM mit diesem Namen; Create hat nichts an ihr geändert.',
    'stored_detail' => 'Gespeicherter Text:',
    'host_no_message' => 'Der Host hat keine genauere Meldung geliefert.',
    'ansible_cause' => 'Letzte Fehlerzeile von Ansible:',
    'show_in_log' => 'Im Protokoll zeigen',
    'retry_creates_missing' => '„:action“ legt nur die VMs an, die noch fehlen; bereits erstellte VMs werden live geprüft.',
    'technical_vm' => ':name (Position :position):',
    'technical_first_attempt' => 'Erster Versuch:',
    'severity_fail' => 'Fehlgeschlagen',
    'severity_hold' => 'Angehalten',
    'severity_fatal' => 'Ansible-Fehler',
    'filter_errors_only' => 'Nur Fehler',
];
