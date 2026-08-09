# VirtuSphere

## Web-App-Migration

VirtuSphere ist eine LAN-interne, server-gerenderte PHP-Web-App; die frühere Desktop-App ist entfernt (ADR-0035). Die technische Basis: Docker Compose mit `.env`, PHP 8.4, MySQL 8.4, nginx HTTP-first, zentrale PHP-Helfer unter `Docker/WebAPI/lib/`, Healthcheck `Docker/WebAPI/portal/health.php`, Migrationsrunner `Docker/WebAPI/lib/migrate.php`, Composer/phpseclib sowie ADR-/Agenten-Doku.

## Einstieg nach Aufgabe

Für einen neuen Betreiber ist die Lesereihenfolge bewusst betrieblich, nicht nach Repository-Architektur sortiert:

1. [Go-live](docs/operations/go-live.md): erste produktive Inbetriebnahme und Reihenfolge der Entscheidungen.
2. [Störungsdiagnose](docs/operations/troubleshooting.md): vom Symptom zur Portal-Seite, Log-Kategorie und ersten Maßnahme.
3. [Bereitstellungskette](docs/operations/deploy-chain.md): Übergaben und Rückkanäle von Portal über Ansible/ESXi und MECM bis zum Client.
4. [Glossar](docs/GLOSSARY.md): Statusstufen, Auftragszustände, MECM-Provenienz, VM-Identität und Korrelations-ID.

Danach führen die spezialisierten Betriebshandbücher weiter:

- [Installation mit Netzzugang](docs/INSTALLATION-ANLEITUNG.md); für einen luftspaltgetrennten Produktionshost stattdessen [Offline-Installation](docs/operations/offline-install.md).
- [ESXi-Inventar](docs/operations/esxi-inventory.md), [MECM-Integration](docs/operations/mecm-integration.md), [HTTPS](docs/operations/https.md) und [Backup/Restore](docs/operations/backup.md).
- [Deployment- und Supportmatrix](docs/DEPLOYMENT.md), [QA-Bedienung](docs/QA.md), [Qualitätsgates](docs/QUALITY-GATES.md) und [Pre-Ship-Checkliste](PRE-SHIP-CHECKLIST.md).

Für Entwicklung und Architektur folgen erst danach [AGENTS.md](AGENTS.md), [GROK.md](GROK.md) und der [ADR-Index](docs/adr/README.md). Die lokale Konfiguration beginnt mit `.env.example`; `.env` selbst ist bewusst ignoriert. Abgeschlossene Änderungen stehen im [Changelog](docs/CHANGELOG.md).

## Funktionsumfang der Web-App

Das Portal ist server-gerendert (PHP, kein JS-Build, kein CDN) und zweisprachig: Deutsch ist Standard, Englisch die zweite Sprache (ADR-0014). Der aktuelle Stand deckt ab:

- Missions- und VM-Verwaltung: Infrastrukturvorlagen anlegen, kopieren, bearbeiten und löschen; Sammellöschung und MECM-ID-Reset auf der VM-Liste; CPU-/RAM-Hot-Add-Optionen.
- Deployment: geplante und gestaffelte Deploy-Läufe mit portalseitiger Zeitzone (ADR-0022), Deploy-Log und Statusverfolgung.
- ESXi-Inventory: von ESXi geführter VLAN-Katalog, Inventory-Abgleich mit Abweichungsreport und geführter VLAN-Neuzuordnung (ADR-0023) sowie Autostart-Policy (ADR-0025).
- Integrationen: MECM-, Ansible- und Maschinen-API-Anbindung mit Heartbeats, Meldekanal und Ampel-Statusanzeige auf der Seite „Systemstatus"; die Kataloge für OS und Pakete sind MECM-geführt und im Portal schreibgeschützt (ADR-0020).
- Betrieb und Sicherheit: RBAC, Sessions und CSRF, zentrale CSP-/Security-Header, persistente Logs mit Kategorienfilter und Aufbewahrungsfenstern (ADR-0026), Backup/Restore (ADR-0017) sowie HTTPS komplett im Portal konfigurierbar: Zertifikats-Upload (PFX/PEM), Listener-, Redirect- und HSTS-Schalter (ADR-0027). Die Maschinenkette kann TLS mitgehen: MECM-Aufgaben und Installer verhandeln TLS 1.2, ein selbstsigniertes Zertifikat wird über einen hinterlegten Fingerabdruck vertraut statt durch abgeschaltete Prüfung, und der MAC-Rückruf des Ansible-Hosts prüft ebenso (`docs/operations/https.md`). HTTP bleibt der LAN-Standard.

## Status und offene Punkte

- Der frühere Desktop-Client und seine Token-API sind physisch entfernt (E3 angenommen, ADR-0035); die entfernten Pfade antworten per Wire-Contract mit 404. Die Maschinen-API (`mecm-*`, `db_importMAC.php`) bleibt wire-kompatibel.
- Offen: rein visuelles Frontend-Design-Handoff und ein frischer Clean-Checkout-Setup-Probelauf als Release-Nachweis.
- Abgeschlossene Arbeitsstände werden ausschließlich in `docs/CHANGELOG.md` geführt, nicht hier.

## Lizenz

Dieses Projekt ist unter der MIT-Lizenz lizenziert.

## Autoren

- Warex: Grundstein und Projektgründung.
- Samy94M: Weiterentwicklung, inklusive der Migration zur Web-App.
