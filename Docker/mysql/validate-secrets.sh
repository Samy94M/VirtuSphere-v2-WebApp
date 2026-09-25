#!/bin/sh
set -eu

assert_strong_secret() {
    name=$1
    value=$(printenv "$name" 2>/dev/null || true)
    if [ "${#value}" -lt 16 ]; then
        echo "mysql bootstrap: $name is missing or too weak" >&2
        exit 1
    fi
    if printf '%s' "$value" | grep -Eiq '^(change-me|password|secret|root|admin)'; then
        echo "mysql bootstrap: $name is missing or too weak" >&2
        exit 1
    fi
}

assert_strong_secret MYSQL_ROOT_PASSWORD
assert_strong_secret MYSQL_PASSWORD

exec /usr/local/bin/docker-entrypoint.sh "$@"
