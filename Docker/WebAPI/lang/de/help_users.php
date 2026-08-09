<?php

declare(strict_types=1);

return [
    'roles_heading' => 'Administrator vs. Benutzer',
    'roles_p1' => 'Es gibt genau zwei Rollen. Die Rolle bestimmt nur, welche Aktionen im Portal sichtbar und erlaubt sind. Sie hat keinen Einfluss auf Sprache oder Anzeige.',
    'roles_matrix_th_feature' => 'Funktion',
    'roles_matrix_view' => 'Portal ansehen: Dashboard, Missionen, VMs, Pakete, Systemstatus, Hilfe',
    'perm_missions_write' => 'Missionen und Vorlagen anlegen, bearbeiten und klonen',
    'perm_vms_write' => 'VMs anlegen, bearbeiten und löschen (inkl. Netzwerkkarten und Paket-Zuordnung)',
    'perm_deploy_run' => 'Bereitstellungsaufträge einreihen und deren Protokolle einsehen',
    'perm_catalog_write' => 'VLANs pflegen (Betriebssysteme und Pakete kommen aus MECM und sind nur lesbar)',
    'perm_credentials_manage' => 'Zugangsdaten (ESXi/Ansible) anlegen und verwalten',
    'perm_system_config' => 'Systemeinstellungen ändern (API-Basis-URL, IP-Freigaben, Rückkanal-Token, Schutzschwellen)',
    'perm_users_manage' => 'Benutzerkonten verwalten und Audit-Protokolle einsehen',
    'usersmgmt_heading' => 'Benutzerverwaltung: die Seite „Benutzer"',
    'usersmgmt_p1' => 'Die Seite „Benutzer" ist nur für Administratoren sichtbar. Sie zeigt alle Konten mit Rolle, Aktiv-Status, Passwort-Änderungspflicht, aktueller Sperre und letzter Anmeldung. Von hier aus werden neue Konten angelegt und bestehende verwaltet; eine Selbstregistrierung gibt es bewusst nicht.',
    'usersmgmt_create_heading' => 'Neues Konto anlegen',
    'usersmgmt_create_p1' => 'Für ein neues Konto reichen Name, ein Startpasswort (mindestens :min Zeichen, laut aktueller Passwort-Richtlinie) und die Rolle; die E-Mail-Adresse ist optional und dient nur als Notiz. Das Startpasswort wird der Person auf sicherem Weg mitgeteilt.',
    'usersmgmt_create_p2' => 'Neue Konten starten immer mit Passwort-Änderungspflicht: Bei der ersten Anmeldung muss die Person das Startpasswort durch ein eigenes ersetzen. So kennt nach der Übergabe niemand außer ihr selbst das gültige Passwort.',
    'usersmgmt_actions_heading' => 'Bestehende Konten verwalten',
    'usersmgmt_action_role' => 'Rolle ändern: In der Zeile des Kontos die neue Rolle wählen und speichern. Die Änderung wirkt ab der nächsten Seitenaktion der Person, eine Neuanmeldung ist nicht nötig.',
    'usersmgmt_action_active' => 'Deaktivieren statt löschen: Ein deaktiviertes Konto kann sich nicht mehr anmelden, bleibt aber samt Historie erhalten und lässt sich jederzeit wieder aktivieren. Konten werden bewusst nicht gelöscht, damit Protokolleinträge zuordenbar bleiben.',
    'usersmgmt_action_reset' => 'Passwort zurücksetzen: Neues Passwort (mindestens :min Zeichen) in das Feld der Zeile eintragen und zurücksetzen. Auch hier gilt danach die Änderungspflicht bei der nächsten Anmeldung.',
    'usersmgmt_safety_heading' => 'Eingebaute Schutzmechanismen',
    'usersmgmt_safety_1' => 'Das eigene Konto lässt sich nicht deaktivieren; so sperrt sich niemand versehentlich selbst aus.',
    'usersmgmt_safety_2' => 'Der letzte aktive Administrator kann weder deaktiviert noch auf die Rolle „Benutzer" herabgestuft werden. Es bleibt also immer mindestens ein Admin übrig.',
    'usersmgmt_safety_3' => 'Nach mehreren fehlgeschlagenen Anmeldeversuchen wird ein Konto automatisch für :minutes Minuten gesperrt; in der Spalte „Aktiv" erscheint dann die Markierung „Gesperrt". Die Sperre läuft von selbst ab; bei aktiver Sperre kann ein Administrator sie mit „Sperre aufheben" auch sofort entfernen.',
    'usersmgmt_safety_4' => 'Eine Ausnahme bleibt: Die eigene Rolle lässt sich herabstufen, solange ein weiterer Administrator existiert. Das Portal fragt vorher nach, denn danach ist die Benutzerverwaltung für dich gesperrt; rückgängig machen kann es dann nur der andere Administrator.',
    'usersmgmt_audit_p1' => 'Jede Änderung an Konten (Anlegen, Rollenwechsel, Aktiv-Status, Passwort-Reset, eigener Passwortwechsel) wird im Audit-Protokoll unter „Sicherheit" mit Benutzer und Zeitpunkt festgehalten. Dort stehen auch An- und Abmeldungen, fehlgeschlagene Anmeldeversuche und automatische Kontosperren.',
];
