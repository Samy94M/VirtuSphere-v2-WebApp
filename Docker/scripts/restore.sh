#!/bin/sh
# STILLGELEGT (E5, ADR-0017): dieses Skript spielte einen Dump ungeprueft in den
# LAUFENDEN Stack ein und ueberschrieb dabei .env und SSL-Material ohne
# Verifikation. Der kanonische, bewiesene Weg ist der Restore-Drill
# scripts/restore_test.sh plus das Disaster-Recovery-Runbook in
# docs/operations/backup.md. Dieses Skript schlaegt absichtlich hart fehl.
echo "FEHLER: Docker/scripts/restore.sh ist stillgelegt. Restore-Probe: sh scripts/restore_test.sh; Ernstfall: docs/operations/backup.md (Disaster Recovery)." >&2
exit 2
