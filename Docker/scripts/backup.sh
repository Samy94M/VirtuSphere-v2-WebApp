#!/bin/sh
# STILLGELEGT (E5, ADR-0017): dieses Skript war ein zweiter, abweichender
# Backup-Pfad ohne Dump-Validierung, Retention und Statuskanal. Kanonisch ist
# ausschliesslich scripts/backup.sh (Restore-Probe: scripts/restore_test.sh);
# siehe docs/operations/backup.md. Dieses Skript schlaegt absichtlich hart
# fehl, damit kein Cronjob und keine Anleitung es weiter benutzt.
echo "FEHLER: Docker/scripts/backup.sh ist stillgelegt. Kanonischer Weg: sh scripts/backup.sh (siehe docs/operations/backup.md)." >&2
exit 2
