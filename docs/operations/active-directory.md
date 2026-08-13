# Active Directory über LDAPS

VirtuSphere kann ausdrücklich importierte AD-DS-Konten über LDAPS anmelden. Die
Rolle, Aktivierung und Zulassung eines Kontos bleiben im Portal. Es gibt weder
automatische Provisionierung noch Gruppen-Mapping, lokales Ersatzpasswort oder
unverschlüsseltes LDAP.

## Voraussetzungen und Freigabegrenze

- Das Portal läuft mit aktiviertem HTTPS und HTTP-zu-HTTPS-Umleitung.
- Mindestens ein aktiver lokaler Administrator bleibt als Notzugang erhalten.
- Jeder Domänencontroller ist über einen FQDN erreichbar, der im Zertifikat
  enthalten ist. Der Standardport ist 636.
- Das hinterlegte CA-Bundle enthält die vollständige Vertrauenskette. Private
  Schlüssel und zusätzlicher Text werden abgewiesen.
- Das Suchkonto darf die benötigten Benutzerattribute und RootDSE lesen. Es
  benötigt keine administrativen Rechte.
- Die Prüfungen aus
  `docs/audits/2026-08-13-ldaps-target-ad-validation-protocol.md` sind für jeden
  Ziel-DC dokumentiert. Vorher ist die Funktion nicht für Produktion freigegeben.

Die aktuelle Ausbaustufe prüft Zertifikatskette, Servernamen, Gültigkeitszeit,
Server-Auth-Eignung und TLS ab Version 1.2. Sie lädt zur Laufzeit keine OCSP-
oder CRL-Daten. Deshalb gehören rechtzeitige Zertifikatsrotation und kurze,
kontrollierte Zertifikatslaufzeiten zum Betriebskonzept.

## Einrichtung

1. Unter **Benutzer > Active Directory** Suchkonto als UPN, Passwort, CA-Bundle
   und optional die eingeschränkte Benutzer-Suchbasis speichern.
2. Einen beschreibbaren Domänencontroller mit seinem Zertifikats-FQDN
   hinzufügen, testen und aktivieren. RODCs werden nicht unterstützt.
3. Weitere Controller in gewünschter Priorität hinzufügen und ebenfalls testen.
4. Benutzer suchen, einzeln importieren, Rolle wählen und Aktiv-Status prüfen.
5. Die Verzeichnisanmeldung aktivieren. Der Server prüft HTTPS, Umleitung,
   lokalen Notfalladmin und mindestens einen für die aktuelle Revision
   freigegebenen Controller erneut.

Jede Änderung an Suchkonto, Passwort, CA oder Suchbasis erzeugt eine neue
Konfigurationsrevision. Frühere Controllerprüfungen gelten dann nicht mehr.

## Betrieb und Störungen

Der Systemstatus zeigt Aktivierung, Revision, Anzahl einsatzbereiter Controller
und den letzten erfolgreichen Kontakt. Detailereignisse stehen im
Sicherheitsprotokoll in der Kategorie `directory`; Secrets, Benutzerpasswörter,
DNs und rohe LDAP-Fehler werden dort nicht gespeichert.

Eine technische Störung darf auf den nächsten priorisierten Controller
ausweichen. Eine abgewiesene Benutzeranmeldung wird nicht gegen weitere
Controller wiederholt. Wird stattdessen das Suchkonto abgewiesen, öffnet sich
der revisionsweite Circuit Breaker, damit das Konto nicht durch automatische
Wiederholungen gesperrt wird. Passwort korrigieren oder einen Controller
manuell erfolgreich testen, um automatische Versuche wieder freizugeben.

Bestehende AD-Sitzungen werden regelmäßig über die unveränderliche
`objectGUID` geprüft. Kurze technische Ausfälle nutzen eine begrenzte
Schonfrist. Ein deaktiviertes Portal- oder AD-Konto sowie eine deaktivierte
Integration beenden die Sitzung ohne Schonfrist.

## Zertifikatsrotation

Das neue CA-Bundle zuerst mit Überlappung der alten und neuen Vertrauenskette
speichern. Danach jeden Controller in bereits laufenden PHP-FPM-Workern testen.
Erst wenn Paralleltests und Negativfälle aus dem Ziel-AD-Protokoll grün sind,
alte CA-Zertifikate aus dem Bundle entfernen. Die abgeleiteten CA-Dateien liegen
nur im geschützten PHP-Laufzeitverzeichnis und sind kein Backupbestandteil.

## Restore

Nach einem Restore muss einmal

```sh
docker exec virtusphere-v2-webapp-php-1 php /var/www/html/lib/directory_restore_converge.php
```

laufen. Der Schritt deaktiviert die AD-Anmeldung, erhöht die Revision und macht
alle früheren Controllerfreigaben unwirksam. Danach Konfiguration, CA,
Zielumgebung und HTTPS prüfen, Controller neu testen und AD bewusst wieder
aktivieren. `scripts/restore_test.sh` beweist diesen Schritt im isolierten Drill.
