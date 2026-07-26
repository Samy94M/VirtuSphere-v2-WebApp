# VirtuSphere

## Web-App-Migration

VirtuSphere wird von der C# WinForms-Desktop-App zu einer LAN-internen, server-gerenderten PHP-Web-App migriert. Die technische Basis: Docker Compose mit `.env`, PHP 8.4, MySQL 8.4, nginx HTTP-first, zentrale PHP-Helfer unter `Docker/WebAPI/lib/`, Healthcheck `Docker/WebAPI/portal/health.php`, Migrationsrunner `Docker/WebAPI/lib/migrate.php`, Composer/phpseclib sowie ADR-/Agenten-Doku.

Wichtige Einstiegsdateien:
- `AGENTS.md` für Entwicklungsregeln und Endpunktkarte.
- `GROK.md` als SSoT für verbotene Patterns und Integrationsverträge.
- `docs/adr/README.md` für die ADR-Entscheidungsübersicht.
- `docs/DEPLOYMENT.md` für persistente Logs und Troubleshooting.
- `docs/INSTALLATION-ANLEITUNG.md` für das Setup auf einem Host **mit** Netzzugang (`setup.sh` baut die Images). Der luftspaltgetrennte Produktionshost geht stattdessen über `docs/operations/offline-install.md`.
- `docs/QA.md` für PHPUnit-, Hook-, Lang-Audit- und Git-Hygiene-Befehle.
- `docs/CHANGELOG.md` für die Migrationshistorie.
- `docs/operations/` für den laufenden Betrieb, ein Dokument je Frage:
  - `go-live.md`: erste produktive Inbetriebnahme auf dem Ubuntu-Host, ohne Docker-Vorwissen.
  - `offline-install.md`: Installation auf einem Host ohne Internetzugang (Bundle prüfen, Images laden statt bauen); ab den Migrationen führt `go-live.md` weiter.
  - `esxi-inventory.md`: read-only Inventarabruf, ESXi-geführter VLAN-Katalog, Fehlerbilder und was eine 0 auf einer ESXi-Karte bedeutet.
  - `mecm-integration.md`: Zusammenspiel von Portal, MECM-Server und PXE-Clients.
  - `https.md`: HTTPS im Portal einschalten (Zertifikat, Listener, HSTS).
  - `backup.md`: Backup/Restore (`scripts/backup.sh`, `scripts/restore_test.sh`).
- `PRE-SHIP-CHECKLIST.md` für die Release-Gates.
- `.env.example` als Vorlage; die lokale `.env` ist bewusst ignoriert.

## Funktionsumfang der Web-App

Das Portal ist server-gerendert (PHP, kein JS-Build, kein CDN) und zweisprachig: Deutsch ist Standard, Englisch die zweite Sprache (ADR-0014). Der aktuelle Stand deckt ab:

- Missions- und VM-Verwaltung: Infrastrukturvorlagen anlegen, kopieren, bearbeiten und löschen; Sammellöschung und MECM-ID-Reset auf der VM-Liste; CPU-/RAM-Hot-Add-Optionen.
- Deployment: geplante und gestaffelte Deploy-Läufe mit portalseitiger Zeitzone (ADR-0022), Deploy-Log und Statusverfolgung.
- ESXi-Inventory: von ESXi geführter VLAN-Katalog, Inventory-Abgleich mit Abweichungsreport und geführter VLAN-Neuzuordnung (ADR-0023) sowie Autostart-Policy (ADR-0025).
- Integrationen: MECM-, Ansible- und Maschinen-API-Anbindung mit Heartbeats, Meldekanal und Ampel-Statusanzeige auf der Seite „Systemstatus"; die Kataloge für OS und Pakete sind MECM-geführt und im Portal schreibgeschützt (ADR-0020).
- Betrieb und Sicherheit: RBAC, Sessions und CSRF, zentrale CSP-/Security-Header, persistente Logs mit Kategorienfilter und Aufbewahrungsfenstern (ADR-0026), Backup/Restore (ADR-0017) sowie HTTPS komplett im Portal konfigurierbar: Zertifikats-Upload (PFX/PEM), Listener-, Redirect- und HSTS-Schalter (ADR-0027). Die Maschinenkette kann TLS mitgehen: MECM-Aufgaben und Installer verhandeln TLS 1.2, ein selbstsigniertes Zertifikat wird über einen hinterlegten Fingerabdruck vertraut statt durch abgeschaltete Prüfung, und der MAC-Rückruf des Ansible-Hosts prüft ebenso (`docs/operations/https.md`). HTTP bleibt der LAN-Standard.

## Status und offene Punkte

- Der Desktop-Client und die Legacy-Token-API sind noch nicht physisch entfernt; das E3-Retirement steht aus. Die Maschinen-API (`mecm-*`, `db_importMAC.php`) bleibt während der Migration wire-kompatibel.
- Offen: rein visuelles Frontend-Design-Handoff, E3-Legacy-Token-Retirement und ein frischer Clean-Checkout-Setup-Probelauf als Release-Nachweis.
- Abgeschlossene Arbeitsstände werden ausschließlich in `docs/CHANGELOG.md` geführt, nicht hier.

## Legacy-Desktop-Client (WinForms)

Dieser Abschnitt beschreibt den bestehenden Desktop-Client, der bis zum E3-Meilenstein parallel weiterläuft.

VirtuSphere bietet Infrastrukturadministratoren eine grafische Schnittstelle zum Anlegen virtueller Server im Microsoft Endpoint Configuration Manager (MECM) mit PXE-Boot für Windows-Installationen. Es automatisiert Nachinstallationsprozesse wie Domain-Controller-Rolle, Domänenbeitritt und die Installation individueller Softwarepakete. Serverinfrastrukturvorlagen können erstellt, kopiert und je ESXi-Ziel angepasst werden. Der Client generiert Ansible-Playbooks und kopiert sie per SSH auf einen Ubuntu-Server, von dem aus die VMs auf dem hinterlegten ESXi-Server erstellt werden; die Windows-Installation erfolgt anschließend per PXE-Boot über MECM.

Hauptmerkmale:
- VM-Management: Erstellen, Bearbeiten und Löschen virtueller Maschinen.
- Automatisierung: Ansible-Playbooks für das Deployment.
- SSH-Schlüsselmanagement und sichere Kommunikation mit Hypervisoren.
- Intuitives GUI für VMs und Missionskonfigurationen.

Technologie-Stack des Clients: .NET Framework (Windows Forms), Ansible, Docker (WebAPI/Datenbank), MySQL und PHP.

## Lizenz

Dieses Projekt ist unter der MIT-Lizenz lizenziert.

## Autoren

- Warex: Grundstein und Projektgründung.
- Samy94M: Weiterentwicklung, inklusive der Migration zur Web-App.
