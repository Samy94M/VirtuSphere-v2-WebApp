#!/bin/sh
# scripts/check-enum-sync.sh — DB-ENUM <-> PHP-Const SSoT-Check.
#
# Canonical SSoT: die PHP-Const-Definitionen in Docker/WebAPI/lib/. Die
# ENUM-Spalten in struktur.sql und lib/migrate.php sind Spiegel und muessen
# exakt matchen, inklusive Reihenfolge (.claude/rules/database.md: frisches
# Schema und Live-Migrationen muessen zur selben Form konvergieren).
#
# Geprueft (Wertesatz + Reihenfolge):
#   deploy_vms.lifecycle_state   <-> VIRTUSPHERE_LIFECYCLE_*        (lib/constants.php)
#   deploy_vms.mecm_sync_state   <-> VIRTUSPHERE_MECM_SYNC_*        (lib/constants.php)
#   deploy_users.role            <-> VIRTUSPHERE_ROLE_*             (lib/permissions.php)
#   deploy_jobs.status           <-> VIRTUSPHERE_DEPLOY_STATUS_*    (lib/deploy_constants.php)
#   deploy_credentials.type      <-> VIRTUSPHERE_CREDENTIAL_TYPE_*  (lib/credentials.php)
#   deploy_esxi_inventory.kind   <-> VIRTUSPHERE_INVENTORY_KIND_*   (lib/deploy_constants.php)
#   deploy_missions.autostart_stop_action
#                               <-> VIRTUSPHERE_AUTOSTART_STOP_ACTION_* (lib/deploy_constants.php)
#
# Aufrufer:
#   - .claude/hooks/session-start.sh  (Modus --quiet)
#   - scripts/check.ps1 (Fast-Lane) und scripts/test-guards.ps1 (Fixtures)
#   - manuell vor Commit              (kein Argument)
#
# VIRTUSPHERE_CHECK_ROOT uebersteuert das Repo-Root (Guard-Fixtures); die
# [enum-sync.*]-IDs in Fehlerzeilen sind der stabile Diagnose-Vertrag.
set -eu
cd "${VIRTUSPHERE_CHECK_ROOT:-$(dirname "$0")/..}"

quiet=0
case "${1:-}" in
  --quiet|-q) quiet=1 ;;
  --ci|'') ;;
  --help|-h) echo "Usage: scripts/check-enum-sync.sh [--quiet|--ci]"; exit 0 ;;
  *) echo "Unknown argument: $1" >&2; exit 2 ;;
esac

LIB=Docker/WebAPI/lib
SQL=Docker/mysql/mysql-init/struktur.sql
MIG=Docker/WebAPI/lib/migrate.php
errors=0

# Literale der Const-Definitionen mit gegebenem Praefix, in Dateireihenfolge.
php_values() { # $1=file $2=const-prefix
  grep -E "^const $2[A-Z_]+ = '" "$1" | sed "s/.*= '\([^']*\)';.*/\1/" | paste -sd, -
}

# Erste ENUM(...)-Definition der Spalte in der Datei, als Komma-Liste.
enum_values() { # $1=file $2=column
  grep -F "$2" "$1" | grep "ENUM(" | head -n 1 \
    | sed -n "s/.*ENUM(\([^)]*\)).*/\1/p" | tr -d "' "
}

check_pair() { # $1=label $2=php-file $3=const-prefix $4=column
  expected=$(php_values "$2" "$3")
  if [ -z "$expected" ]; then
    # Zero-Match darf nie leer gruen werden: keine Consts gefunden ist ein Fehler.
    echo "FEHLER: [enum-sync.no-consts] keine Consts mit Praefix $3 in $2 gefunden." >&2
    errors=$((errors + 1))
    return 0
  fi
  for src in "$SQL" "$MIG"; do
    actual=$(enum_values "$src" "$4")
    if [ -z "$actual" ]; then
      echo "FEHLER: [enum-sync.no-enum] $1 — Spalte $4 hat keine ENUM-Definition in $src." >&2
      errors=$((errors + 1))
    elif [ "$actual" != "$expected" ]; then
      echo "FEHLER: [enum-sync.drift] $1 — ENUM-Drift in $src fuer $4:" >&2
      echo "  PHP-SSoT: $expected" >&2
      echo "  DB-ENUM:  $actual" >&2
      errors=$((errors + 1))
    fi
  done
  [ "$quiet" -eq 1 ] || echo "OK: $1 ($expected)"
}

check_pair "Lifecycle-States"  "$LIB/constants.php"        "VIRTUSPHERE_LIFECYCLE_"       "lifecycle_state"
check_pair "MECM-Sync-States"  "$LIB/constants.php"        "VIRTUSPHERE_MECM_SYNC_"       "mecm_sync_state"
check_pair "User-Rollen"       "$LIB/permissions.php"      "VIRTUSPHERE_ROLE_"            "role"
check_pair "Deploy-Job-Status" "$LIB/deploy_constants.php" "VIRTUSPHERE_DEPLOY_STATUS_"   "status"
check_pair "Credential-Typen"  "$LIB/credentials.php"      "VIRTUSPHERE_CREDENTIAL_TYPE_" "type ENUM"
check_pair "Inventory-Kinds"   "$LIB/deploy_constants.php" "VIRTUSPHERE_INVENTORY_KIND_"  "kind ENUM"
check_pair "Autostart-Stop"    "$LIB/deploy_constants.php" "VIRTUSPHERE_AUTOSTART_STOP_ACTION_" "autostart_stop_action"

if [ "$errors" -gt 0 ]; then
  echo "check-enum-sync: $errors Drift-Fehler." >&2
  exit 1
fi
[ "$quiet" -eq 1 ] || echo "check-enum-sync: alle ENUM-Spiegel synchron."
