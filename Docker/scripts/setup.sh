#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT_DIR"

require_command() {
    if ! command -v "$1" >/dev/null 2>&1; then
        echo "setup: missing required command: $1" >&2
        exit 2
    fi
}

random_b64() {
    openssl rand -base64 32
}

replace_env_value() {
    local key="$1"
    local value="$2"
    local file="$3"
    if grep -q "^${key}=" "$file"; then
        sed -i "s#^${key}=.*#${key}=${value}#" "$file"
    else
        printf '%s=%s\n' "$key" "$value" >> "$file"
    fi
}

require_command docker
require_command openssl

if ! docker compose version >/dev/null 2>&1; then
    echo "setup: docker compose plugin is required" >&2
    exit 2
fi

mkdir -p Docker/WebAPI/logs Docker/mysql/mysql-data

if [ ! -f .env ]; then
    cp .env.example .env
    replace_env_value APP_KEY "base64:$(random_b64)" .env
    replace_env_value DB_PASS "$(random_b64)" .env
    replace_env_value MYSQL_ROOT_PASSWORD "$(random_b64)" .env
    # The file holds the MySQL root password and APP_KEY (SEC2, C-10): readable
    # by its owner only. docker compose runs as that owner and still reads it.
    chmod 600 .env
    echo "setup: created .env with fresh local secrets (mode 600)"
else
    echo "setup: keeping existing .env"
    if [ -n "$(find .env -perm /077 2>/dev/null)" ]; then
        echo "setup: note: .env is readable by group or others; consider: chmod 600 .env" >&2
    fi
fi

docker compose config --quiet
docker compose build
# --wait: erst weitermachen, wenn jeder Healthcheck gruen ist (AP8). Ein Stack,
# der nicht gesund wird, bricht das Setup sichtbar ab, statt kaputte Container
# stehen zu lassen.
docker compose up -d --wait

docker compose exec -T php php /var/www/html/lib/migrate.php --check
docker compose exec -T php php /var/www/html/lib/migrate.php

if grep -q '^SEED_ADMIN_USER=' .env && grep -q '^SEED_ADMIN_PASSWORD=' .env; then
    docker compose exec -T php php /var/www/html/lib/seed.php
    # The seeded password is one-time (must_change_password), but it would stay
    # in .env, in the PHP container environment and in every config archive of
    # scripts/backup.sh (SEC2, C-10). Remove it and recreate the stack (all of
    # it: a recreated php alone answers 502 behind the old nginx).
    sed -i '/^SEED_ADMIN_PASSWORD=/d' .env
    docker compose up -d --force-recreate --wait
    echo "setup: first admin seeded; SEED_ADMIN_PASSWORD removed from .env (change it at the first sign-in)"
else
    echo "setup: SEED_ADMIN_USER and SEED_ADMIN_PASSWORD are not set; skipping first-admin seed"
fi

echo "setup: ok"
