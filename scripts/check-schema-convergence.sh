#!/bin/sh
# Proves that the fresh schema (Docker/mysql/mysql-init/struktur.sql) and the
# incremental migration path (lib/migrate.php) converge to the same shape, and
# that struktur.sql loads cleanly on a fresh volume.
#
# Requires a running stack: the MySQL and PHP containers from docker-compose.
# Not part of CI (CI has no MySQL); run on the dev host and before shipping.
#
#   sh scripts/check-schema-convergence.sh
#
# Env overrides: MYSQL_CONTAINER, PHP_CONTAINER, MYSQL_ROOT_PASSWORD.
set -eu

# Git Bash on Windows rewrites /var/www/... into a host path; disable that so
# container-absolute paths survive docker exec.
export MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*'

MYSQL_CONTAINER="${MYSQL_CONTAINER:-virtusphere-v2-webapp-mysql-1}"
PHP_CONTAINER="${PHP_CONTAINER:-virtusphere-v2-webapp-php-1}"
STRUKTUR="Docker/mysql/mysql-init/struktur.sql"

if [ -z "${MYSQL_ROOT_PASSWORD:-}" ]; then
  MYSQL_ROOT_PASSWORD="$(grep -E '^MYSQL_ROOT_PASSWORD=' .env | cut -d= -f2-)"
fi
[ -n "$MYSQL_ROOT_PASSWORD" ] || { echo "convergence: MYSQL_ROOT_PASSWORD not set and not in .env" >&2; exit 2; }

myql() { docker exec -i "$MYSQL_CONTAINER" mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$@" 2>/dev/null; }
mydump() { docker exec "$MYSQL_CONTAINER" mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --no-data --skip-comments --skip-dump-date "$1" 2>/dev/null | sed -E 's/ AUTO_INCREMENT=[0-9]+//'; }

A=vs_conv_struktur
B=vs_conv_migrated
dump_a="$(mktemp)"
dump_b="$(mktemp)"
cleanup() {
  myql -e "DROP DATABASE IF EXISTS $A; DROP DATABASE IF EXISTS $B;" 2>/dev/null || true
  rm -f "$dump_a" "$dump_b"
}
trap cleanup EXIT

echo "convergence: building $A from struktur.sql only"
myql -e "DROP DATABASE IF EXISTS $A; CREATE DATABASE $A CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
# struktur.sql must load standalone (this is what docker-entrypoint-initdb.d does).
if ! myql "$A" < "$STRUKTUR"; then
  echo "BLOCK: [schema-convergence.fresh-load] struktur.sql failed to load on a fresh database (a fresh install would fail to initialize)" >&2
  exit 1
fi

echo "convergence: building $B from struktur.sql + migrate.php"
myql -e "DROP DATABASE IF EXISTS $B; CREATE DATABASE $B CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
myql "$B" < "$STRUKTUR"

echo "convergence: exercising migrations 0019/0020/0021/0034 from the previous schema"
# deploy_tokens existiert im frischen Schema nicht mehr (ADR-0035); der
# Alt-Zustand wird als pre-0010-Form nachgebaut (ohne user_id, ohne FK), damit
# 0021 seine Reparatur und 0034 den gezaehlten Drop wirklich durchlaufen.
myql "$B" -e "
  CREATE TABLE deploy_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(255) NOT NULL,
    expired BOOLEAN NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  INSERT INTO deploy_tokens (token, expired) VALUES ('vs_conv_token', 0);
  ALTER TABLE deploy_jobs
    MODIFY COLUMN status ENUM('queued','running','succeeded','failed','cancelled') NOT NULL DEFAULT 'queued',
    DROP COLUMN result_json;
  INSERT INTO deploy_missions (mission_name, mission_status, wds_vlan) VALUES
    ('vs_conv_materialize', 'Aktiv', 'VLAN-MIGRATE'),
    ('vs_conv_empty', 'Aktiv', ''),
    ('_vs_conv_template', 'Aktiv', 'VLAN-TEMPLATE');
  INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname)
    SELECT id, 'vm-materialize', 'vm-materialize' FROM deploy_missions WHERE mission_name = 'vs_conv_materialize';
  INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname)
    SELECT id, 'vm-existing', 'vm-existing' FROM deploy_missions WHERE mission_name = 'vs_conv_materialize';
  INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname)
    SELECT id, 'vm-empty', 'vm-empty' FROM deploy_missions WHERE mission_name = 'vs_conv_empty';
  INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname)
    SELECT id, 'vm-template', 'vm-template' FROM deploy_missions WHERE mission_name = '_vs_conv_template';
  INSERT INTO deploy_interfaces (vm_id, ip, subnet, gateway, dns1, dns2, vlan, mac, mode, type)
    SELECT id, '', '', '', '', '', 'KEEP', '', 'dhcp', 'e1000e'
    FROM deploy_vms WHERE vm_name = 'vm-existing';
"

migration_output="$(docker exec -e DB_NAME="$B" -e DB_USER=root -e DB_PASS="$MYSQL_ROOT_PASSWORD" "$PHP_CONTAINER" \
  php /var/www/html/lib/migrate.php)"
printf '%s\n' "$migration_output" | grep -Fq '0020: skipped VM "vm-empty" in mission "vs_conv_empty" because wds_vlan is empty' \
  || { echo "BLOCK: [schema-convergence.migration-report] migration 0020 did not name the empty-WDS VM in its report" >&2; exit 1; }
# 0021 muss die Alt-Form wirklich repariert haben (Report statt Schema-Assert:
# 0034 raeumt die Tabelle danach weg), und 0034 muss die Zerstoerung beziffern.
printf '%s\n' "$migration_output" | grep -Fq '0021: added fk_deploy_tokens_user' \
  || { echo "BLOCK: [schema-convergence.migration-report] migration 0021 did not repair the pre-0010 token shape" >&2; exit 1; }
printf '%s\n' "$migration_output" | grep -Fq '0034: dropped deploy_tokens (1 row(s), 1 non-expired)' \
  || { echo "BLOCK: [schema-convergence.migration-report] migration 0034 did not count what it destroyed" >&2; exit 1; }

assert_sql() {
  actual="$(myql -N -B "$B" -e "$1")"
  [ "$actual" = "$2" ] || {
    echo "BLOCK: [schema-convergence.assert] $3 (expected '$2', got '$actual')" >&2
    exit 1
  }
}

assert_sql "SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '$B' AND TABLE_NAME = 'deploy_jobs' AND COLUMN_NAME = 'result_json'" "json" "migration 0019 did not create deploy_jobs.result_json as JSON"
assert_sql "SELECT COUNT(*) FROM deploy_interfaces i INNER JOIN deploy_vms v ON v.id = i.vm_id WHERE v.vm_name = 'vm-materialize' AND i.ip = '' AND i.subnet = '' AND i.gateway = '' AND i.dns1 = '' AND i.dns2 = '' AND i.vlan = 'VLAN-MIGRATE' AND i.mac = '' AND i.mode = 'dhcp' AND i.type = 'vmxnet3'" "1" "migration 0020 did not materialize the YAML-equivalent default interface"
assert_sql "SELECT COUNT(*) FROM deploy_interfaces i INNER JOIN deploy_vms v ON v.id = i.vm_id WHERE v.vm_name = 'vm-empty'" "0" "migration 0020 guessed an interface for an empty WDS VLAN"
assert_sql "SELECT COUNT(*) FROM deploy_interfaces i INNER JOIN deploy_vms v ON v.id = i.vm_id WHERE v.vm_name = 'vm-template'" "0" "migration 0020 materialized an interface for a non-deployable template"
assert_sql "SELECT COUNT(*) FROM deploy_interfaces i INNER JOIN deploy_vms v ON v.id = i.vm_id WHERE v.vm_name = 'vm-existing' AND i.vlan = 'KEEP' AND i.type = 'e1000e'" "1" "migration 0020 changed an existing interface"
assert_sql "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$B' AND table_name = 'deploy_tokens'" "0" "migration 0034 did not drop deploy_tokens (ADR-0035)"

# Re-run the data migration itself, not just the tracking guard, to prove that
# its NOT EXISTS write cannot duplicate a previously materialized interface.
myql "$B" -e "DELETE FROM deploy_migrations WHERE name = '0020_materialize_default_interfaces';"
docker exec -e DB_NAME="$B" -e DB_USER=root -e DB_PASS="$MYSQL_ROOT_PASSWORD" "$PHP_CONTAINER" \
  php /var/www/html/lib/migrate.php >/dev/null
assert_sql "SELECT COUNT(*) FROM deploy_interfaces i INNER JOIN deploy_vms v ON v.id = i.vm_id WHERE v.vm_name = 'vm-materialize'" "1" "migration 0020 is not idempotent"

# migrations must be a no-op on a fresh struktur schema.
docker exec -e DB_NAME="$B" -e DB_USER=root -e DB_PASS="$MYSQL_ROOT_PASSWORD" "$PHP_CONTAINER" \
  php /var/www/html/lib/migrate.php --check | grep -q 'pending=0' \
  || { echo "BLOCK: [schema-convergence.pending] migrations still pending after applying against a fresh struktur schema" >&2; exit 1; }

# Tempdateien statt Prozesssubstitution: dieses Skript laeuft unter #!/bin/sh
# (dash/BusyBox kennen kein <(...)).
mydump "$A" > "$dump_a"
mydump "$B" > "$dump_b"
if diff -u "$dump_a" "$dump_b"; then
  echo "convergence: OK - struktur.sql and migrate.php produce an identical schema"
else
  echo "BLOCK: [schema-convergence.diverged] struktur.sql and migrate.php diverge (see diff above)" >&2
  exit 1
fi
