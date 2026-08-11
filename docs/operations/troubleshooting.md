# Störungsdiagnose

Dieses Runbook beginnt beim sichtbaren Symptom. Es ersetzt weder das Auftragsprotokoll noch den Systemstatus, sondern führt zur ersten belastbaren Spur. Zeitangaben, Benutzer und Korrelations-ID immer notieren, bevor ein Dienst neu gestartet wird.

## Reihenfolge

1. Im Portal **Systemstatus** öffnen und die betroffene Komponente bestimmen.
2. Bei einem Bereitstellungsauftrag dessen **Auftragsprotokoll** öffnen. Bei Portal-, MECM- oder Konfigurationsproblemen unter **Protokolle** den genannten Tab und die Kategorie wählen.
3. Die Korrelations-ID aus Fehlermeldung, Auftrag oder Logzeile in der Protokollsuche verwenden.
4. Erst die erste Maßnahme aus der Tabelle ausführen. Ein Neustart ohne vorherigen Nachweis löscht flüchtige Hinweise und ist keine Diagnose.

## Symptomtabelle

| Symptom | Bedeutung | Portal-Seite / Log-Tab / Kategorie | Erste Maßnahme |
|---|---|---|---|
| Portal antwortet mit 502 oder gar nicht | nginx erreicht PHP nicht oder ein Container ist nicht gesund | Wenn erreichbar: **Systemstatus**. Sonst Host: `docker compose ps` und `docker compose logs php webserver` | Den ersten ungesunden Dienst und dessen Health-Ausgabe prüfen; nicht die Datenbank löschen oder den Stack neu aufsetzen. |
| Anmeldung wird trotz korrekter Daten abgelehnt | Konto- oder IP-Sperre, falsches Passwort oder fehlender Erstwechsel | **Protokolle → Sicherheit**, Kategorie `auth` | Zeitpunkt und Quell-IP vergleichen; eine Sperre nur über die vorgesehene Admin-Aktion lösen. |
| „Auftrag einreihen“ bleibt deaktiviert | Mindestens eine konkrete Voraussetzung fehlt | **Bereitstellung**; jeder Blocker nennt und verlinkt seine Portal-Seite | Die einzeln angezeigten Blocker von oben nach unten beheben. |
| Auftrag bleibt `queued` | Deploy-Worker arbeitet nicht, ein geplanter Zeitpunkt ist noch nicht erreicht oder ein älterer Missionsauftrag ist aktiv | **Systemstatus → Deploy-Worker**, danach **Protokolle → Bereitstellung**, Kategorie `deploy` | Worker-Ampel und geplanten Zeitpunkt prüfen; dann das Auftragsprotokoll öffnen. |
| Auftrag steht lange auf `running` | Ein Remoteschritt läuft noch oder der Worker hat seinen Besitz verloren | Auftragsprotokoll und **Systemstatus → Deploy-Worker** | Prüfen, ob neue Heartbeats/Logzeilen eintreffen. Nur einen nachweislich verwaisten Auftrag dem Reaper überlassen. |
| Auftrag wurde beendet mit „Reaped stale deploy job" | Über das Fenster kam kein Herzschlag an. Das belegt nicht, dass der Worker gestorben ist: eine kurz nicht erreichbare Datenbank erzeugt dieselbe Stille | Auftragsprotokoll (die Meldung nennt dahinter, ob sich der Dienst noch meldet) und **Systemstatus → Deploy-Worker** | Den Satz hinter der Meldung lesen. Meldet sich der Dienst weiter, den Lauf auf dem Ansible-Host zu Ende laufen lassen und den Auftrag erneut einreihen, den Dienst **nicht** neu starten. Meldet er sich nicht, ist er der Befund. Siehe [Bereitstellungskette](deploy-chain.md). |
| Auftrag steht auf `cancelling` | Der Abbruch ist angefordert, aber der Worker muss noch einen sicheren Schrittpunkt erreichen | Auftragsprotokoll; **Protokolle → Bereitstellung**, Kategorie `deploy` | Warten, bis `cancelled` bestätigt ist. VM/Mission bis dahin nicht löschen. |
| ESXi-Test meldet Zertifikatsfehler | TLS ist verschlüsselt, aber Hostidentität oder Vertrauenskette passt nicht | **Zugangsdaten** und **Systemstatus → ESXi-Inventar**; **Protokolle → Sicherheit**, Kategorie `credentials` | Host/Zertifikat prüfen, richtiges Serverzertifikat oder CA-Bundle hinterlegen und erneut testen. Nicht still auf Legacy wechseln. |
| ESXi-Inventar zeigt alte Daten | Der letzte Abruf ist fehlgeschlagen oder eine Teilabfrage war nicht autoritativ | **Systemstatus → ESXi-Inventar**, direkter Link zum letzten Auftragsprotokoll | Fehlerkategorie und Zeile `Inventory queries:` lesen; Zugang, Zertifikat oder Antwortform an der benannten Teilabfrage korrigieren. |
| Ansible steht auf „Test veraltet“, obwohl ein Deploy funktioniert hat | Der manuelle Volltest ist älter als das Gültigkeitsfenster; das ist kein gemeldeter Fehler. Ein Missionsauftrag belegt nur seine tatsächlich ausgeführten Playbooks und erneuert den Volltest bewusst nicht. | **Systemstatus → Ansible-Host**: Volltestzeit und letzter beendeter Missionsauftrag getrennt lesen; dort direktes Jobprotokoll und **Prüfprotokolle öffnen** (Kategorie `credentials`) | Zuerst den Ausgang des letzten Missionsauftrags lesen. Für einen aktuellen Gesamtnachweis „Volltest jetzt starten“; bei Warnung IP-Allowlist, bei Fehler die benannte Komponente beheben. |
| VM bleibt bei 2/5 | ESXi-/Ansible-Kette hat keine gültigen MACs zurückgemeldet | Letztes Auftragsprotokoll der Mission; Kategorie `deploy` | Rückkanal, `job_id`, IP-Allowlist und MAC-Konflikte prüfen. |
| VM bleibt bei 3/5 | MAC ist vorhanden, MECM Device-Sync hat aber keine ResourceID bestätigt | **Systemstatus → MECM Device-Sync**; **Protokolle → Bereitstellung**, Kategorie `mecm` | Ursachenzeile des letzten Device-Sync-Laufs lesen; Collection-, ResourceID- oder MAC-Konflikt beheben. |
| VM bleibt bei 4/5 oder Clientphase schlägt fehl | Ab hier arbeitet die VM beziehungsweise die Task Sequence selbst | **VM bearbeiten → Client-Phasen**; **Systemstatus → MECM Site Health** | Letzte gemeldete Phase und deren Detail prüfen; PXE/Task Sequence nur bei fehlender erster Clientmeldung untersuchen. |
| Maschinen-API antwortet 403 | Quell-IP ist nicht freigegeben oder der optionale Report-Token passt nicht | **Protokolle → Sicherheit**, Kategorie `machine_api`; Einstellungen **Maschinen-API** | Tatsächliche Quell-IP aus der Ablehnungszeile übernehmen und Allowlist/Token gezielt korrigieren. |
| Backup-Ampel ist unbekannt oder rot | Noch kein Statuslauf, fehlgeschlagenes/stales Backup oder zu wenig Platz | **Einstellungen → Backup** und **Systemstatus → Wartung** | `scripts/backup.sh` beziehungsweise Scheduler-Ausgabe prüfen; danach den Restore-Drill ausführen. |

## Host-Dateien

Wenn das Portal selbst keine Diagnose mehr anzeigen kann, liegen PHP-Fehler in `Docker/WebAPI/logs/error.log` und `Docker/WebAPI/logs/php-error.log`, nginx-Fehler in `Docker/logs/nginx/error.log`. Diese Dateien enthalten technische Details und bleiben auf dem Server. Secrets niemals in Tickets oder Screenshots übernehmen.

Die vollständigen Übergaben zwischen Portal, Worker, Ansible, ESXi und MECM beschreibt die [Bereitstellungskette](deploy-chain.md). Begriffe und Statuswerte stehen im [Glossar](../GLOSSARY.md). Für Backup, HTTPS und ESXi-Inventar gelten zusätzlich die jeweiligen Runbooks in diesem Verzeichnis.
