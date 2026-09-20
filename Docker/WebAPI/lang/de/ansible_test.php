<?php
declare(strict_types=1);
return [
    'title' => 'Automatischer Ansible-Volltest',
    'interval' => 'Prüfintervall (Stunden, 0 = aus)',
    'hint' => 'Prüft alle registrierten Ansible-Zugänge über denselben Volltest wie „Volltest jetzt starten“: SSH, Werkzeuge, SFTP und bei konfigurierter API-Rückadresse auch die Verbindung zum Portal. Standard: :default Stunden. Ganze Stunden bis :max sind erlaubt; 0 deaktiviert die Automatik.',
    'behaviour' => 'Der Bereitstellungsdienst startet fällige Prüfungen im Hintergrund, sobald er frei und die Auftragsannahme aktiv ist. Das Intervall zählt ab dem letzten Prüfstart, auch bei Fehler oder Unterbrechung. Manuelle Prüfungen verschieben den nächsten Termin ebenfalls. Änderungen gelten für kommende Prüfungen; eine laufende Prüfung wird dadurch nicht abgebrochen.',
    'invalid' => 'Geben Sie eine ganze Stundenzahl zwischen :min und :max ein.',
    'status_link' => 'Ansible-Systemstatus öffnen',
    'configure' => 'Prüfintervall ändern',
    'cadence' => 'Automatisch im Abstand von :hours h, sobald der Dienst frei und die Auftragsannahme aktiv ist; Nachweis :days Tage gültig. Manueller Volltest weiterhin möglich.',
    'cadence_off' => 'Automatischer Volltest ausgeschaltet; manuell auf Klick. Nachweis :days Tage gültig.',
    'help' => 'Unter Einstellungen → Kataloge und Inventar ändern Benutzer mit Einstellungsberechtigung das Prüfintervall. Ohne bisherigen Test ist ein Zugang beim nächsten freien Durchlauf fällig. Bei ausgelastetem, pausiertem oder gestopptem Bereitstellungsdienst verschiebt sich der Start. Nach einer Änderung der Zugangsdaten ist ein neuer Test fällig. Ein neueres Testergebnis oder eine neuere Konfiguration kann nicht durch eine ältere Prüfung überschrieben werden. Ergebnisse stehen im Systemstatus und in den Prüfprotokollen; automatische Prüfungen sind dort gekennzeichnet. Ein Deploy ersetzt diesen Volltest nicht.',
];
