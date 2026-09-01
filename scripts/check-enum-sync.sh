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
#   deploy_users/deploy_login_attempts.auth_source
#                               <-> VIRTUSPHERE_AUTH_SOURCE_*       (lib/directory_constants.php)
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
  # Match the complete SQL identifier. A substring search for `kind ENUM`
  # also matched `esxi_cert_kind ENUM` and compared the certificate vocabulary
  # against the inventory kinds. The non-identifier boundary keeps legitimate
  # indentation/backticks while rejecting suffix matches.
  grep -E "(^|[^[:alnum:]_])$2([^[:alnum:]_]|$).*ENUM[(]" "$1" | head -n 1 \
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
check_pair "Auth-Quellen"      "$LIB/directory_constants.php" "VIRTUSPHERE_AUTH_SOURCE_"    "auth_source"
check_pair "Deploy-Job-Status" "$LIB/deploy_constants.php" "VIRTUSPHERE_DEPLOY_STATUS_"   "status"
check_pair "Credential-Typen"  "$LIB/credentials.php"      "VIRTUSPHERE_CREDENTIAL_TYPE_" "type"
check_pair "Inventory-Kinds"   "$LIB/deploy_constants.php" "VIRTUSPHERE_INVENTORY_KIND_"  "kind"
check_pair "Autostart-Stop"    "$LIB/deploy_constants.php" "VIRTUSPHERE_AUTOSTART_STOP_ACTION_" "autostart_stop_action"
check_pair "Joblog-Quellen"    "$LIB/deploy_constants.php" "VIRTUSPHERE_DEPLOY_LOG_"      "stream"

# Tabellenweite Variante. Notwendig geworden, weil `status` als Spaltenname
# mehrfach vorkommt: check_pair nimmt die ERSTE ENUM-Definition der Datei und
# verglich damit die Create-Ergebnisse gegen die Jobstatus. Ausserdem liegen
# neue Migrationen nicht mehr in migrate.php, sondern unter lib/migrations/;
# ein Spiegel, den kein Wächter sieht, ist genau die still gruene Luecke, die
# dieses Skript verhindern soll.
table_enum_values() { # $1=file $2=table $3=column
  awk -v table="$table_marker" -v column="$3" '
    index($0, table) { inside = 1 }
    inside && $0 ~ ("(^|[^[:alnum:]_])" column "([^[:alnum:]_]|$).*ENUM[(]") && !found {
      match($0, /ENUM\([^)]*\)/)
      value = substr($0, RSTART + 5, RLENGTH - 6)
      gsub(/['"'"' ]/, "", value)
      print value
      found = 1
    }
    inside && /^\) ENGINE=|ENGINE=InnoDB/ { inside = 0 }
  ' "$1"
}

check_table_pair() { # $1=label $2=php-file $3=const-prefix $4=table $5=column
  expected=$(php_values "$2" "$3")
  if [ -z "$expected" ]; then
    echo "FEHLER: [enum-sync.no-consts] keine Consts mit Praefix $3 in $2 gefunden." >&2
    errors=$((errors + 1))
    return 0
  fi
  # Zwei Quellen sind Pflicht: das Frischschema UND mindestens eine Migration.
  # Nur eine von beiden hiesse, dass eine frische und eine migrierte Datenbank
  # auseinanderlaufen duerfen, ohne dass es jemand merkt; genau das ist die
  # Konvergenzregel aus .claude/rules/database.md.
  pair_errors=0
  migration_seen=0
  table_marker="$4"
  sql_values=$(table_enum_values "$SQL" "$4" "$5")
  if [ -n "$sql_values" ] && [ "$sql_values" != "$expected" ]; then
    echo "FEHLER: [enum-sync.drift] $1 — ENUM-Drift in $SQL fuer $4.$5:" >&2
    echo "  PHP-SSoT: $expected" >&2
    echo "  DB-ENUM:  $sql_values" >&2
    pair_errors=$((pair_errors + 1))
  fi
  for src in $(ls Docker/WebAPI/lib/migrations/*.php 2>/dev/null) "$MIG"; do
    [ -f "$src" ] || continue
    actual=$(table_enum_values "$src" "$4" "$5")
    [ -z "$actual" ] && continue
    migration_seen=1
    if [ "$actual" != "$expected" ]; then
      echo "FEHLER: [enum-sync.drift] $1 — ENUM-Drift in $src fuer $4.$5:" >&2
      echo "  PHP-SSoT: $expected" >&2
      echo "  DB-ENUM:  $actual" >&2
      pair_errors=$((pair_errors + 1))
    fi
  done
  if [ -z "$sql_values" ] || [ "$migration_seen" -eq 0 ]; then
    echo "FEHLER: [enum-sync.no-enum] $1 — $4.$5 fehlt im Frischschema oder in jeder Migration." >&2
    pair_errors=$((pair_errors + 1))
  fi
  errors=$((errors + pair_errors))
  # OK nur, wenn dieses Paar wirklich sauber war. Eine OK-Zeile direkt unter
  # einer FEHLER-Zeile wird ueberlesen.
  if [ "$pair_errors" -eq 0 ] && [ "$quiet" -ne 1 ]; then
    echo "OK: $1 ($expected)"
  fi
}

check_table_pair "Create-Aktionen" "$LIB/deploy_create_constants.php" "VIRTUSPHERE_CREATE_ACTION_" "deploy_create_vm_results" "action"
check_table_pair "Create-Status"   "$LIB/deploy_create_constants.php" "VIRTUSPHERE_CREATE_RESULT_STATUS_" "deploy_create_vm_results" "status"
check_table_pair "Create-Outcomes" "$LIB/deploy_create_constants.php" "VIRTUSPHERE_CREATE_OUTCOME_" "deploy_create_vm_results" "outcome"

if [ "$errors" -gt 0 ]; then
  echo "check-enum-sync: $errors Drift-Fehler." >&2
  exit 1
fi
[ "$quiet" -eq 1 ] || echo "check-enum-sync: alle ENUM-Spiegel synchron."
