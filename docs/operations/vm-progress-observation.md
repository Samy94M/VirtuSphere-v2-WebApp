# Rollout und Betrieb: VM-Fortschrittsbeobachtung

Dieses Runbook gilt für Migration `0038_vm_progress_watch` und ADR-0038. Die im
Repository bereits vorhandene Migration `0034_drop_legacy_token_schema` ist ein
anderer, abgeschlossener Rückbau und darf nicht mit dieser Änderung verwechselt
werden.

## Wirkung

- MECM-pending wird nach mehr als zwei Stunden gelb markiert.
- Eine OS-Installation erhält erst nach der bestätigten Aktion „PXE jetzt
  beobachten“ eine Uhr und wird nach mehr als sechs Stunden gelb markiert.
- Keine Warnung setzt eine VM fehl, löscht sie oder startet eine externe Aktion.
- Bestehende MECM-pending-Zeilen beginnen beim Migrationslauf frisch. Bestehende
  OS-Installationen bleiben ohne Uhr, bis PXE tatsächlich ausgelöst wurde.

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
6. Portal-Smoke: Dashboard, gefilterte Missionsliste, VM-Liste und eine
   `os_installing`-VM im Editor öffnen. Eine echte PXE-Beobachtung nur starten,
   wenn PXE für genau diese VM ausgelöst wurde.

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
  SUM(lifecycle_state = 'deployed'
      AND mecm_sync_state = 'pending'
      AND mecm_pending_since < DATE_SUB(NOW(), INTERVAL 7200 SECOND)) AS overdue_mecm,
  SUM(lifecycle_state = 'os_installing'
      AND mecm_sync_state = 'registered'
      AND os_install_watch_started_at < DATE_SUB(NOW(), INTERVAL 21600 SECOND)) AS overdue_os_install
FROM deploy_vms;
```

Erwartung: genau eine Migrationszeile, genau zwei nullable Zeitspalten, genau
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
