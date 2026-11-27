<?php
declare(strict_types=1);
return [
    'title' => 'Automatischer Ansible-Volltest',
    'interval' => 'Prüfintervall (Stunden, 0 = aus)',
    'hint' => 'Prüft alle registrierten Ansible-Zugänge in diesem Abstand mit dem Volltest. Standard sind :default Stunden, erlaubt ganze Stunden bis :max; 0 schaltet die Automatik ab.',
    'behaviour' => 'Fällige Prüfungen startet der Bereitstellungsdienst im Hintergrund, sobald er frei ist; das Intervall zählt ab dem letzten Prüfstart.',
    'invalid' => 'Geben Sie eine ganze Stundenzahl zwischen :min und :max ein.',
    'status_link' => 'Systemstatus öffnen',
    'configure' => 'Prüfintervall ändern',
    'cadence' => 'Automatisch im Abstand von :hours h, sobald der Dienst frei und die Auftragsannahme aktiv ist; Beleg :days Tage gültig. Manueller Volltest weiterhin möglich.',
    'cadence_off' => 'Automatischer Volltest ausgeschaltet; manuell auf Klick. Beleg :days Tage gültig.',
    'help' => 'Unter Einstellungen → Kataloge und Inventar ändern Benutzer mit Einstellungsberechtigung das Prüfintervall. Ohne bisherigen Test ist ein Zugang beim nächsten freien Durchlauf fällig. Bei ausgelastetem, pausiertem oder gestopptem Bereitstellungsdienst verschiebt sich der Start. Nach einer Änderung der Zugangsdaten ist ein neuer Test fällig. Ein neueres Testergebnis oder eine neuere Konfiguration kann nicht durch eine ältere Prüfung überschrieben werden. Ergebnisse stehen im Systemstatus und in den Prüfprotokollen; automatische Prüfungen sind dort gekennzeichnet. Eine Bereitstellung ersetzt diesen Volltest nicht.',
    'sweep_closed' => 'Entfernte ESXi-Zugangsdateien früherer Aufträge: :count.',
    'sweep_aborted' => 'Aufräumen früherer ESXi-Zugangsdateien abgebrochen (vorher entfernt: :count). Der Rest folgt beim nächsten Kontakt mit diesem Ansible-Host.',
    'secret_cleanup_missing' => 'Der Timer virtusphere-secret-cleanup.timer fehlt auf dem Ansible-Host. Zugangsdateien abgebrochener Aufträge bleiben dort, bis ein Kontakt sie entfernt. Einmalige Einrichtung als root auf dem Ansible-Host aus dem Offline-Bundle: sudo sh virtusphere/Ansible/host-cleanup/install-secret-cleanup.sh <Ansible-Konto>.',
];
