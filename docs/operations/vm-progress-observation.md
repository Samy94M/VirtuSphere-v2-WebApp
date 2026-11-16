# Rollout und Betrieb: VM-Fortschrittsbeobachtung

Dieses Runbook gilt für Migration `0038_vm_progress_watch` und ADR-0038. Die im
Repository bereits vorhandene Migration `0034_drop_legacy_token_schema` ist ein
anderer, abgeschlossener Rückbau und darf nicht mit dieser Änderung verwechselt
werden.

## Wirkung

- MECM-pending wird nach mehr als `VIRTUSPHERE_VM_MECM_PENDING_WARN_SECONDS`
  gelb markiert.
- Eine OS-Installation erhält ihre Uhr von selbst, sobald die VM registriert
  ist und ein Einschaltbeleg vorliegt (Abschnitt „Automatischer Start der
  Beobachtung“), und wird nach mehr als
  `VIRTUSPHERE_VM_OS_INSTALL_WARN_SECONDS` gelb markiert (beide Grenzen in
  `lib/constants.php`). Ohne Beleg bleibt die Aktion „Beobachtung von Hand
  starten“ im VM-Editor.
- Die Dashboard-Kachel „VM-Prüfung fällig“ zählt überfällige VMs je Grund und
  öffnet die VM-Übersicht mit dem passenden Filter.
- Keine Warnung setzt eine VM fehl, löscht sie oder startet eine externe Aktion.
- Missionsliste und Dashboard zeigen aktuelle empfangene Clientphasenfehler mit
  VM-Anzahl und Links. `repo_client_phase_failures()` liest je VM und Phase nur
  die letzte Meldung. Schweigen erzeugt keinen Fehler; ein neuerer Abschluss
  derselben Phase entfernt den Hinweis. Fehlermeldungen anderer Phasen bleiben
  bestehen.
- Bestehende MECM-pending-Zeilen beginnen beim Migrationslauf frisch. Bestehende
  OS-Installationen bleiben ohne Uhr, bis ein Einschaltbeleg eintrifft oder der
  Bediener die Beobachtung von Hand startet.

## Automatischer Start der Beobachtung

Seit ADR-0038 Nachtrag 2 (16.11.2026) startet das Portal die Uhr der
OS-Installation ohne Klick. Die Uhr bedeutet „registriert und eingeschaltet
seit“. Sie belegt keinen PXE-Start: MECM und die Task Sequence liefern dafür
kein Signal, und die Maschinen-API ist unverändert.

Die Uhr startet, wenn die VM `os_installing` und `registered` ist, noch keine
Uhr hat und einer der beiden Belege vorliegt:

| Beleg | Bedingung | Anzeige im VM-Editor |
|---|---|---|
| Portalstart | Der jüngste Auftrag der VM hat seinen Startschritt beendet: `succeeded` mit Startschritt („Vollständige Kette“, „VMs starten“) oder gescheitert erst im Autostart-Schritt danach | „Vom Portal gestartet: Ein Auftrag hat die VM eingeschaltet.“ |
| Inventar | Ein frisches, beantwortetes ESXi-Inventar des gebundenen Zugangs zeigt die VM eingeschaltet | „Vom Portal gestartet: Das ESXi-Inventar zeigt die VM eingeschaltet.“ |

Kein Beleg sind: ein gescheiterter Startschritt (für keine VM des Auftrags),
Aufträge mit `partial`, `cancelled` oder `outcome_unknown`, Protokollzeilen, ein
veraltetes oder unbeantwortetes Inventar und eine VM ohne ESXi-Bindung. Ein
Inventar zählt nur, wenn es nach dem Ende des jüngsten Auftrags der VM gelesen
wurde: Ein Auftrag schaltet seine VMs ein und wieder aus (Powercycle), ein
älterer Stand sagt deshalb nichts mehr, und solange der Auftrag läuft, zählt
kein Inventar. Zeigt ein solches jüngeres Inventar die VM ausgeschaltet oder
nicht mehr auf dem Host, zählt auch der Portalstart nicht.

Drei Stellen fragen dieselbe Regel (`virtusphere_vm_power_evidence()`): die
MECM-Registrierung (`mecm_updateid.php`), das Ende eines Auftrags mit
Startschritt und jeder übernommene Inventarabruf, dessen VM-Liste beantwortet
wurde. Ein Auftrag mit Startschritt reiht danach selbst einen Inventarabruf für
seinen Zugang ein, auch wenn der Startschritt gescheitert ist; ein nach einem
Anmeldefehler pausierter Zugang wird nicht abgefragt. Eine von Hand im
vSphere-Client gestartete VM erhält ihre Uhr deshalb erst mit dem nächsten
Inventarabruf (Zeitplan, „Alle aktualisieren“ oder Einzelabruf im Systemstatus).

Der automatische Start setzt nur eine leere Uhr. Er startet nie neu, ändert
weder `updated_at` noch Lebenszyklus oder MECM-Zustand und schreibt einen
Eintrag in die Statushistorie, der die Quelle nennt.

### Nach „MECM-ID zurücksetzen“

Ein Beleg zählt nur für die laufende Runde. Ein Start aus der Zeit vor dem
Zurücksetzen der MECM-ID beweist nicht, dass die VM danach eingeschaltet wurde.
Das Portal vergleicht deshalb den Zeitpunkt des Belegs mit dem Beginn des
Wartens auf MECM (`mecm_pending_since`) und lehnt ältere Belege ab. Diese
Grenze kann einen echten Beleg ablehnen, aber nie einen falschen annehmen:

- Wurde die VM nach dem Zurücksetzen nicht erneut über das Portal gestartet,
  bleibt nach der Registrierung der Befund „Einschaltbeleg fehlt“ stehen, bis
  ein Inventarabruf die VM eingeschaltet zeigt.
- Ist die VM schon wieder registriert, zählt nur noch ein Beleg, der in diesem
  Moment entsteht: ein Auftrag, der jetzt endet, oder ein Inventar, das jetzt
  übernommen wird.

In beiden Fällen hilft ein Inventarabruf oder „Beobachtung von Hand starten“.

## Rollout-Reihenfolge

1. Anwendungsbackup gemäß [Backup/Restore](backup.md) erstellen und Referenz im
   Rollout-Protokoll festhalten.
2. Den freigegebenen Commit bzw. das Offline-Bundle bereitstellen. Während Code
   und Schema noch nicht zusammenpassen, keine Portal-Anfragen bedienen.
3. Im PHP-Container `php /var/www/html/lib/migrate.php` ausführen. Der Lauf muss
   `0038_vm_progress_watch: applied` oder bei einer Wiederholung `skipped` und
   abschließend `migrations: ok` melden.
4. PHP-, Webserver-, Deploy- und Maintenance-Worker mit dem freigegebenen Stand
   neu erzeugen. Die Beobachtung selbst braucht keinen periodischen Worker; der
   Worker-Neustart stellt nur einen einheitlichen Release-Stand sicher.
5. Die nachstehenden SQL-Abfragen als App-Datenbankbenutzer ausführen und die
   Resultate mit Datum, Commit und Bediener ins Rollout-Protokoll übernehmen.
6. Portal-Smoke: Dashboard mit der Kachel „VM-Prüfung fällig“, Missionsliste mit
   der Spalte „Prüfung fällig“, VM-Liste und eine
   `os_installing`-VM im Editor öffnen. Die Beobachtung nur von Hand starten,
   wenn genau diese VM eingeschaltet ist und der Beleg ausbleibt.

## SQL-Verifikation

```sql
SELECT name, applied_at
FROM deploy_migrations
WHERE name = '0038_vm_progress_watch';

SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_TYPE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'deploy_vms'
  AND COLUMN_NAME IN ('mecm_pending_since', 'os_install_watch_started_at')
ORDER BY COLUMN_NAME;

SELECT INDEX_NAME,
       GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_in_order
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'deploy_vms'
  AND INDEX_NAME IN ('deploy_vms_mecm_pending_watch', 'deploy_vms_os_install_watch')
GROUP BY INDEX_NAME
ORDER BY INDEX_NAME;

SELECT
  SUM(mecm_sync_state = 'pending' AND mecm_pending_since IS NULL) AS pending_without_clock,
  SUM(lifecycle_state <> 'os_installing' AND os_install_watch_started_at IS NOT NULL) AS install_clock_outside_state
FROM deploy_vms;

SELECT
  SUM(mecm_sync_state = 'pending'
      AND mecm_pending_since < DATE_SUB(NOW(), INTERVAL 7200 SECOND)) AS overdue_mecm,
  SUM(lifecycle_state = 'os_installing'
      AND mecm_sync_state = 'registered'
      AND os_install_watch_started_at < DATE_SUB(NOW(), INTERVAL 21600 SECOND)) AS overdue_os_install
FROM deploy_vms;
```

Die Sekundenwerte der letzten Abfrage entsprechen den beiden Konstanten oben;
weichen diese ab, die Abfrage mit ihren Werten ausführen. Erwartung: genau eine Migrationszeile, genau zwei nullable Zeitspalten, genau
zwei Indizes und `pending_without_clock = 0`. `install_clock_outside_state = 0`
ist der Normalfall; ein anderer Wert blockiert den Rollout nicht automatisch,
muss aber vor Freigabe anhand der Statushistorie erklärt werden. Die beiden
Overdue-Zahlen sind Betriebsbestand und dürfen größer null sein.

## Rückbau und Grenzen

Die Migration ist additiv. Ein Anwendungsrollback kann die Spalten zunächst
liegen lassen; sie werden von älterem Code ignoriert. Spalten oder Indizes im
Störungsfall nicht ad hoc löschen. Erst nach gesichertem Backup und separatem
Rückbauplan entfernen.

Produktionsprotokoll: **nicht durchgeführt** – in dieser Arbeitsumgebung besteht
kein Zugriff auf Produktion. ESXi-, MECM- und Windows-Hardwaretests sind für
diese Funktion ebenfalls **nicht durchgeführt** und werden durch lokale
Schema-, Browser- oder Lane-Ergebnisse nicht ersetzt.
