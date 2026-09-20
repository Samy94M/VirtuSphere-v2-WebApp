# Laufzeitprotokolle und Rotation

VirtuSphere begrenzt die stdout-/stderr-Protokolle jedes langfristigen
Compose-Dienstes mit genau einer Policy:

- Treiber `json-file`
- höchstens fünf Dateien je Container
- höchstens 10 MiB je Datei

Damit belegt ein Dienst höchstens ungefähr 50 MiB Containerlog. Die Grenze gilt
für Webserver, PHP-FPM, Deploy-Worker, Maintenance-Worker, MySQL und das optional
gestartete phpMyAdmin. Ein Neustart des Containers behält den noch nicht
rotierten Ausschnitt; ein Entfernen und Neuerzeugen des Containers entfernt
seinen Docker-Logverlauf.

## Lesen und live verfolgen

```sh
docker compose logs --tail 100 webserver
docker compose logs --tail 100 php deploy-worker maintenance-worker mysql
docker compose logs -f deploy-worker maintenance-worker
```

nginx schreibt Access- und Error-Zeilen für HTTP und generiertes HTTPS direkt in
stdout/stderr. Es gibt keinen Bind-Mount nach `/var/log/nginx` und keine Dateien
unter `Docker/logs/nginx` mehr. Dadurch kann eine fehlende Host-Schreibberechtigung
den Webserver nicht am Start hindern; Rotation und Aufbewahrung haben denselben
Owner wie die übrigen Containerstreams.

Persistente Anwendungsdiagnose bleibt davon getrennt:

- `Docker/WebAPI/logs/error.log`: globaler PHP-Fehlerhandler mit Referenz-ID
- `Docker/WebAPI/logs/php-error.log`: PHP-Engine-Fehler
- `Docker/WebAPI/logs/fail.log`: Legacy-Repositoryfehler

Diese Dateien sind Hostzustand, werden nicht in das Config-/DB-Backup aufgenommen
und benötigen auf einem Linux-Host die in der Go-live-Anleitung dokumentierten
Schreibrechte. Deploy-Joblogs und strukturierte Portalereignisse liegen in der
Datenbank und folgen ihren eigenen Retentionverträgen.

## Platzmangel und fehlende Diagnose

Die Größenpolitik verhindert unbegrenztes Wachstum eines einzelnen
Containerstreams, ersetzt aber keine Überwachung des Docker-Datenverzeichnisses.
Wenn `docker compose logs` selbst fehlschlägt oder keine neuen Zeilen mehr zeigt,
zuerst freien Platz und Docker-Daemonzustand auf dem Host prüfen. Nicht als
Reparatur Container, MySQL-Daten oder historische Binlogdateien löschen.

Ein volles Hostdateisystem kann weiterhin die persistenten PHP-Dateien oder die
Datenbank betreffen. Der sichtbare Ausfallweg ist dann ein ungesunder Dienst,
eine PHP-Fehlerreferenz ohne schreibbaren Dateisink oder ein Docker-Daemonfehler;
die Betriebsmaßnahme ist Platz schaffen beziehungsweise den Dateisink reparieren,
nicht den fachlichen Auftrag erneut ausführen.

## MySQL-Binloggrenze

MySQL läuft mit `--skip-log-bin`. VirtuSphere hat keinen Replikations- oder
Point-in-Time-Recovery-Verbraucher; der unterstützte Wiederherstellungspunkt ist
das letzte vollständig geprüfte Tripel aus Datenbankdump, Konfigurationsarchiv
und Manifest. Diese Einstellung entfernt keine alten Binlogdateien. Solche
Dateien niemals direkt aus `Docker/mysql/mysql-data` löschen. Eine spätere
PITR-Einführung braucht zuerst einen eigenen Archivierungs-, Retention- und
Restorevertrag.
