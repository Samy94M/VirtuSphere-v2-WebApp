<?php

declare(strict_types=1);

return [
    'credentials_heading' => 'Welche Zugangsdaten gehören hierher?',
    'credentials_p1' => 'Die Seite Zugangsdaten hat bewusst nur zwei Kontotypen. Secrets werden verschlüsselt gespeichert und niemals erneut in ein Formular oder Protokoll geschrieben.',
    'credentials_ansible' => 'Ansible: SSH-Konto des Linux-Ausführungs-Hosts. Der Test prüft Verbindung, Werkzeugkette, SFTP, Portal-Rückweg und IP-Freigabe.',
    'credentials_esxi' => 'ESXi: Konto für vSphere/ESXi. Die Aktion startet einen echten read-only Inventarabruf über den gewählten Ansible-Host.',
    'credentials_mecm' => 'MECM: kein Konto auf dieser Seite. MECM verwendet die Machine-API-IP-Freigabe und optional den Report-Token unter Einstellungen.',
    'credentials_trust_heading' => 'ESXi-Zertifikatsprüfung',
    'credentials_trust_why_heading' => 'Warum?',
    'credentials_trust_why' => 'Verschlüsselung allein beweist nicht, mit welchem Host die Verbindung besteht. Ohne Zertifikatsprüfung kann ein Angreifer im LAN ESXi nachahmen und das ESXi-Konto abgreifen.',
    'credentials_trust_what_heading' => 'Was wird gespeichert?',
    'credentials_trust_what' => 'Ein CA-Bundle vertraut einer ausstellenden PKI und ist bei regulärer Zertifikatsrotation die robuste Wahl. Ein einzelnes Serverzertifikat bindet exakt dieses Zertifikat; bei dessen Erneuerung muss der Pin ersetzt und erneut getestet werden.',
    'credentials_trust_how_heading' => 'Wie wird umgestellt?',
    'credentials_trust_how' => 'Zertifikat im ESXi Host Client oder Browser als PEM exportieren, im Zugangsdatum speichern und den strikten Test starten. Nach seinem Erfolg erscheint die getrennte Aktivierungsaktion. Legacy insecure bedeutet ausdrücklich: verschlüsselt, aber Hostidentität ungeprüft.',
    'credentials_tests_heading' => 'Tests, Inventarabrufe und mehrere Ansible-Zugänge',
    'credentials_tests_p1' => 'Der manuelle Ansible-Volltest läuft sofort. Sein letzter Status und Zeitpunkt bleiben im Systemstatus sichtbar; er wird nicht automatisch wiederholt. Nach :days Tagen ohne Test steht die Ampel deshalb auf „Test veraltet": Das ist kein gemeldeter Fehler, sondern sagt, dass der heutige Zustand unbekannt ist. Der letzte vom Worker bearbeitete Missionsauftrag steht getrennt daneben; er ist kein Zugangstest und erneuert den Volltest nicht.',
    'credentials_tests_p2' => 'Der ESXi-Inventarabruf ist ein Hintergrundauftrag. Eingereiht, läuft, Erfolg, Fehler und Auth-Pause erscheinen an der passenden ESXi-Karte; vollständige Inventardetails werden erst beim Öffnen dieser Karte geladen.',
    'credentials_cadence_heading' => 'Die Zeile unter dem Status: erneuert sich das von selbst?',
    'credentials_cadence_p1' => 'Unter Marke und Zeitpunkt steht in jeder Zeile, ob sich der Wert von allein erneuert. Ein ESXi-Zugang wird im eingestellten Rhythmus abgerufen, ein Ansible-Zugang nur, wenn jemand auf „Verbindung und Umgebung prüfen" klickt. Beim ESXi-Zugang gibt es drei Gründe, aus denen trotzdem nichts läuft; die Zeile nennt jeweils den, der zuerst behoben werden muss:',
    'credentials_cadence_off' => '„kein automatischer Abruf (Intervall 0)": Die Automatik ist ausgeschaltet. Einstellen unter Einstellungen, Kataloge und Inventar.',
    'credentials_cadence_no_ansible' => '„kein automatischer Abruf, kein Ansible-Zugang gewählt": Der Abruf läuft über einen Ansible-Host, und es ist keiner da, es sind mehrere ohne Auswahl da, oder der ausgewählte wurde gelöscht. Auswählen unter Einstellungen, Kataloge und Inventar. Solange das offen ist, bleibt auch der Zeitpunkt stehen, denn es wird nicht einmal ein Versuch unternommen.',
    'credentials_cadence_paused' => '„pausiert, kein automatischer Abruf": Nur dieser Zugang ist nach Anmeldefehlern angehalten, damit das Konto nicht gesperrt wird. Er läuft wieder, sobald die Zugangsdaten gespeichert werden; ein einzelner Versuch geht jederzeit über „Inventarabruf starten".',
    'credentials_tests_p3' => 'Bei genau einem Ansible-Zugang wird er automatisch genutzt. Bei mehreren muss unter Einstellungen → Kataloge und Inventar genau einer ausgewählt werden. Wird dieser Zugang gelöscht oder in ESXi umgewandelt, bereinigt VirtuSphere die Auswahl und protokolliert das.',
];
